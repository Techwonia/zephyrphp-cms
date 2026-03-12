<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Api;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\OAuth\OAuthClient;
use ZephyrPHP\OAuth\OAuthManager;

/**
 * OAuth 2.0 Controller — handles authorization and token endpoints.
 *
 * Endpoints:
 *   GET  /oauth/authorize  — Show authorization consent screen
 *   POST /oauth/authorize  — User approves/denies, generates auth code
 *   POST /oauth/token      — Exchange code for tokens / refresh tokens
 *   POST /oauth/revoke     — Revoke tokens
 */
class OAuthController extends Controller
{
    private OAuthManager $oauth;

    public function __construct()
    {
        parent::__construct();
        $this->oauth = new OAuthManager();
    }

    /**
     * GET /oauth/authorize — Show consent screen.
     * Query params: client_id, redirect_uri, scope, state, response_type=code
     * Optional PKCE: code_challenge, code_challenge_method
     */
    public function authorize(): string
    {
        if (!Auth::check()) {
            // Store the full authorize URL to redirect back after login
            $_SESSION['_oauth_return'] = $_SERVER['REQUEST_URI'];
            $this->redirect('/login');
            return '';
        }

        $clientId = $_GET['client_id'] ?? '';
        $redirectUri = $_GET['redirect_uri'] ?? '';
        $scope = $_GET['scope'] ?? '';
        $state = $_GET['state'] ?? '';
        $responseType = $_GET['response_type'] ?? 'code';
        $codeChallenge = $_GET['code_challenge'] ?? '';
        $codeChallengeMethod = $_GET['code_challenge_method'] ?? 'S256';

        if ($responseType !== 'code') {
            return $this->render('cms::oauth/error', [
                'error' => 'Only response_type=code is supported.',
                'user' => Auth::user(),
            ]);
        }

        $client = OAuthClient::findByClientId($clientId);
        if (!$client || !$client->isActive()) {
            return $this->render('cms::oauth/error', [
                'error' => 'Unknown or inactive application.',
                'user' => Auth::user(),
            ]);
        }

        if ($client->getRedirectUri() !== $redirectUri) {
            return $this->render('cms::oauth/error', [
                'error' => 'Redirect URI does not match the registered application.',
                'user' => Auth::user(),
            ]);
        }

        $requestedScopes = array_filter(explode(' ', $scope));
        $validScopes = $this->oauth->validateScopes($requestedScopes, $client);

        return $this->render('cms::oauth/authorize', [
            'client' => $client,
            'scopes' => $validScopes,
            'scopeDescriptions' => OAuthManager::SCOPES,
            'state' => $state,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'user' => Auth::user(),
        ]);
    }

    /**
     * POST /oauth/authorize — User approves, generate auth code.
     */
    public function authorizeApprove(): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }

        $clientId = $this->input('client_id', '');
        $redirectUri = $this->input('redirect_uri', '');
        $scopes = $this->input('scopes', '');
        $state = $this->input('state', '');
        $codeChallenge = $this->input('code_challenge', '');
        $codeChallengeMethod = $this->input('code_challenge_method', 'S256');
        $approved = $this->input('approve', '') === '1';

        if (!$approved) {
            // User denied
            $separator = str_contains($redirectUri, '?') ? '&' : '?';
            $this->redirect($redirectUri . $separator . http_build_query([
                'error' => 'access_denied',
                'error_description' => 'The user denied the authorization request.',
                'state' => $state,
            ]));
            return;
        }

        $client = OAuthClient::findByClientId($clientId);
        if (!$client || $client->getRedirectUri() !== $redirectUri) {
            $this->flash('errors', ['Invalid OAuth request.']);
            $this->redirect('/cms');
            return;
        }

        $scopeArray = array_filter(explode(' ', $scopes));
        $user = Auth::user();

        $code = $this->oauth->createAuthCode(
            $clientId,
            $user->getId(),
            $scopeArray,
            $redirectUri,
            $codeChallenge ?: null,
            $codeChallengeMethod
        );

        $separator = str_contains($redirectUri, '?') ? '&' : '?';
        $this->redirect($redirectUri . $separator . http_build_query([
            'code' => $code,
            'state' => $state,
        ]));
    }

    /**
     * POST /oauth/token — Exchange auth code for tokens or refresh.
     */
    public function token(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');

        $grantType = $this->input('grant_type', '');

        if ($grantType === 'authorization_code') {
            $result = $this->oauth->exchangeCode(
                $this->input('code', ''),
                $this->input('client_id', ''),
                $this->input('client_secret', ''),
                $this->input('redirect_uri', ''),
                $this->input('code_verifier') ?: null,
            );
        } elseif ($grantType === 'refresh_token') {
            $result = $this->oauth->refreshAccessToken(
                $this->input('refresh_token', ''),
                $this->input('client_id', ''),
                $this->input('client_secret', ''),
            );
        } else {
            $result = [
                'error' => 'unsupported_grant_type',
                'error_description' => 'Only authorization_code and refresh_token grant types are supported.',
            ];
        }

        if (isset($result['error'])) {
            http_response_code(400);
        }

        echo json_encode($result);
    }

    /**
     * POST /oauth/revoke — Revoke all tokens for a client.
     */
    public function revoke(): void
    {
        header('Content-Type: application/json');

        $clientId = $this->input('client_id', '');
        $clientSecret = $this->input('client_secret', '');

        $client = OAuthClient::findByClientId($clientId);
        if (!$client || !$client->verifySecret($clientSecret)) {
            http_response_code(401);
            echo json_encode(['error' => 'invalid_client']);
            return;
        }

        $this->oauth->revokeClientTokens($clientId);
        echo json_encode(['success' => true]);
    }
}
