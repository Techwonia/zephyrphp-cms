<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Middleware;

use ZephyrPHP\Cms\Models\ApiKey;

class ApiAuthMiddleware
{
    /**
     * Authenticate API requests via API key (Bearer token or X-API-Key header).
     * Read-only endpoints (GET) can optionally bypass auth if collection allows public reads.
     */
    public function handle(): bool
    {
        $apiKey = $this->extractApiKey();

        if (!$apiKey) {
            $this->sendError('API key required. Use Authorization: Bearer <key> or X-API-Key header.', 401);
            return false;
        }

        $keyModel = ApiKey::findOneBy(['key' => hash('sha256', $apiKey)]);

        if (!$keyModel) {
            $this->sendError('Invalid API key.', 401);
            return false;
        }

        if (!$keyModel->isActive()) {
            $this->sendError('API key is inactive.', 403);
            return false;
        }

        // Check expiry
        if ($keyModel->getExpiresAt() && $keyModel->getExpiresAt() < new \DateTime()) {
            $this->sendError('API key has expired.', 403);
            return false;
        }

        // Update last used timestamp
        $keyModel->setLastUsedAt(new \DateTime());
        $keyModel->save();

        // Store key info for downstream use
        $_SERVER['CMS_API_KEY'] = $keyModel;

        return true;
    }

    private function extractApiKey(): ?string
    {
        // Check Authorization: Bearer <token>
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        // Check X-API-Key header
        $xApiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
        if ($xApiKey) {
            return $xApiKey;
        }

        // Query param intentionally removed — API keys in URLs leak via logs, referer headers, and browser history
        return null;
    }

    private function sendError(string $message, int $code): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['error' => $message]);
        exit;
    }
}
