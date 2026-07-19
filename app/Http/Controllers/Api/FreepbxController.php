<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FreepbxController extends Controller
{
    public function test(Request $request)
    {
        $startedAt = microtime(true);
        $baseUrl = rtrim((string) config('freepbx.base_url', env('FREEPBX_BASE_URL', 'http://192.168.2.250:83')), '/');
        $authUrl = (string) env('FREEPBX_TOKEN_URL');
        // $authUrl = (string) config('freepbx.auth_url', $baseUrl . '/api/oauth/token');
        $graphqlUrl = (string) config('freepbx.graphql_url', $baseUrl . '/api/graphql');
        $restUrl = (string) config('freepbx.rest_url', $baseUrl . '/api/v1/health');
        $clientId = (string) config('freepbx.client_id', env('FREEPBX_CLIENT_ID', ''));
        $clientSecret = (string) config('freepbx.client_secret', env('FREEPBX_CLIENT_SECRET', ''));
        $scope = (string) config('freepbx.scope', env('FREEPBX_SCOPE', 'api'));
        $grantType = (string) config('freepbx.grant_type', env('FREEPBX_GRANT_TYPE', 'client_credentials'));

        $result = [
            'success' => false,
            'connected' => false,
            'serverUrl' => $baseUrl,
            'authentication' => [
                'status' => 'failed',
                'message' => 'Authentication has not been attempted yet.',
            ],
            'accessToken' => null,
            'accessTokenPreview' => null,
            'responseTimeMs' => 0,
            'httpStatus' => null,
            'graphql' => [
                'status' => 'failed',
                'message' => 'GraphQL test has not been attempted yet.',
            ],
            'rest' => [
                'status' => 'failed',
                'message' => 'REST test has not been attempted yet.',
            ],
            'apiResponse' => null,
            'error' => null,
            'requestedAt' => now()->toIso8601String(),
        ];

        try {
            Log::info('[FreePBX] Starting connection test', [
                'user_id' => $request->user()?->id,
                'server_url' => $baseUrl,
                'auth_url' => $authUrl,
                'graphql_url' => $graphqlUrl,
                'rest_url' => $restUrl,
            ]);

            if (empty($clientId) || empty($clientSecret)) {
                throw new \RuntimeException('FreePBX OAuth credentials are missing. Set FREEPBX_CLIENT_ID and FREEPBX_CLIENT_SECRET in the backend environment.');
            }

            $tokenPayload = [
                'grant_type' => $grantType,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => $scope,
            ];

            Log::info('[FreePBX] Requesting access token', ['url' => $authUrl, 'payload' => $tokenPayload]);
            $tokenResponse = Http::withoutVerifying()->asForm()->timeout(10)->post($authUrl, $tokenPayload);
            Log::info('[FreePBX] OAuth token response', [
                'status' => $tokenResponse->status(),
                'body' => $tokenResponse->body(),
            ]);

            $result['httpStatus'] = $tokenResponse->status();

            if ($tokenResponse->successful()) {
                $tokenData = $tokenResponse->json();
                $accessToken = $tokenData['access_token'] ?? null;

                $result['authentication'] = [
                    'status' => 'success',
                    'message' => 'Token request completed successfully.',
                    'tokenType' => $tokenData['token_type'] ?? null,
                ];
                $result['accessToken'] = $accessToken;
                $result['accessTokenPreview'] = $this->maskToken($accessToken);

                $graphqlResponse = Http::withToken($accessToken)->acceptJson()->timeout(10)->asJson()->post($graphqlUrl, [
                    'query' => '{ __typename }',
                ]);

                Log::info('[FreePBX] GraphQL test response', [
                    'status' => $graphqlResponse->status(),
                    'body' => $graphqlResponse->body(),
                ]);

                $result['graphql'] = [
                    'status' => $graphqlResponse->successful() ? 'success' : 'failed',
                    'httpStatus' => $graphqlResponse->status(),
                    'message' => $graphqlResponse->successful() ? 'GraphQL endpoint responded successfully.' : 'GraphQL endpoint did not respond successfully.',
                    'body' => $graphqlResponse->json() ?? $graphqlResponse->body(),
                ];
                $result['httpStatus'] = $graphqlResponse->status();

                if ($graphqlResponse->successful()) {
                    $result['success'] = true;
                    $result['connected'] = true;
                    $result['apiResponse'] = [
                        'message' => 'FreePBX GraphQL endpoint is reachable.',
                        'graphql' => $result['graphql'],
                    ];
                } else {
                    $restResponse = Http::withToken($accessToken)->acceptJson()->timeout(10)->get($restUrl);
                    Log::info('[FreePBX] REST fallback response', [
                        'status' => $restResponse->status(),
                        'body' => $restResponse->body(),
                    ]);

                    $result['rest'] = [
                        'status' => $restResponse->successful() ? 'success' : 'failed',
                        'httpStatus' => $restResponse->status(),
                        'message' => $restResponse->successful() ? 'REST endpoint responded successfully.' : 'REST endpoint did not respond successfully.',
                        'body' => $restResponse->json() ?? $restResponse->body(),
                    ];
                    $result['httpStatus'] = $restResponse->status();

                    if ($restResponse->successful()) {
                        $result['success'] = true;
                        $result['connected'] = true;
                        $result['apiResponse'] = [
                            'message' => 'FreePBX REST endpoint is reachable after GraphQL fallback.',
                            'rest' => $result['rest'],
                        ];
                    } else {
                        $result['error'] = $this->buildErrorPayload(
                            'FreePBX Test Failed',
                            'GraphQL and REST probes both failed. Verify the server URL, OAuth client credentials, and endpoint paths.',
                            $graphqlResponse->status(),
                            $restResponse->status(),
                        );
                    }
                }
            } else {
                $errorBody = $tokenResponse->json() ?? $tokenResponse->body();
                $result['authentication'] = [
                    'status' => 'failed',
                    'message' => 'OAuth token request failed.',
                    'body' => $errorBody,
                ];
                $result['error'] = $this->buildErrorPayload(
                    'Authentication Failed',
                    'The backend could not request an access token from FreePBX.',
                    $tokenResponse->status(),
                    null,
                    $errorBody,
                );
            }
        } catch (Throwable $e) {
            Log::error('[FreePBX] Connection test exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $result['error'] = $this->buildErrorPayload(
                'Connection Error',
                $e->getMessage(),
                null,
                null,
                null,
                $e,
            );
        }

        $result['responseTimeMs'] = (int) round((microtime(true) - $startedAt) * 1000);

        return response()->json($result, 200);
    }

    private function maskToken(?string $token): ?string
    {
        if (empty($token)) {
            return null;
        }

        return substr($token, 0, 20) . '...********';
    }

    private function buildErrorPayload(
        string $title,
        string $message,
        ?int $httpStatus = null,
        ?int $fallbackStatus = null,
        mixed $body = null,
        ?Throwable $exception = null,
    ): array {
        $suggestedSolution = $this->suggestSolution($httpStatus, $fallbackStatus, $body);

        return [
            'title' => $title,
            'message' => $message,
            'httpStatus' => $httpStatus,
            'fallbackStatus' => $fallbackStatus,
            'body' => $body,
            'stack' => app()->environment('local') && $exception ? $exception->getTraceAsString() : null,
            'suggestedSolution' => $suggestedSolution,
        ];
    }

    private function suggestSolution(?int $httpStatus, ?int $fallbackStatus, mixed $body): string
    {
        if ($httpStatus === 401 || $fallbackStatus === 401) {
            return 'Verify that the FreePBX OAuth client credentials are valid and that the client secret matches the server configuration.';
        }

        if ($httpStatus === 403 || $fallbackStatus === 403) {
            return 'Confirm that the OAuth client has the required scope and that the endpoint is permitted for this application.';
        }

        if ($httpStatus === 404 || $fallbackStatus === 404) {
            return 'Check the FreePBX endpoint paths. The GraphQL or REST URLs may be configured incorrectly.';
        }

        if ($httpStatus === 408 || $httpStatus === 504 || $fallbackStatus === 408 || $fallbackStatus === 504) {
            return 'The FreePBX server may be unreachable or timing out. Confirm the host, port, and network connectivity.';
        }

        if (!empty($body)) {
            return 'Inspect the response body from FreePBX and update the backend configuration if necessary.';
        }

        return 'Validate the FreePBX base URL, OAuth credentials, and API endpoint configuration.';
    }
}
