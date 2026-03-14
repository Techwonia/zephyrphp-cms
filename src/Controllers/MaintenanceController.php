<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;

class MaintenanceController extends Controller
{
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

    public function index(): string
    {
        $this->requirePermission('settings.view');

        $downFile = $this->getDownFilePath();
        $isDown = file_exists($downFile);
        $data = [];

        if ($isDown) {
            $content = file_get_contents($downFile);
            $data = json_decode($content, true) ?: [];
        }

        return $this->render('cms::system/maintenance', [
            'isDown' => $isDown,
            'data' => $data,
            'user' => Auth::user(),
        ]);
    }

    public function activate(): void
    {
        $this->requirePermission('settings.edit');

        $frameworkDir = $this->getFrameworkDir();
        if (!is_dir($frameworkDir)) {
            mkdir($frameworkDir, 0755, true);
        }

        $data = [
            'message' => trim($this->input('message', 'We are currently performing maintenance. Please check back soon.')),
            'time' => date('Y-m-d H:i:s'),
        ];

        $retry = trim($this->input('retry', ''));
        if ($retry !== '' && is_numeric($retry)) {
            $data['retry'] = (int) $retry;
        }

        $allowedIps = trim($this->input('allowed', ''));
        if ($allowedIps !== '') {
            $ips = array_filter(array_map('trim', explode("\n", $allowedIps)));
            $validIps = array_filter($ips, fn($ip) => filter_var($ip, FILTER_VALIDATE_IP));
            if (!empty($validIps)) {
                $data['allowed'] = array_values($validIps);
            }
        }

        $secret = trim($this->input('secret', ''));
        if ($secret !== '') {
            $data['secret'] = $secret;
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->getDownFilePath(), $json, LOCK_EX);

        $this->flash('success', 'Application is now in maintenance mode.');
        $this->redirect('/cms/system/maintenance');
    }

    public function deactivate(): void
    {
        $this->requirePermission('settings.edit');

        $downFile = $this->getDownFilePath();
        if (file_exists($downFile)) {
            unlink($downFile);
        }

        $this->flash('success', 'Application is now live.');
        $this->redirect('/cms/system/maintenance');
    }

    private function getDownFilePath(): string
    {
        return $this->getFrameworkDir() . '/down';
    }

    private function getFrameworkDir(): string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        return $basePath . '/storage/framework';
    }
}
