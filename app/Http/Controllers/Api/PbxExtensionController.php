<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class PbxExtensionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $startedAt = microtime(true);
        $baseUrl = rtrim((string) config('freepbx.base_url', env('FREEPBX_BASE_URL', '')), '/');
        $authUrl = (string) env('FREEPBX_TOKEN_URL', $baseUrl . '/admin/api/api/token');
        $graphqlUrl = (string) config('freepbx.graphql_url', $baseUrl . '/admin/api/api/gql');
        $restUrl = (string) config('freepbx.rest_url', $baseUrl . '/admin/api/api/rest');
        $clientId = (string) config('freepbx.client_id', env('FREEPBX_CLIENT_ID', ''));
        $clientSecret = (string) config('freepbx.client_secret', env('FREEPBX_CLIENT_SECRET', ''));
        $scope = (string) config('freepbx.scope', env('FREEPBX_SCOPE', ''));
        $grantType = (string) config('freepbx.grant_type', env('FREEPBX_GRANT_TYPE', 'client_credentials'));

        $result = [
            'success' => false,
            'server' => 'FreePBX',
            'server_url' => $baseUrl,
            'connection' => [
                'status' => 'disconnected',
                'message' => 'Connection has not been attempted.',
                'response_time_ms' => 0,
            ],
            'authentication' => [
                'status' => 'not_attempted',
                'message' => 'Authentication has not been attempted.',
            ],
            'extensions_count' => 0,
            'extensions' => [],
            'last_sync' => null,
            'error' => null,
            'requested_at' => now()->toIso8601String(),
        ];

        try {
            if (empty($clientId) || empty($clientSecret)) {
                throw new \RuntimeException('FreePBX credentials are not configured. Set FREEPBX_CLIENT_ID and FREEPBX_CLIENT_SECRET in .env');
            }

            // ── Step 1: OAuth2 Token ──
            $tokenResponse = Http::withoutVerifying()
                ->asForm()
                ->timeout(10)
                ->post($authUrl, [
                    'grant_type' => $grantType,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'scope' => $scope,
                ]);

            if (!$tokenResponse->successful()) {
                $errorBody = $tokenResponse->json() ?? $tokenResponse->body();
                $result['authentication'] = [
                    'status' => 'failed',
                    'message' => 'OAuth token request failed (HTTP ' . $tokenResponse->status() . ').',
                    'http_status' => $tokenResponse->status(),
                    'body' => is_array($errorBody) ? $errorBody : null,
                ];
                $result['error'] = $this->buildError('authentication_failed', $tokenResponse->status(), $errorBody);
                return response()->json($result, 200);
            }

            $accessToken = $tokenResponse->json('access_token');
            $result['authentication'] = [
                'status' => 'success',
                'message' => 'OAuth2 token obtained successfully.',
                'token_type' => $tokenResponse->json('token_type'),
            ];

            // ── Step 2: Fetch Extensions via GraphQL ──
            $extensionsQuery = '{
                fetchAllExtensions(first: 500) {
                    totalCount
                    status
                    message
                    extension {
                        id
                        extensionId
                        tech
                        user {
                            id
                            extension
                            name
                            outboundCid
                            sipname
                            voicemail
                            callwaiting
                            donotdisturb
                            callforward_all
                        }
                        coreDevice {
                            id
                            deviceId
                            tech
                            dial
                            devicetype
                            description
                            emergencyCid
                        }
                    }
                }
            }';

            $graphqlResponse = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(15)
                ->asJson()
                ->post($graphqlUrl, ['query' => $extensionsQuery]);

            if ($graphqlResponse->successful()) {
                $graphData = $graphqlResponse->json('data', []);
                $connection = $graphData['fetchAllExtensions'] ?? [];
                $rawExtensions = $connection['extension'] ?? [];

                $extensions = array_map(function ($ext) {
                    $user = $ext['user'] ?? [];
                    $device = $ext['coreDevice'] ?? [];

                    return [
                        'extension' => $ext['extensionId'] ?? '',
                        'name' => $user['name'] ?? $device['description'] ?? '',
                        'device' => $device['tech'] ?? $ext['tech'] ?? 'Unknown',
                        'status' => 'available',
                        'caller_id' => $user['outboundCid'] ?? '',
                        'dial_string' => $device['dial'] ?? null,
                        'device_type' => $device['devicetype'] ?? null,
                        'description' => $device['description'] ?? null,
                        'voicemail' => $user['voicemail'] ?? null,
                        'call_waiting' => $user['callwaiting'] ?? null,
                        'do_not_disturb' => $user['donotdisturb'] ?? null,
                        'call_forward' => $user['callforward_all'] ?? null,
                        'sip_name' => $user['sipname'] ?? null,
                        'emergency_cid' => $device['emergencyCid'] ?? null,
                    ];
                }, $rawExtensions);

                $result['success'] = true;
                $result['extensions'] = $extensions;
                $result['extensions_count'] = count($extensions);
                $result['total_count'] = $connection['totalCount'] ?? count($extensions);
                $result['connection'] = [
                    'status' => 'connected',
                    'message' => $connection['message'] ?? 'Successfully connected via GraphQL.',
                    'method' => 'graphql',
                    'response_time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ];
                $result['last_sync'] = now()->toIso8601String();
            } else {
                // ── Fallback: REST API ──
                Log::info('[PBX] GraphQL failed, trying REST fallback', ['status' => $graphqlResponse->status()]);

                $restResponse = Http::withToken($accessToken)
                    ->acceptJson()
                    ->timeout(15)
                    ->get($restUrl . '/extensions');

                if ($restResponse->successful()) {
                    $restData = $restResponse->json('data', $restResponse->json());
                    $rawExtensions = is_array($restData) ? $restData : ($restData['extensions'] ?? []);

                    $extensions = array_map(function ($ext) {
                        $user = $ext['user'] ?? $ext;
                        $device = $ext['coreDevice'] ?? $ext;
                        return [
                            'extension' => $ext['extensionId'] ?? $ext['extension'] ?? '',
                            'name' => $user['name'] ?? $device['description'] ?? '',
                            'device' => $device['tech'] ?? $ext['tech'] ?? 'Unknown',
                            'status' => 'available',
                            'caller_id' => $user['outboundCid'] ?? '',
                            'dial_string' => $device['dial'] ?? null,
                            'device_type' => $device['devicetype'] ?? null,
                            'description' => $device['description'] ?? null,
                        ];
                    }, $rawExtensions);

                    $result['success'] = true;
                    $result['extensions'] = $extensions;
                    $result['extensions_count'] = count($extensions);
                    $result['connection'] = [
                        'status' => 'connected',
                        'message' => 'Connected via REST fallback (GraphQL unavailable).',
                        'method' => 'rest',
                        'response_time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    ];
                    $result['last_sync'] = now()->toIso8601String();
                } else {
                    $result['connection'] = [
                        'status' => 'error',
                        'message' => 'GraphQL and REST endpoints both failed.',
                        'method' => 'none',
                        'response_time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    ];
                    $result['error'] = $this->buildError(
                        'api_unavailable',
                        $graphqlResponse->status(),
                        $graphqlResponse->json() ?? $graphqlResponse->body()
                    );
                }
            }
        } catch (Throwable $e) {
            Log::error('[PBX] Extensions fetch error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $result['connection'] = [
                'status' => 'error',
                'message' => 'Exception: ' . $e->getMessage(),
                'response_time_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
            $result['error'] = $this->buildError('exception', null, null, $e);
        }

        return response()->json($result, 200);
    }

    public function testExtension(Request $request, string $extension): JsonResponse
    {
        $baseUrl = rtrim((string) config('freepbx.base_url', env('FREEPBX_BASE_URL', '')), '/');
        $authUrl = (string) env('FREEPBX_TOKEN_URL', $baseUrl . '/admin/api/api/token');
        $graphqlUrl = (string) config('freepbx.graphql_url', $baseUrl . '/admin/api/api/gql');
        $clientId = (string) config('freepbx.client_id', env('FREEPBX_CLIENT_ID', ''));
        $clientSecret = (string) config('freepbx.client_secret', env('FREEPBX_CLIENT_SECRET', ''));
        $scope = (string) config('freepbx.scope', env('FREEPBX_SCOPE', ''));
        $grantType = (string) config('freepbx.grant_type', env('FREEPBX_GRANT_TYPE', 'client_credentials'));

        try {
            if (empty($clientId) || empty($clientSecret)) {
                throw new \RuntimeException('FreePBX credentials not configured.');
            }

            $tokenResponse = Http::withoutVerifying()->asForm()->timeout(10)->post($authUrl, [
                'grant_type' => $grantType,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => $scope,
            ]);

            if (!$tokenResponse->successful()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Authentication failed',
                    'http_status' => $tokenResponse->status(),
                ]);
            }

            $accessToken = $tokenResponse->json('access_token');

            $query = '{ fetchExtension(extensionId: "' . addslashes($extension) . '") { id extensionId tech status message user { id extension name outboundCid sipname voicemail callwaiting donotdisturb callforward_all } coreDevice { id deviceId tech dial devicetype description emergencyCid } } }';
            $graphqlResponse = Http::withToken($accessToken)->acceptJson()->timeout(10)->asJson()->post($graphqlUrl, ['query' => $query]);

            if ($graphqlResponse->successful()) {
                $extData = $graphqlResponse->json('data.fetchExtension');
                if ($extData) {
                    $user = $extData['user'] ?? [];
                    $device = $extData['coreDevice'] ?? [];
                    return response()->json([
                        'success' => true,
                        'extension' => [
                            'extension' => $extData['extensionId'] ?? '',
                            'name' => $user['name'] ?? $device['description'] ?? '',
                            'device' => $device['tech'] ?? $extData['tech'] ?? 'Unknown',
                            'status' => 'available',
                            'caller_id' => $user['outboundCid'] ?? '',
                            'dial_string' => $device['dial'] ?? null,
                            'device_type' => $device['devicetype'] ?? null,
                            'description' => $device['description'] ?? null,
                            'voicemail' => $user['voicemail'] ?? null,
                            'call_waiting' => $user['callwaiting'] ?? null,
                            'do_not_disturb' => $user['donotdisturb'] ?? null,
                            'call_forward' => $user['callforward_all'] ?? null,
                            'sip_name' => $user['sipname'] ?? null,
                            'emergency_cid' => $device['emergencyCid'] ?? null,
                        ],
                    ]);
                }
            }

            return response()->json([
                'success' => false,
                'error' => 'Extension not found or API unavailable',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function mapExtensionStatus(string $status): string
    {
        return match (strtolower($status)) {
            'online', 'registered', 'available', 'idle', 'in_use' => 'available',
            'busy', 'ringing', 'on_hold' => 'busy',
            'offline', 'unavailable', 'unknown', 'not_registered' => 'unavailable',
            default => 'unknown',
        };
    }

    private function buildError(string $type, ?int $httpStatus, mixed $body = null, ?Throwable $exception = null): array
    {
        $solutions = [
            'authentication_failed' => 'Verify FreePBX OAuth credentials (FREEPBX_CLIENT_ID, FREEPBX_CLIENT_SECRET) and server URL.',
            'api_unavailable' => 'FreePBX GraphQL/REST API endpoints are unreachable. Check FREEPBX_GRAPHQL_URL and FREEPBX_REST_URL.',
            'exception' => 'An unexpected error occurred. Check the Laravel log for details.',
        ];

        return [
            'type' => $type,
            'message' => match ($type) {
                'authentication_failed' => 'OAuth authentication with FreePBX failed.',
                'api_unavailable' => 'FreePBX API endpoints are not responding.',
                default => 'An error occurred while communicating with FreePBX.',
            },
            'http_status' => $httpStatus,
            'suggested_solution' => $solutions[$type] ?? 'Check the FreePBX configuration.',
            'stack' => app()->environment('local') && $exception ? $exception->getTraceAsString() : null,
        ];
    }
}
