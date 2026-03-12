<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\OAuth\OAuthClient;
use ZephyrPHP\OAuth\OAuthManager;
use ZephyrPHP\Cms\Services\PermissionService;

/**
 * Admin controller for managing OAuth 2.0 clients (external app registrations).
 */
class OAuthClientController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    private function requirePermission(string $permission): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!PermissionService::can($permission)) {
            $this->flash('errors', ['You do not have permission to perform this action.']);
            $this->redirect('/cms');
        }
    }

    /**
     * GET /cms/oauth-clients — List all registered OAuth clients.
     */
    public function index(): string
    {
        $this->requirePermission('apps.manage');

        $clients = OAuthClient::findAll();

        return $this->render('cms::oauth/clients', [
            'clients' => $clients,
            'scopes' => OAuthManager::SCOPES,
            'user' => Auth::user(),
        ]);
    }

    /**
     * POST /cms/oauth-clients — Register a new OAuth client.
     */
    public function store(): void
    {
        $this->requirePermission('apps.manage');

        $name = trim($this->input('name', ''));
        $redirectUri = trim($this->input('redirect_uri', ''));
        $scopes = $this->input('scopes', []);

        if (empty($name)) {
            $this->flash('errors', ['App name is required.']);
            $this->redirect('/cms/oauth-clients');
            return;
        }

        if (empty($redirectUri) || !filter_var($redirectUri, FILTER_VALIDATE_URL)) {
            $this->flash('errors', ['A valid redirect URI is required.']);
            $this->redirect('/cms/oauth-clients');
            return;
        }

        if (!is_array($scopes)) {
            $scopes = array_filter(explode(' ', $scopes));
        }

        // Validate scopes
        $validScopes = [];
        foreach ($scopes as $scope) {
            if (isset(OAuthManager::SCOPES[$scope])) {
                $validScopes[] = $scope;
            }
        }

        try {
            $client = OAuthClient::create($name, $redirectUri, $validScopes);

            // Show the plain secret once
            $this->flash('success', "App \"{$name}\" registered. Client ID: {$client->getClientId()}");
            $this->flash('client_secret', $client->_plainSecret);
        } catch (\Exception $e) {
            $this->flash('errors', ['Failed to create OAuth client: ' . $e->getMessage()]);
        }

        $this->redirect('/cms/oauth-clients');
    }

    /**
     * POST /cms/oauth-clients/{id}/delete — Delete an OAuth client.
     */
    public function destroy(string $id): void
    {
        $this->requirePermission('apps.manage');

        $client = OAuthClient::findById((int) $id);
        if (!$client) {
            $this->flash('errors', ['OAuth client not found.']);
            $this->redirect('/cms/oauth-clients');
            return;
        }

        // Revoke all tokens
        $oauth = new OAuthManager();
        $oauth->revokeClientTokens($client->getClientId());

        $name = $client->getName();
        $client->delete();

        $this->flash('success', "OAuth client \"{$name}\" has been removed and all tokens revoked.");
        $this->redirect('/cms/oauth-clients');
    }
}
