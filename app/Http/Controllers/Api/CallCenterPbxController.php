<?php

/**
 * Call Center PBX Controller
 *
 * Routes to add in routes/api.php:
 *
 *   use App\Http\Controllers\Api\CallCenterPbxController;
 *
 *   Route::prefix('call-center/pbx')->group(function () {
 *       Route::get('/live-calls', [CallCenterPbxController::class, 'liveCalls']);
 *       Route::get('/cdr', [CallCenterPbxController::class, 'callHistory']);
 *       Route::get('/queues', [CallCenterPbxController::class, 'queueStats']);
 *       Route::get('/agents', [CallCenterPbxController::class, 'agentStats']);
 *       Route::get('/analytics', [CallCenterPbxController::class, 'analytics']);
 *       Route::get('/extensions', [CallCenterPbxController::class, 'extensions']);
 *       Route::get('/trunks', [CallCenterPbxController::class, 'trunks']);
 *       Route::get('/recordings/{uniqueid}/play', [CallCenterPbxController::class, 'playRecording']);
 *       Route::get('/recordings/{uniqueid}/download', [CallCenterPbxController::class, 'downloadRecording']);
 *       Route::get('/blacklist', [CallCenterPbxController::class, 'blacklistIndex']);
 *       Route::post('/blacklist', [CallCenterPbxController::class, 'blacklistStore']);
 *       Route::delete('/blacklist/{id}', [CallCenterPbxController::class, 'blacklistDestroy']);
 *   });
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\StreamedResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CallCenterPbxController extends Controller
{
    private string $freepbxBaseUrl;
    private string $freepbxGraphqlUrl;
    private string $freepbxRestUrl;
    private string $freepbxClientId;
    private string $freepbxClientSecret;
    private string $freepbxScope;
    private string $freepbxGrantType;

    private ?string $cachedToken = null;
    private ?int $tokenExpiresAt = 0;

    public function __construct()
    {
        $this->freepbxBaseUrl    = config('freepbx.base_url', '');
        $this->freepbxGraphqlUrl = config('freepbx.graphql_url', '');
        $this->freepbxRestUrl    = config('freepbx.rest_url', '');
        $this->freepbxClientId   = config('freepbx.client_id', '');
        $this->freepbxClientSecret = config('freepbx.client_secret', '');
        $this->freepbxScope      = config('freepbx.scope', '');
        $this->freepbxGrantType  = config('freepbx.grant_type', 'client_credentials');
    }

    // -------------------------------------------------------------------------
    // Auth helpers
    // -------------------------------------------------------------------------

    /**
     * Obtain an OAuth2 bearer token from FreePBX.
     */
    private function getAccessToken(): string
    {
        if ($this->cachedToken && $this->tokenExpiresAt > time()) {
            return $this->cachedToken;
        }

        $authUrl = rtrim($this->freepbxBaseUrl, '/') . '/admin/api/api/token';

        $response = Http::asForm()->post($authUrl, [
            'grant_type'    => $this->freepbxGrantType,
            'client_id'     => $this->freepbxClientId,
            'client_secret' => $this->freepbxClientSecret,
        ]);

        if ($response->failed()) {
            Log::error('FreePBX auth failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('Failed to authenticate with FreePBX');
        }

        $data   = $response->json();
        $token  = $data['access_token'] ?? '';
        $expiry = $data['expires_in'] ?? 3600;

        $this->cachedToken    = $token;
        $this->tokenExpiresAt = time() + $expiry - 60; // refresh 60 s early

        return $this->cachedToken;
    }

    /**
     * Authenticated GET request to the FreePBX REST API.
     */
    private function freepbxGet(string $endpoint, array $query = []): array
    {
        $url = rtrim($this->freepbxRestUrl, '/') . '/' . ltrim($endpoint, '/');

        $response = Http::withToken($this->getAccessToken())
            ->timeout(30)
            ->get($url, $query);

        if ($response->failed()) {
            Log::error('FreePBX REST request failed', [
                'url'    => $url,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('FreePBX REST request failed');
        }

        return $response->json();
    }

    /**
     * Authenticated POST to the FreePBX GraphQL endpoint.
     */
    private function graphql(string $query, ?string $operationName = null): array
    {
        $payload = ['query' => $query];
        if ($operationName) {
            $payload['operationName'] = $operationName;
        }

        $response = Http::withToken($this->getAccessToken())
            ->timeout(60)
            ->post($this->freepbxGraphqlUrl, $payload);

        if ($response->failed()) {
            Log::error('FreePBX GraphQL request failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('FreePBX GraphQL request failed');
        }

        $json = $response->json();

        if (!empty($json['errors'])) {
            Log::warning('FreePBX GraphQL errors', ['errors' => $json['errors']]);
        }

        return $json['data'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Customer lookup helpers
    // -------------------------------------------------------------------------

    /**
     * Try to match one or more phone numbers against the ERP customer database.
     * Returns an array keyed by the normalised phone number.
     */
    private function matchCustomers(array $phoneNumbers): array
    {
        $unique = array_values(array_unique(array_filter($phoneNumbers)));
        if (empty($unique)) {
            return [];
        }

        try {
            $erpApiBase = config('erp.api_base_url', config('services.erp.base_url', ''));
            $erpToken   = config('erp.api_token', config('services.erp.token', ''));

            if (!$erpApiBase || !$erpToken) {
                return [];
            }

            $response = Http::withToken($erpToken)
                ->timeout(15)
                ->post(rtrim($erpApiBase, '/') . '/api/customers/lookup', [
                    'phone_numbers' => $unique,
                ]);

            if ($response->failed()) {
                Log::warning('ERP customer lookup failed', ['status' => $response->status()]);
                return [];
            }

            return $response->json('data', []);
        } catch (\Throwable $e) {
            Log::warning('ERP customer lookup exception', ['message' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Normalise a phone number for comparison (strip spaces, dashes, leading + / 00).
     */
    private function normalisePhone(?string $phone): string
    {
        if (!$phone) {
            return '';
        }
        $digits = preg_replace('/[^0-9]/', '', $phone);
        // Strip leading country code 966 for Saudi numbers
        if (strlen($digits) > 9 && str_starts_with($digits, '966')) {
            $digits = substr($digits, 3);
        }
        return $digits;
    }

    // -------------------------------------------------------------------------
    // 1. Live Calls
    // -------------------------------------------------------------------------

    /**
     * GET /api/call-center/pbx/live-calls
     *
     * Returns currently active channels and real-time queue metrics.
     */
    public function liveCalls(): JsonResponse
    {
        try {
            $channelsQuery = '{
                fetchActiveChannels {
                    totalCount
                    channel {
                        channel
                        channelState
                        channelStateText
                        callerIdNum
                        callerIdName
                        connectedLineNum
                        connectedLineName
                        application
                        applicationData
                        context
                        duration
                        bridgeId
                    }
                }
            }';

            $queuesQuery = '{
                fetchQueues {
                    totalCount
                    queue {
                        name
                        callscompleted
                        callscompletedabandoned
                        callsdropped
                        callswaiting
                        members {
                            memberName
                            membership
                            paused
                            callsTaken
                            lastCall
                            penalty
                        }
                    }
                }
            }';

            $channelData = $this->graphql($channelsQuery, 'FetchActiveChannels');
            $queueData   = $this->graphql($queuesQuery, 'FetchQueues');

            $activeChannels = $channelData['fetchActiveChannels']['channel'] ?? [];
            $queues         = $queueData['fetchQueues']['queue'] ?? [];

            // Collect phone numbers for customer matching
            $phoneNumbers = [];
            foreach ($activeChannels as $ch) {
                if (!empty($ch['callerIdNum'])) {
                    $phoneNumbers[] = $ch['callerIdNum'];
                }
                if (!empty($ch['connectedLineNum'])) {
                    $phoneNumbers[] = $ch['connectedLineNum'];
                }
            }
            foreach ($queues as $queue) {
                foreach ($queue['members'] ?? [] as $member) {
                    if (!empty($member['memberName'])) {
                        $phoneNumbers[] = $member['memberName'];
                    }
                }
            }

            $customerMap = $this->matchCustomers($phoneNumbers);

            // Enrich channels with customer data and call status
            $enrichedChannels = array_map(function ($ch) use ($customerMap) {
                $callerNorm    = $this->normalisePhone($ch['callerIdNum'] ?? '');
                $connectedNorm = $this->normalisePhone($ch['connectedLineNum'] ?? '');

                $status = 'unknown';
                $state  = $ch['channelState'] ?? null;
                $app    = $ch['application'] ?? '';

                if ($state === '6') { // AST_STATE_UP
                    $status = 'answered';
                } elseif ($state === '5' && strtolower($app) === 'bridge') {
                    $status = 'bridged';
                } elseif ($state === '5') {
                    $status = 'ringing';
                } elseif ($state === '4') {
                    $status = 'dialing';
                } elseif ($state === '1') {
                    $status = 'down';
                } elseif ($state === '2') {
                    $status = 'reserved';
                } elseif ($state === '3') {
                    $status = 'offhook';
                }

                return [
                    'channel'            => $ch['channel'] ?? '',
                    'channelState'       => $ch['channelState'] ?? null,
                    'channelStateText'   => $ch['channelStateText'] ?? '',
                    'callerIdNum'        => $ch['callerIdNum'] ?? '',
                    'callerIdName'       => $ch['callerIdName'] ?? '',
                    'connectedLineNum'   => $ch['connectedLineNum'] ?? '',
                    'connectedLineName'  => $ch['connectedLineName'] ?? '',
                    'application'        => $ch['application'] ?? '',
                    'applicationData'    => $ch['applicationData'] ?? '',
                    'context'            => $ch['context'] ?? '',
                    'duration'           => (int) ($ch['duration'] ?? 0),
                    'bridgeId'           => $ch['bridgeId'] ?? '',
                    'status'             => $status,
                    'callerCustomer'     => $customerMap[$callerNorm] ?? null,
                    'connectedCustomer'  => $customerMap[$connectedNorm] ?? null,
                ];
            }, $activeChannels);

            return response()->json([
                'success' => true,
                'data'    => [
                    'activeChannels' => $enrichedChannels,
                    'totalChannels'  => $channelData['fetchActiveChannels']['totalCount'] ?? count($enrichedChannels),
                    'queues'         => $queues,
                    'totalQueues'    => $queueData['fetchQueues']['totalCount'] ?? count($queues),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('liveCalls error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch live calls',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // -------------------------------------------------------------------------
    // 2. Call History (CDR)
    // -------------------------------------------------------------------------

    /**
     * GET /api/call-center/pbx/cdr
     *
     * Paginated call detail records with filtering.
     */
    public function callHistory(Request $request): JsonResponse
    {
        try {
            $page    = max(1, (int) $request->input('page', 1));
            $perPage = min(200, max(1, (int) $request->input('per_page', 25)));
            $first   = $perPage;
            $skip    = ($page - 1) * $perPage;

            $startDate = $request->input('start_date');
            $endDate   = $request->input('end_date');
            $caller    = $request->input('caller');
            $dest      = $request->input('destination');
            $disp      = $request->input('disposition');
            $direction = $request->input('direction');

            // Build optional filter arguments
            $args = [];
            if ($startDate) {
                $args[] = "startDate: \"$startDate\"";
            }
            if ($endDate) {
                $args[] = "endDate: \"$endDate\"";
            }

            $argsStr    = $args ? ' (' . implode(', ', $args) . ')' : '';
            $query      = '{
                fetchAllCdrs' . $argsStr . ' {
                    totalCount
                    cdrs {
                        uniqueid
                        calldate
                        clid
                        src
                        dst
                        dcontext
                        channel
                        dstchannel
                        duration
                        billsec
                        disposition
                        recordingfile
                        accountcode
                        userfield
                        did
                        cnum
                        outbound_cnum
                        outbound_cnam
                        dst_cnam
                        lastapp
                        lastdata
                        amaflags
                        callstart
                    }
                }
            }';

            $data  = $this->graphql($query, 'FetchAllCdrs');
            $allCdrs = $data['fetchAllCdrs']['cdrs'] ?? [];
            $totalAll = $data['fetchAllCdrs']['totalCount'] ?? 0;

            // Server-side filtering (FreePBX GraphQL may not support all filters)
            $filtered = array_filter($allCdrs, function ($cdr) use ($caller, $dest, $disp, $direction) {
                if ($caller && stripos($cdr['src'] ?? '', $caller) === false && stripos($cdr['clid'] ?? '', $caller) === false) {
                    return false;
                }
                if ($dest && stripos($cdr['dst'] ?? '', $dest) === false) {
                    return false;
                }
                if ($disp && stripos($cdr['disposition'] ?? '', $disp) === false) {
                    return false;
                }
                if ($direction === 'inbound' && empty($cdr['did'])) {
                    return false;
                }
                if ($direction === 'outbound' && !empty($cdr['did'])) {
                    return false;
                }
                return true;
            });

            $filtered = array_values($filtered);

            // Paginate after filtering
            $totalCount = count($filtered);
            $pageItems  = array_slice($filtered, $skip, $perPage);

            // Customer matching
            $phoneNumbers = [];
            foreach ($pageItems as $cdr) {
                if (!empty($cdr['src'])) {
                    $phoneNumbers[] = $cdr['src'];
                }
                if (!empty($cdr['dst'])) {
                    $phoneNumbers[] = $cdr['dst'];
                }
                if (!empty($cdr['cnum'])) {
                    $phoneNumbers[] = $cdr['cnum'];
                }
            }
            $customerMap = $this->matchCustomers($phoneNumbers);

            $enrichedCdrs = array_map(function ($cdr) use ($customerMap) {
                $srcNorm = $this->normalisePhone($cdr['src'] ?? '');
                $dstNorm = $this->normalisePhone($cdr['dst'] ?? '');

                $isInbound = !empty($cdr['did']);

                return [
                    'uniqueid'        => $cdr['uniqueid'] ?? '',
                    'calldate'        => $cdr['calldate'] ?? '',
                    'clid'            => $cdr['clid'] ?? '',
                    'src'             => $cdr['src'] ?? '',
                    'dst'             => $cdr['dst'] ?? '',
                    'dcontext'        => $cdr['dcontext'] ?? '',
                    'channel'         => $cdr['channel'] ?? '',
                    'dstchannel'      => $cdr['dstchannel'] ?? '',
                    'duration'        => (int) ($cdr['duration'] ?? 0),
                    'billsec'         => (int) ($cdr['billsec'] ?? 0),
                    'disposition'     => $cdr['disposition'] ?? '',
                    'recordingfile'   => $cdr['recordingfile'] ?? '',
                    'accountcode'     => $cdr['accountcode'] ?? '',
                    'userfield'       => $cdr['userfield'] ?? '',
                    'did'             => $cdr['did'] ?? '',
                    'cnum'            => $cdr['cnum'] ?? '',
                    'outbound_cnum'   => $cdr['outbound_cnum'] ?? '',
                    'outbound_cnam'   => $cdr['outbound_cnam'] ?? '',
                    'dst_cnam'        => $cdr['dst_cnam'] ?? '',
                    'lastapp'         => $cdr['lastapp'] ?? '',
                    'lastdata'        => $cdr['lastdata'] ?? '',
                    'amaflags'        => $cdr['amaflags'] ?? '',
                    'callstart'       => $cdr['callstart'] ?? '',
                    'direction'       => $isInbound ? 'inbound' : 'outbound',
                    'hasRecording'    => !empty($cdr['recordingfile']),
                    'srcCustomer'     => $customerMap[$srcNorm] ?? null,
                    'dstCustomer'     => $customerMap[$dstNorm] ?? null,
                ];
            }, $pageItems);

            return response()->json([
                'success' => true,
                'data'    => $enrichedCdrs,
                'meta'    => [
                    'total'        => $totalCount,
                    'totalAll'     => $totalAll,
                    'page'         => $page,
                    'per_page'     => $perPage,
                    'total_pages'  => (int) ceil($totalCount / $perPage),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('callHistory error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch call history',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // -------------------------------------------------------------------------
    // 3. Queue Stats
    // -------------------------------------------------------------------------

    /**
     * GET /api/call-center/pbx/queues
     *
     * Real-time queue metrics with member details.
     */
    public function queueStats(): JsonResponse
    {
        try {
            $query = '{
                fetchQueues {
                    totalCount
                    queue {
                        name
                        callscompleted
                        callscompletedabandoned
                        callsdropped
                        callswaiting
                        members {
                            memberName
                            membership
                            paused
                            callsTaken
                            lastCall
                            penalty
                        }
                    }
                }
            }';

            $data    = $this->graphql($query, 'FetchQueues');
            $queues  = $data['fetchQueues']['queue'] ?? [];
            $total   = $data['fetchQueues']['totalCount'] ?? count($queues);

            $enrichedQueues = array_map(function ($queue) {
                $members     = $queue['members'] ?? [];
                $activeMembers = array_filter($members, fn ($m) => empty($m['paused']));
                $pausedMembers = array_filter($members, fn ($m) => !empty($m['paused']));

                $totalCallsCompleted    = (int) ($queue['callscompleted'] ?? 0);
                $totalCallsAbandoned    = (int) ($queue['callscompletedabandoned'] ?? 0);
                $totalCallsDropped      = (int) ($queue['callsdropped'] ?? 0);
                $callsWaiting           = (int) ($queue['callswaiting'] ?? 0);

                $answerRate = 0;
                $abandonRate = 0;
                $totalAttempted = $totalCallsCompleted + $totalCallsAbandoned;
                if ($totalAttempted > 0) {
                    $answerRate  = round(($totalCallsCompleted / $totalAttempted) * 100, 2);
                    $abandonRate = round(($totalCallsAbandoned / $totalAttempted) * 100, 2);
                }

                return [
                    'name'                => $queue['name'] ?? '',
                    'callscompleted'      => $totalCallsCompleted,
                    'callscompletedabandoned' => $totalCallsAbandoned,
                    'callsdropped'        => $totalCallsDropped,
                    'callswaiting'        => $callsWaiting,
                    'totalMembers'        => count($members),
                    'activeMembers'       => count($activeMembers),
                    'pausedMembers'       => count($pausedMembers),
                    'answerRate'          => $answerRate,
                    'abandonRate'         => $abandonRate,
                    'members'             => array_map(function ($m) {
                        return [
                            'memberName'   => $m['memberName'] ?? '',
                            'membership'   => $m['membership'] ?? '',
                            'paused'       => (bool) ($m['paused'] ?? false),
                            'callsTaken'   => (int) ($m['callsTaken'] ?? 0),
                            'lastCall'     => $m['lastCall'] ?? '',
                            'penalty'      => (int) ($m['penalty'] ?? 0),
                        ];
                    }, $members),
                ];
            }, $queues);

            return response()->json([
                'success' => true,
                'data'    => $enrichedQueues,
                'meta'    => [
                    'total' => $total,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('queueStats error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch queue stats',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // -------------------------------------------------------------------------
    // 4. Agent Stats
    // -------------------------------------------------------------------------

    /**
     * GET /api/call-center/pbx/agents
     *
     * All extensions with devices and computed per-agent statistics from CDR.
     */
    public function agentStats(Request $request): JsonResponse
    {
        try {
            $extQuery = '{
                fetchAllExtensions(first: 500) {
                    totalCount
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

            $extData     = $this->graphql($extQuery, 'FetchAllExtensions');
            $extensions  = $extData['fetchAllExtensions']['extension'] ?? [];

            // Build CDR query for recent stats (last 30 days)
            $thirtyDaysAgo = now()->subDays(30)->format('Y-m-d H:i:s');
            $cdrQuery = '{
                fetchAllCdrs(startDate: "' . $thirtyDaysAgo . '") {
                    totalCount
                    cdrs {
                        uniqueid
                        src
                        dst
                        duration
                        billsec
                        disposition
                        calldate
                        did
                    }
                }
            }';

            $cdrData = $this->graphql($cdrQuery, 'FetchAllCdrs');
            $cdrs    = $cdrData['fetchAllCdrs']['cdrs'] ?? [];

            // Build per-extension stats
            $agentStats = [];
            foreach ($extensions as $ext) {
                $userId     = $ext['user']['extension'] ?? $ext['extensionId'] ?? '';
                $userName   = $ext['user']['name'] ?? $ext['coreDevice']['description'] ?? $userId;

                $agentStats[$userId] = [
                    'extensionId'    => $ext['extensionId'] ?? $ext['id'] ?? '',
                    'userId'         => $userId,
                    'name'           => $userName,
                    'tech'           => $ext['tech'] ?? '',
                    'sipname'        => $ext['user']['sipname'] ?? '',
                    'outboundCid'    => $ext['user']['outboundCid'] ?? '',
                    'voicemail'      => $ext['user']['voicemail'] ?? '',
                    'callwaiting'    => $ext['user']['callwaiting'] ?? '',
                    'doNotDisturb'   => $ext['user']['donotdisturb'] ?? '',
                    'callForwardAll' => $ext['user']['callforward_all'] ?? '',
                    'deviceType'     => $ext['coreDevice']['devicetype'] ?? '',
                    'dialString'     => $ext['coreDevice']['dial'] ?? '',
                    'description'    => $ext['coreDevice']['description'] ?? '',
                    'stats'          => [
                        'totalCalls'       => 0,
                        'inboundCalls'     => 0,
                        'outboundCalls'    => 0,
                        'answeredCalls'    => 0,
                        'missedCalls'      => 0,
                        'totalDuration'    => 0,
                        'totalBillSec'     => 0,
                        'avgDuration'      => 0,
                        'avgBillSec'       => 0,
                    ],
                ];
            }

            // Aggregate CDR data
            foreach ($cdrs as $cdr) {
                $src = $cdr['src'] ?? '';
                $dst = $cdr['dst'] ?? '';
                $isInbound = !empty($cdr['did']);

                $agentKey = $isInbound ? $dst : $src;
                if (!isset($agentStats[$agentKey])) {
                    continue;
                }

                $stat = &$agentStats[$agentKey]['stats'];
                $stat['totalCalls']++;
                $stat['totalDuration'] += (int) ($cdr['duration'] ?? 0);
                $stat['totalBillSec']  += (int) ($cdr['billsec'] ?? 0);

                if ($isInbound) {
                    $stat['inboundCalls']++;
                } else {
                    $stat['outboundCalls']++;
                }

                $disp = strtoupper($cdr['disposition'] ?? '');
                if ($disp === 'ANSWERED' || $disp === 'COMPLETECALLER' || $disp === 'COMPLETEAGENT') {
                    $stat['answeredCalls']++;
                } else {
                    $stat['missedCalls']++;
                }
            }

            // Calculate averages
            foreach ($agentStats as &$agent) {
                $s = &$agent['stats'];
                if ($s['totalCalls'] > 0) {
                    $s['avgDuration'] = round($s['totalDuration'] / $s['totalCalls'], 2);
                    $s['avgBillSec']  = round($s['totalBillSec'] / $s['totalCalls'], 2);
                }
                unset($s);
            }
            unset($agent);

            $result = array_values($agentStats);

            // Sort by total calls descending
            usort($result, fn ($a, $b) => $b['stats']['totalCalls'] <=> $a['stats']['totalCalls']);

            return response()->json([
                'success' => true,
                'data'    => $result,
                'meta'    => [
                    'totalExtensions' => count($extensions),
                    'period'          => 'last_30_days',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('agentStats error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch agent stats',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // -------------------------------------------------------------------------
    // 5. Analytics
    // -------------------------------------------------------------------------

    /**
     * GET /api/call-center/pbx/analytics
     *
     * Aggregated CDR data for dashboard charts.
     *
     * @param  string  period  today | week | month | custom
     */
    public function analytics(Request $request): JsonResponse
    {
        try {
            $period   = $request->input('period', 'today');
            $customStart = $request->input('start_date');
            $customEnd   = $request->input('end_date');

            switch ($period) {
                case 'today':
                    $startDate = now()->startOfDay()->format('Y-m-d H:i:s');
                    $endDate   = now()->endOfDay()->format('Y-m-d H:i:s');
                    break;
                case 'week':
                    $startDate = now()->startOfWeek()->format('Y-m-d H:i:s');
                    $endDate   = now()->endOfWeek()->format('Y-m-d H:i:s');
                    break;
                case 'month':
                    $startDate = now()->startOfMonth()->format('Y-m-d H:i:s');
                    $endDate   = now()->endOfMonth()->format('Y-m-d H:i:s');
                    break;
                case 'custom':
                    $startDate = $customStart ?? now()->subDays(7)->format('Y-m-d H:i:s');
                    $endDate   = $customEnd ?? now()->format('Y-m-d H:i:s');
                    break;
                default:
                    $startDate = now()->startOfDay()->format('Y-m-d H:i:s');
                    $endDate   = now()->endOfDay()->format('Y-m-d H:i:s');
            }

            $args     = "startDate: \"$startDate\", endDate: \"$endDate\"";
            $cdrQuery = '{
                fetchAllCdrs(' . $args . ') {
                    totalCount
                    cdrs {
                        uniqueid
                        src
                        dst
                        duration
                        billsec
                        disposition
                        calldate
                        did
                        lastapp
                    }
                }
            }';

            $cdrData = $this->graphql($cdrQuery, 'FetchAllCdrs');
            $cdrs    = $cdrData['fetchAllCdrs']['cdrs'] ?? [];
            $totalAll = $cdrData['fetchAllCdrs']['totalCount'] ?? count($cdrs);

            // Per hour aggregation
            $callsPerHour = array_fill(0, 24, [
                'total'    => 0,
                'answered' => 0,
                'missed'   => 0,
                'inbound'  => 0,
                'outbound' => 0,
            ]);

            // Per day aggregation (keyed by date)
            $callsPerDay = [];

            $answeredCount = 0;
            $missedCount   = 0;
            $inboundCount  = 0;
            $outboundCount = 0;
            $totalDuration = 0;
            $totalBillSec  = 0;
            $maxConcurrent = 0;
            $concurrentMap = [];

            foreach ($cdrs as $cdr) {
                $calldate = $cdr['calldate'] ?? '';
                $hour     = (int) date('H', strtotime($calldate));
                $dayKey   = date('Y-m-d', strtotime($calldate));

                $duration = (int) ($cdr['duration'] ?? 0);
                $billSec  = (int) ($cdr['billsec'] ?? 0);
                $isInbound = !empty($cdr['did']);
                $disp      = strtoupper($cdr['disposition'] ?? '');
                $isAnswered = $disp === 'ANSWERED' || $disp === 'COMPLETECALLER' || $disp === 'COMPLETEAGENT';

                $totalDuration += $duration;
                $totalBillSec  += $billSec;

                if ($isAnswered) {
                    $answeredCount++;
                } else {
                    $missedCount++;
                }

                if ($isInbound) {
                    $inboundCount++;
                } else {
                    $outboundCount++;
                }

                // Hour bucket
                $callsPerHour[$hour]['total']++;
                if ($isAnswered) {
                    $callsPerHour[$hour]['answered']++;
                } else {
                    $callsPerHour[$hour]['missed']++;
                }
                if ($isInbound) {
                    $callsPerHour[$hour]['inbound']++;
                } else {
                    $callsPerHour[$hour]['outbound']++;
                }

                // Day bucket
                if (!isset($callsPerDay[$dayKey])) {
                    $callsPerDay[$dayKey] = [
                        'date'     => $dayKey,
                        'total'    => 0,
                        'answered' => 0,
                        'missed'   => 0,
                        'inbound'  => 0,
                        'outbound' => 0,
                    ];
                }
                $callsPerDay[$dayKey]['total']++;
                if ($isAnswered) {
                    $callsPerDay[$dayKey]['answered']++;
                } else {
                    $callsPerDay[$dayKey]['missed']++;
                }
                if ($isInbound) {
                    $callsPerDay[$dayKey]['inbound']++;
                } else {
                    $callsPerDay[$dayKey]['outbound']++;
                }

                // Track concurrent calls by bridge id simulation (duration overlap)
                $callStart = strtotime($calldate);
                $callEnd   = $callStart + $duration;
                $concurrentMap[] = ['start' => $callStart, 'end' => $callEnd];
            }

            // Calculate max concurrent calls (simple sweep)
            if (!empty($concurrentMap)) {
                $events = [];
                foreach ($concurrentMap as $c) {
                    $events[] = ['time' => $c['start'], 'type' => 'start'];
                    $events[] = ['time' => $c['end'],   'type' => 'end'];
                }
                usort($events, fn ($a, $b) => $a['time'] <=> $b['time'] ?: $a['type'] <=> $b['type']);
                $current = 0;
                foreach ($events as $ev) {
                    if ($ev['type'] === 'start') {
                        $current++;
                        if ($current > $maxConcurrent) {
                            $maxConcurrent = $current;
                        }
                    } else {
                        $current--;
                    }
                }
            }

            $totalCalls = count($cdrs);
            $avgDuration = $totalCalls > 0 ? round($totalDuration / $totalCalls, 2) : 0;
            $avgBillSec  = $totalCalls > 0 ? round($totalBillSec / $totalCalls, 2) : 0;

            return response()->json([
                'success' => true,
                'data'    => [
                    'summary' => [
                        'totalCalls'      => $totalAll,
                        'answeredCalls'   => $answeredCount,
                        'missedCalls'     => $missedCount,
                        'inboundCalls'    => $inboundCount,
                        'outboundCalls'   => $outboundCount,
                        'avgDuration'     => $avgDuration,
                        'avgBillSec'      => $avgBillSec,
                        'maxConcurrent'   => $maxConcurrent,
                        'answerRate'      => $totalCalls > 0 ? round(($answeredCount / $totalCalls) * 100, 2) : 0,
                        'abandonRate'     => $totalCalls > 0 ? round(($missedCount / $totalCalls) * 100, 2) : 0,
                    ],
                    'callsPerHour' => array_values($callsPerHour),
                    'callsPerDay'  => array_values($callsPerDay),
                    'period'       => [
                        'type'       => $period,
                        'start_date' => $startDate,
                        'end_date'   => $endDate,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('analytics error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch analytics',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // -------------------------------------------------------------------------
    // 6. Extensions
    // -------------------------------------------------------------------------

    /**
     * GET /api/call-center/pbx/extensions
     *
     * All extensions with user and device details.
     */
    public function extensions(Request $request): JsonResponse
    {
        try {
            $first = min(500, max(1, (int) $request->input('per_page', 100)));

            $query = '{
                fetchAllExtensions(first: ' . $first . ') {
                    totalCount
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

            $data       = $this->graphql($query, 'FetchAllExtensions');
            $extensions = $data['fetchAllExtensions']['extension'] ?? [];
            $totalCount = $data['fetchAllExtensions']['totalCount'] ?? count($extensions);

            $result = array_map(function ($ext) {
                return [
                    'id'           => $ext['id'] ?? '',
                    'extensionId'  => $ext['extensionId'] ?? '',
                    'tech'         => $ext['tech'] ?? '',
                    'user'         => [
                        'id'              => $ext['user']['id'] ?? '',
                        'extension'       => $ext['user']['extension'] ?? '',
                        'name'            => $ext['user']['name'] ?? '',
                        'outboundCid'     => $ext['user']['outboundCid'] ?? '',
                        'sipname'         => $ext['user']['sipname'] ?? '',
                        'voicemail'       => $ext['user']['voicemail'] ?? '',
                        'callwaiting'     => $ext['user']['callwaiting'] ?? '',
                        'doNotDisturb'    => $ext['user']['donotdisturb'] ?? '',
                        'callForwardAll'  => $ext['user']['callforward_all'] ?? '',
                    ],
                    'coreDevice'   => [
                        'id'             => $ext['coreDevice']['id'] ?? '',
                        'deviceId'       => $ext['coreDevice']['deviceId'] ?? '',
                        'tech'           => $ext['coreDevice']['tech'] ?? '',
                        'dial'           => $ext['coreDevice']['dial'] ?? '',
                        'devicetype'     => $ext['coreDevice']['devicetype'] ?? '',
                        'description'    => $ext['coreDevice']['description'] ?? '',
                        'emergencyCid'   => $ext['coreDevice']['emergencyCid'] ?? '',
                    ],
                ];
            }, $extensions);

            return response()->json([
                'success' => true,
                'data'    => $result,
                'meta'    => [
                    'total' => $totalCount,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('extensions error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch extensions',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // -------------------------------------------------------------------------
    // 7. Trunks
    // -------------------------------------------------------------------------

    /**
     * GET /api/call-center/pbx/trunks
     *
     * Trunk status via GraphQL or REST fallback.
     */
    public function trunks(): JsonResponse
    {
        try {
            // Attempt GraphQL first
            $query = '{
                fetchTrunks {
                    totalCount
                    trunk {
                        name
                        tech
                        maxchans
                        acceptRejectCodec
                        dialplan
                        context
                        host
                        port
                        disable
                    }
                }
            }';

            $data   = $this->graphql($query, 'FetchTrunks');
            $trunks = $data['fetchTrunks']['trunk'] ?? null;

            if ($trunks === null) {
                // Fallback: try REST API
                try {
                    $restData = $this->freepbxGet('/admin/api/module/admin/getmoduleinfo', [
                        'type' => 'trunks',
                    ]);
                    $trunks = $restData['data'] ?? $restData ?? [];
                } catch (\Throwable $e) {
                    Log::warning('REST trunk fallback failed', ['message' => $e->getMessage()]);
                    $trunks = [];
                }
            }

            if (is_array($trunks) && !isset($trunks[0]) && !empty($trunks)) {
                $trunks = [$trunks];
            }

            $result = array_map(function ($trunk) {
                return [
                    'name'                => $trunk['name'] ?? '',
                    'tech'                => $trunk['tech'] ?? '',
                    'maxchans'            => (int) ($trunk['maxchans'] ?? 0),
                    'acceptRejectCodec'   => $trunk['acceptRejectCodec'] ?? '',
                    'dialplan'            => $trunk['dialplan'] ?? '',
                    'context'             => $trunk['context'] ?? '',
                    'host'                => $trunk['host'] ?? '',
                    'port'                => $trunk['port'] ?? '',
                    'disabled'            => (bool) ($trunk['disable'] ?? false),
                ];
            }, $trunks ?? []);

            return response()->json([
                'success' => true,
                'data'    => $result,
                'meta'    => [
                    'total' => count($result),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('trunks error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch trunks',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // -------------------------------------------------------------------------
    // 8. Blacklist
    // -------------------------------------------------------------------------

    /**
     * GET /api/call-center/pbx/blacklist
     */
    public function blacklistIndex(): JsonResponse
    {
        try {
            $data = $this->freepbxGet('/admin/api/blacklist');

            return response()->json([
                'success' => true,
                'data'    => $data['data'] ?? $data ?? [],
            ]);
        } catch (\Throwable $e) {
            Log::error('blacklistIndex error', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch blacklist',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/call-center/pbx/blacklist
     *
     * Body: { number: "...", name: "..." }
     */
    public function blacklistStore(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'number' => 'required|string|max:20',
                'name'   => 'nullable|string|max:255',
            ]);

            $payload = [
                'number' => $request->input('number'),
                'name'   => $request->input('name', ''),
            ];

            $response = Http::withToken($this->getAccessToken())
                ->timeout(15)
                ->post(rtrim($this->freepbxRestUrl, '/') . '/admin/api/blacklist', $payload);

            if ($response->failed()) {
                Log::error('blacklistStore API error', ['status' => $response->status(), 'body' => $response->body()]);
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to add number to blacklist',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Number added to blacklist',
                'data'    => $response->json('data', $payload),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('blacklistStore error', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to add to blacklist',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/call-center/pbx/blacklist/{id}
     */
    public function blacklistDestroy(string $id): JsonResponse
    {
        try {
            $response = Http::withToken($this->getAccessToken())
                ->timeout(15)
                ->delete(rtrim($this->freepbxRestUrl, '/') . '/admin/api/blacklist/' . $id);

            if ($response->failed()) {
                Log::error('blacklistDestroy API error', ['status' => $response->status(), 'body' => $response->body()]);
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to remove from blacklist',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Number removed from blacklist',
            ]);
        } catch (\Throwable $e) {
            Log::error('blacklistDestroy error', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove from blacklist',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // -------------------------------------------------------------------------
    // 9. Recordings
    // -------------------------------------------------------------------------

    /**
     * GET /api/call-center/pbx/recordings/{uniqueid}/play
     *
     * Streams the recording audio file to the client.
     */
    public function playRecording(string $uniqueid): StreamedResponse|JsonResponse
    {
        try {
            $url = rtrim($this->freepbxRestUrl, '/')
                . '/admin/api/recording/play/' . rawurlencode($uniqueid);

            $response = Http::withToken($this->getAccessToken())
                ->timeout(30)
            ->get($url);

            if ($response->failed()) {
                Log::error('playRecording fetch failed', [
                    'status'   => $response->status(),
                    'uniqueid' => $uniqueid,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Recording not found',
                ], 404);
            }

            $contentType = $response->header('Content-Type') ?? 'audio/wav';
            $content     = $response->body();

            return response()->stream(function () use ($content) {
                echo $content;
            }, 200, [
                'Content-Type'        => $contentType,
                'Content-Disposition' => 'inline; filename="recording-' . $uniqueid . '.wav"',
                'Content-Length'      => strlen($content),
                'Cache-Control'       => 'no-cache',
            ]);
        } catch (\Throwable $e) {
            Log::error('playRecording error', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to stream recording',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/call-center/pbx/recordings/{uniqueid}/download
     *
     * Proxies the recording as a downloadable file.
     */
    public function downloadRecording(string $uniqueid): StreamedResponse|JsonResponse
    {
        try {
            $url = rtrim($this->freepbxRestUrl, '/')
                . '/admin/api/recording/play/' . rawurlencode($uniqueid);

            $response = Http::withToken($this->getAccessToken())
                ->timeout(30)
                ->get($url);

            if ($response->failed()) {
                Log::error('downloadRecording fetch failed', [
                    'status'   => $response->status(),
                    'uniqueid' => $uniqueid,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Recording not found',
                ], 404);
            }

            $contentType = $response->header('Content-Type') ?? 'audio/wav';
            $content     = $response->body();

            return response()->stream(function () use ($content) {
                echo $content;
            }, 200, [
                'Content-Type'        => $contentType,
                'Content-Disposition' => 'attachment; filename="recording-' . $uniqueid . '.wav"',
                'Content-Length'      => strlen($content),
                'Cache-Control'       => 'no-cache',
            ]);
        } catch (\Throwable $e) {
            Log::error('downloadRecording error', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to download recording',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
