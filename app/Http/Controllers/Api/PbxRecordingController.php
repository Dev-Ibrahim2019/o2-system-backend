<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class PbxRecordingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $startedAt = microtime(true);
        $config = $this->getConfig();

        $result = [
            'success' => false,
            'count' => 0,
            'total_count' => 0,
            'recordings' => [],
            'stats' => [
                'today' => 0,
                'total_duration' => 0,
                'total_size' => 0,
            ],
            'connection' => [
                'status' => 'disconnected',
                'method' => null,
                'response_time_ms' => 0,
            ],
            'error' => null,
        ];

        try {
            $accessToken = $this->authenticate($config);
            if (!$accessToken) {
                $result['error'] = ['type' => 'authentication_failed', 'message' => 'Failed to authenticate with FreePBX.'];
                return response()->json($result, 200);
            }

            // Build CDR query with optional filters
            $first = min((int) $request->input('per_page', 100), 500);
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            $args = "first: {$first}";
            if ($startDate) $args .= ', startDate: "' . addslashes($startDate) . '"';
            if ($endDate) $args .= ', endDate: "' . addslashes($endDate) . '"';

            $query = '{ fetchAllCdrs(' . $args . ') { totalCount cdrs { id uniqueid calldate clid src dst dcontext channel dstchannel duration billsec disposition recordingfile accountcode userfield did cnum outbound_cnum outbound_cnam dst_cnam status } } }';

            $graphqlResponse = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(30)
                ->asJson()
                ->post($config['graphql_url'], ['query' => $query]);

            if (!$graphqlResponse->successful()) {
                $result['error'] = ['type' => 'api_unavailable', 'message' => 'GraphQL query failed (HTTP ' . $graphqlResponse->status() . ').'];
                return response()->json($result, 200);
            }

            $graphData = $graphqlResponse->json('data.fetchAllCdrs', []);
            $cdrs = $graphData['cdrs'] ?? [];
            $totalCount = $graphData['totalCount'] ?? 0;

            // Filter only CDRs that have recording files
            $recordings = [];
            $totalDuration = 0;
            $todayCount = 0;
            $today = now()->toDateString();

            foreach ($cdrs as $cdr) {
                $recordingFile = $cdr['recordingfile'] ?? null;
                if (empty($recordingFile)) continue;

                $calldate = $cdr['calldate'] ?? $cdr['timestamp'] ?? '';
                $duration = (int) ($cdr['duration'] ?? 0);
                $billsec = (int) ($cdr['billsec'] ?? 0);

                $recordings[] = [
                    'id' => $cdr['id'] ?? $cdr['uniqueid'] ?? '',
                    'unique_id' => $cdr['uniqueid'] ?? '',
                    'caller' => $cdr['src'] ?? $cdr['cnum'] ?? $cdr['outbound_cnum'] ?? '',
                    'callee' => $cdr['dst'] ?? '',
                    'caller_name' => $cdr['clid'] ?? $cdr['outbound_cnam'] ?? '',
                    'callee_name' => $cdr['dst_cnam'] ?? '',
                    'direction' => $this->guessDirection($cdr),
                    'duration' => $duration,
                    'billsec' => $billsec,
                    'date' => $calldate,
                    'file_name' => $recordingFile,
                    'file_size' => null,
                    'disposition' => $cdr['disposition'] ?? 'UNKNOWN',
                    'channel' => $cdr['channel'] ?? null,
                    'dst_channel' => $cdr['dstchannel'] ?? null,
                    'context' => $cdr['dcontext'] ?? null,
                    'account_code' => $cdr['accountcode'] ?? null,
                    'did' => $cdr['did'] ?? null,
                    'play_url' => '/api/pbx/recordings/' . urlencode($recordingFile) . '/play',
                    'download_url' => '/api/pbx/recordings/' . urlencode($recordingFile) . '/download',
                ];

                $totalDuration += $billsec ?: $duration;
                if (str_starts_with($calldate, $today)) {
                    $todayCount++;
                }
            }

            $result['success'] = true;
            $result['count'] = count($recordings);
            $result['total_count'] = $totalCount;
            $result['recordings'] = $recordings;
            $result['stats'] = [
                'today' => $todayCount,
                'total_duration' => $totalDuration,
                'total_size' => null,
            ];
            $result['connection'] = [
                'status' => 'connected',
                'method' => 'graphql',
                'response_time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        } catch (Throwable $e) {
            Log::error('[PBX] Recordings fetch error', ['message' => $e->getMessage()]);
            $result['error'] = ['type' => 'exception', 'message' => $e->getMessage()];
            $result['connection'] = [
                'status' => 'error',
                'response_time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }

        return response()->json($result, 200);
    }

    public function stats(Request $request): JsonResponse
    {
        $config = $this->getConfig();
        try {
            $accessToken = $this->authenticate($config);
            if (!$accessToken) {
                return response()->json(['success' => false, 'error' => 'Authentication failed']);
            }

            $today = now()->toDateString();
            $query = '{ fetchAllCdrs(first: 500, startDate: "' . $today . '") { totalCount cdrs { duration billsec recordingfile } } }';
            $res = Http::withToken($accessToken)->acceptJson()->timeout(15)->asJson()->post($config['graphql_url'], ['query' => $query]);

            if ($res->successful()) {
                $cdrs = $res->json('data.fetchAllCdrs.cdrs', []);
                $withRecording = array_filter($cdrs, fn($c) => !empty($c['recordingfile']));
                $totalDuration = array_sum(array_map(fn($c) => (int) ($c['billsec'] ?? $c['duration'] ?? 0), $withRecording));

                return response()->json([
                    'success' => true,
                    'today_count' => count($withRecording),
                    'today_duration' => $totalDuration,
                ]);
            }

            return response()->json(['success' => false, 'error' => 'Query failed']);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    private function isAudioContent($response): bool
    {
        $contentType = $response->header('Content-Type') ?? '';
        if (str_contains($contentType, 'text/html')) return false;
        $body = $response->body();
        if (str_starts_with(trim($body), '<!DOCTYPE') || str_starts_with(trim($body), '<html')) return false;
        return true;
    }

    public function play(Request $request): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
    {
        $fileName = $request->query('file');

        $config = $this->getConfig();
        Log::info('[PBX] Recording play request', [
            'file' => $fileName,
            'user_id' => $request->user()?->id,
        ]);

        try {
            $accessToken = $this->authenticate($config);
            if (!$accessToken) {
                return response('Authentication failed', 401);
            }

            $restUrl = rtrim($config['rest_url'], '/');
            $baseUrl = rtrim($config['base_url'], '/');

            // Strategy 1: POST /cdr/recording with form body
            $response = Http::withoutVerifying()->withToken($accessToken)
                ->timeout(30)
                ->asForm()
                ->post($restUrl . '/cdr/recording', ['recording' => $fileName]);

            if ($response->successful() && $response->body() && $this->isAudioContent($response)) {
                return $this->serveAudio($response, $fileName);
            }

            // Strategy 2: GET /cdr/recording/{file}
            $response2 = Http::withoutVerifying()->withToken($accessToken)
                ->timeout(30)
                ->get($restUrl . '/cdr/recording/' . rawurlencode($fileName));

            if ($response2->successful() && $response2->body() && $this->isAudioContent($response2)) {
                return $this->serveAudio($response2, $fileName);
            }

            // Strategy 3: POST /cdr/recording/get with JSON body
            $response3 = Http::withoutVerifying()->withToken($accessToken)
                ->timeout(30)
                ->asJson()
                ->post($restUrl . '/cdr/recording/get', ['recording' => $fileName]);

            if ($response3->successful() && $response3->body() && $this->isAudioContent($response3)) {
                return $this->serveAudio($response3, $fileName);
            }

            // Strategy 4: Direct file access via FreePBX REST (recordings endpoint)
            $response4 = Http::withoutVerifying()->withToken($accessToken)
                ->timeout(30)
                ->get($restUrl . '/recordings/file/' . rawurlencode($fileName));

            if ($response4->successful() && $response4->body() && $this->isAudioContent($response4)) {
                return $this->serveAudio($response4, $fileName);
            }

            // Strategy 5: Try system recordings API
            $response5 = Http::withoutVerifying()->withToken($accessToken)
                ->timeout(30)
                ->get($restUrl . '/system/recording/' . rawurlencode($fileName));

            if ($response5->successful() && $response5->body() && $this->isAudioContent($response5)) {
                return $this->serveAudio($response5, $fileName);
            }

            Log::warning('[PBX] All recording strategies failed', [
                'file' => $fileName,
                'statuses' => [
                    $response->status(),
                    $response2->status(),
                    $response3->status(),
                    $response4->status(),
                    $response5->status(),
                ],
            ]);

            return response('Recording not found or unavailable on the server', 404);
        } catch (Throwable $e) {
            Log::error('[PBX] Recording play error', ['file' => $fileName, 'error' => $e->getMessage()]);
            return response('Error loading recording: ' . $e->getMessage(), 500);
        }
    }

    private function serveAudio($response, string $fileName): Response
    {
        $content = $response->body();
        $contentType = $response->header('Content-Type') ?? 'audio/wav';

        // Validate the response is actually audio, not HTML
        if (str_contains($contentType, 'text/html') || str_starts_with(trim($content), '<!DOCTYPE') || str_starts_with(trim($content), '<html')) {
            return response()->json([
                'success' => false,
                'error' => 'FreePBX REST API returned HTML instead of audio data. The CDR recording endpoint may not be enabled.',
                'file' => $fileName,
            ], 502);
        }

        // Detect actual mime type from content
        if (str_starts_with(substr($content, 0, 4), 'RIFF')) {
            $contentType = 'audio/wav';
        } elseif (str_starts_with(substr($content, 0, 3), 'ID3') || str_contains($contentType, 'mpeg')) {
            $contentType = 'audio/mpeg';
        }

        return response($content, 200, [
            'Content-Type' => (string) $contentType,
            'Content-Disposition' => 'inline; filename="' . basename($fileName) . '"',
            'Content-Length' => strlen($content),
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function download(Request $request): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
    {
        $fileName = $request->query('file');

        Log::info('[PBX] Recording download request', [
            'file' => $fileName,
            'user_id' => $request->user()?->id,
        ]);

        $config = $this->getConfig();
        try {
            $accessToken = $this->authenticate($config);
            if (!$accessToken) {
                return response('Authentication failed', 401);
            }

            $restUrl = rtrim($config['rest_url'], '/');

            // Try multiple strategies
            $response = Http::withoutVerifying()->withToken($accessToken)->timeout(30)->asForm()
                ->post($restUrl . '/cdr/recording', ['recording' => $fileName]);

            if (!$response->successful() || !$response->body() || !$this->isAudioContent($response)) {
                $response = Http::withoutVerifying()->withToken($accessToken)->timeout(30)
                    ->get($restUrl . '/cdr/recording/' . rawurlencode($fileName));
            }

            if (!$response->successful() || !$response->body() || !$this->isAudioContent($response)) {
                $response = Http::withoutVerifying()->withToken($accessToken)->timeout(30)->asJson()
                    ->post($restUrl . '/cdr/recording/get', ['recording' => $fileName]);
            }

            if ($response->successful() && $response->body() && $this->isAudioContent($response)) {
                $content = $response->body();
                return response($content, 200, [
                    'Content-Type' => 'audio/wav',
                    'Content-Disposition' => 'attachment; filename="' . basename($fileName) . '"',
                    'Content-Length' => strlen($content),
                ]);
            }

            return response()->json(['success' => false, 'error' => 'Recording not found or FreePBX REST API CDR endpoint is unavailable'], 404);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => 'Download error: ' . $e->getMessage()], 500);
        }
    }

    private function getConfig(): array
    {
        $baseUrl = rtrim((string) config('freepbx.base_url', env('FREEPBX_BASE_URL', '')), '/');
        return [
            'base_url' => $baseUrl,
            'auth_url' => env('FREEPBX_TOKEN_URL', $baseUrl . '/admin/api/api/token'),
            'graphql_url' => config('freepbx.graphql_url', $baseUrl . '/admin/api/api/gql'),
            'rest_url' => config('freepbx.rest_url', $baseUrl . '/admin/api/api/rest'),
            'client_id' => config('freepbx.client_id', env('FREEPBX_CLIENT_ID', '')),
            'client_secret' => config('freepbx.client_secret', env('FREEPBX_CLIENT_SECRET', '')),
            'scope' => config('freepbx.scope', env('FREEPBX_SCOPE', '')),
            'grant_type' => config('freepbx.grant_type', env('FREEPBX_GRANT_TYPE', 'client_credentials')),
        ];
    }

    private function authenticate(array $config): ?string
    {
        if (empty($config['client_id']) || empty($config['client_secret'])) return null;

        $res = Http::withoutVerifying()->asForm()->timeout(10)->post($config['auth_url'], [
            'grant_type' => $config['grant_type'],
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'scope' => $config['scope'],
        ]);

        return $res->successful() ? $res->json('access_token') : null;
    }

    private function guessDirection(array $cdr): string
    {
        $src = $cdr['src'] ?? '';
        $dst = $cdr['dst'] ?? '';
        $context = $cdr['dcontext'] ?? '';

        if (str_contains($context, 'from-trunk') || str_contains($context, 'from-pstn')) return 'Inbound';
        if (str_contains($context, 'outbound') || str_contains($context, 'from-internal')) {
            return strlen($src) <= 4 ? 'Outbound' : 'Internal';
        }
        if (strlen($src) <= 4 && strlen($dst) > 4) return 'Outbound';
        if (strlen($src) > 4 && strlen($dst) <= 4) return 'Inbound';
        return 'Internal';
    }
}
