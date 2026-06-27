<?php

namespace App\Http\Controllers;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Request;
use App\Http\ViewComposers\PortalComposer;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MijnmotorController extends BaseController
{
    protected $entity_type = Company::class;

    /**
     * Get OAuth endpoints from OpenID Connect discovery document
     *
     * @param  string  $oauthDomain
     * @param  int  $companyId
     * @param  bool  $useCache
     * @return array|null
     */
    private function getOAuthEndpoints($oauthDomain, $companyId, $useCache = true)
    {
        $cacheKey = "mijnmotor_discovery_{$companyId}";

        // Check if we have cached discovery document (if using cache)
        if ($useCache) {
            $cachedDiscovery = Cache::get($cacheKey);
            if ($cachedDiscovery) {
                return $cachedDiscovery;
            }
        }

        // Try to fetch OpenID Connect discovery document
        $discoveryUrl = rtrim($oauthDomain, '/') . '/.well-known/openid-configuration';

        try {
            $response = Http::get($discoveryUrl);

            if ($response->successful()) {
                $data = $response->json();
                $endpoints = [
                    'token_endpoint' => $data['token_endpoint'] ?? null,
                ];

                // Cache discovery document for 1 hour (it rarely changes)
                // Only cache if using cache
                if ($useCache) {
                    Cache::put($cacheKey, $endpoints, 3600);
                }

                return $endpoints;
            }
        } catch (\Throwable $e) {
            // Discovery failed, will use fallback
        }

        // Fallback to default endpoints
        $defaultEndpoints = [
            'token_endpoint' => rtrim($oauthDomain, '/') . '/oauth/token',
        ];

        // Cache default endpoints for a shorter time (5 minutes)
        // Only cache if using cache
        if ($useCache) {
            Cache::put($cacheKey, $defaultEndpoints, 300);
        }

        return $defaultEndpoints;
    }

    /**
     * Get or retrieve OAuth access token with caching
     *
     * @param  string  $oauthDomain
     * @param  string  $oauthClientId
     * @param  string  $oauthClientSecret
     * @param  int  $companyId
     * @param  bool  $useCache
     * @return string|null
     */
    private function getOAuthToken($oauthDomain, $oauthClientId, $oauthClientSecret, $companyId, $useCache = true)
    {
        $cacheKey = "mijnmotor_token_{$companyId}";

        // Check if we have a cached token (if using cache)
        if ($useCache) {
            $cachedToken = Cache::get($cacheKey);
            if ($cachedToken) {
                return $cachedToken;
            }
        }

        // Get OAuth endpoints from discovery or fallback
        $endpoints = $this->getOAuthEndpoints($oauthDomain, $companyId, $useCache);
        $tokenUrl = $endpoints['token_endpoint'] ?? rtrim($oauthDomain, '/') . '/oauth/token';

        try {
            $response = Http::asForm()->post($tokenUrl, [
                'grant_type' => 'client_credentials',
                'client_id' => $oauthClientId,
                'client_secret' => $oauthClientSecret,
                'scope' => 'any:subscriptions.read'
            ]);

            if ($response->failed()) {
                return null;
            }

            $data = $response->json();
            $accessToken = $data['access_token'] ?? null;

            if (!$accessToken) {
                return null;
            }

            // Cache the token with expiration (default to 3600 seconds if not provided)
            // Only cache if using cache
            if ($useCache) {
                $expiresIn = $data['expires_in'] ?? 3600;
                // Subtract a small buffer (5 minutes) to ensure we refresh before expiration
                $cacheTtl = max($expiresIn - 300, 60);

                Cache::put($cacheKey, $accessToken, $cacheTtl);
            }

            return $accessToken;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get subscriptions for a client from MijnMotor API
     *
     * @param  Request  $request
     * @param  Client  $client
     * @return JsonResponse
     */
    public function getSubscriptions(Request $request, Client $client): JsonResponse
    {
        $company = auth()->user()->company;

        // Check if module is enabled
        if (!($company->enabled_modules & PortalComposer::MODULE_MIJNMOTOR)) {
            return response()->json(['message' => 'Module not enabled'], 403);
        }

        // Get OAuth credentials from company settings
        $oauthDomain = $company->settings->mijnmotor_oauth_domain;
        $oauthClientId = $company->settings->mijnmotor_oauth_client_identifier;
        $oauthClientSecret = $company->settings->mijnmotor_oauth_client_secret;

        // Validate credentials are set
        if (empty($oauthDomain) || empty($oauthClientId) || empty($oauthClientSecret)) {
            return response()->json(['message' => 'OAuth credentials not configured'], 400);
        }

        // Get OAuth token (cached or new)
        $accessToken = $this->getOAuthToken($oauthDomain, $oauthClientId, $oauthClientSecret, $company->id);

        if (!$accessToken) {
            return response()->json(['message' => 'Failed to obtain OAuth token'], 500);
        }

        // Fetch subscriptions from MijnMotor API using client's hashed ID
        $apiUrl = rtrim($oauthDomain, '/') . "/api/subscriptions/{$client->hashed_id}";

        try {
            $apiResponse = Http::withToken($accessToken)
                ->get($apiUrl);

            if ($apiResponse->failed()) {
                // Clear cached token on failure (might be expired)
                Cache::forget("mijnmotor_token_{$company->id}");
                return response()->json(['message' => 'API request failed'], $apiResponse->status());
            }

            // Return the MijnMotor API response as-is
            return response()->json($apiResponse->json());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'API request error'], 500);
        }
    }

    /**
     * Test OAuth token acquisition for MijnMotor
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function testToken(Request $request): JsonResponse
    {
        $company = auth()->user()->company;

        // Check if module is enabled
        if (!($company->enabled_modules & PortalComposer::MODULE_MIJNMOTOR)) {
            return response()->json(['message' => 'Module not enabled'], 403);
        }

        // Validate request input
        $validated = $request->validate([
            'mijnmotor_oauth_domain' => 'sometimes|filled|url',
            'mijnmotor_oauth_client_identifier' => 'sometimes|filled|string',
            'mijnmotor_oauth_client_secret' => 'sometimes|filled|string',
        ]);

        // Get OAuth credentials from request or fall back to company settings
        $oauthDomain = $validated['mijnmotor_oauth_domain'] ?? $company->settings->mijnmotor_oauth_domain;
        $oauthClientId = $validated['mijnmotor_oauth_client_identifier'] ?? $company->settings->mijnmotor_oauth_client_identifier;
        $oauthClientSecret = $validated['mijnmotor_oauth_client_secret'] ?? $company->settings->mijnmotor_oauth_client_secret;

        // Try to obtain a new token without using cache
        $accessToken = $this->getOAuthToken($oauthDomain, $oauthClientId, $oauthClientSecret, $company->id, false);

        if (!$accessToken) {
            return response()->json(['message' => 'Failed to obtain OAuth token. Please check your credentials and OAuth domain configuration.'], 500);
        }

        return response()->json(['message' => 'OAuth token obtained successfully']);
    }
}
