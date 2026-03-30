<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Traits\CmsAccessTrait;
use ZephyrPHP\Cms\Services\EnvFileManager;
use ZephyrPHP\Cms\Services\PermissionService;

class AuthSettingsController extends Controller
{
    use CmsAccessTrait;

    public function index(): string
    {
        $this->requirePermission('settings.view');

        $features = [
            'AUTH_REGISTRATION' => env('AUTH_REGISTRATION', 'true'),
            'AUTH_PASSWORD_RESET' => env('AUTH_PASSWORD_RESET', 'true'),
            'AUTH_EMAIL_VERIFICATION' => env('AUTH_EMAIL_VERIFICATION', 'false'),
            'AUTH_REMEMBER_ME' => env('AUTH_REMEMBER_ME', 'true'),
        ];

        $passwordPolicy = [
            'AUTH_PASSWORD_MIN_LENGTH' => env('AUTH_PASSWORD_MIN_LENGTH', '8'),
            'AUTH_PASSWORD_REQUIRE_UPPERCASE' => env('AUTH_PASSWORD_REQUIRE_UPPERCASE', 'false'),
            'AUTH_PASSWORD_REQUIRE_LOWERCASE' => env('AUTH_PASSWORD_REQUIRE_LOWERCASE', 'false'),
            'AUTH_PASSWORD_REQUIRE_NUMBERS' => env('AUTH_PASSWORD_REQUIRE_NUMBERS', 'false'),
            'AUTH_PASSWORD_REQUIRE_SYMBOLS' => env('AUTH_PASSWORD_REQUIRE_SYMBOLS', 'false'),
            'AUTH_PASSWORD_CHECK_PWNED' => env('AUTH_PASSWORD_CHECK_PWNED', 'false'),
        ];

        $rateLimiting = [
            'AUTH_LOGIN_MAX_ATTEMPTS' => env('AUTH_LOGIN_MAX_ATTEMPTS', '5'),
            'AUTH_LOGIN_DECAY_MINUTES' => env('AUTH_LOGIN_DECAY_MINUTES', '1'),
            'AUTH_RESET_MAX_ATTEMPTS' => env('AUTH_RESET_MAX_ATTEMPTS', '3'),
            'AUTH_RESET_DECAY_MINUTES' => env('AUTH_RESET_DECAY_MINUTES', '1'),
            'AUTH_REGISTER_MAX_ATTEMPTS' => env('AUTH_REGISTER_MAX_ATTEMPTS', '5'),
            'AUTH_REGISTER_DECAY_MINUTES' => env('AUTH_REGISTER_DECAY_MINUTES', '60'),
        ];

        $jwt = [
            'JWT_SECRET' => env('JWT_SECRET', '') ? '••••••••' : '',
            'JWT_ALGORITHM' => env('JWT_ALGORITHM', 'HS256'),
            'JWT_LIFETIME' => env('JWT_LIFETIME', '3600'),
            'JWT_REFRESH' => env('JWT_REFRESH', '2592000'),
        ];

        $session = [
            'SESSION_LIFETIME' => env('SESSION_LIFETIME', '120'),
            'SESSION_DRIVER' => env('SESSION_DRIVER', 'file'),
            'SESSION_DOMAIN' => env('SESSION_DOMAIN', ''),
            'SESSION_SECURE_COOKIE' => env('SESSION_SECURE_COOKIE', 'false'),
        ];

        return $this->render('cms::settings/auth', [
            'features' => $features,
            'passwordPolicy' => $passwordPolicy,
            'rateLimiting' => $rateLimiting,
            'jwt' => $jwt,
            'session' => $session,
            'user' => Auth::user(),
        ]);
    }

    public function update(): void
    {
        $this->requirePermission('settings.edit');

        $settings = [];

        // Feature toggles
        $toggles = [
            'AUTH_REGISTRATION', 'AUTH_PASSWORD_RESET', 'AUTH_EMAIL_VERIFICATION',
            'AUTH_REMEMBER_ME',
        ];
        foreach ($toggles as $key) {
            $settings[$key] = $this->input($key, 'false');
        }

        // Password policy
        $minLength = (int) $this->input('AUTH_PASSWORD_MIN_LENGTH', '8');
        $settings['AUTH_PASSWORD_MIN_LENGTH'] = (string) max(4, min(128, $minLength));

        $policyToggles = [
            'AUTH_PASSWORD_REQUIRE_UPPERCASE', 'AUTH_PASSWORD_REQUIRE_LOWERCASE',
            'AUTH_PASSWORD_REQUIRE_NUMBERS', 'AUTH_PASSWORD_REQUIRE_SYMBOLS',
            'AUTH_PASSWORD_CHECK_PWNED',
        ];
        foreach ($policyToggles as $key) {
            $settings[$key] = $this->input($key, 'false');
        }

        // Rate limiting
        $rateKeys = [
            'AUTH_LOGIN_MAX_ATTEMPTS', 'AUTH_LOGIN_DECAY_MINUTES',
            'AUTH_RESET_MAX_ATTEMPTS', 'AUTH_RESET_DECAY_MINUTES',
            'AUTH_REGISTER_MAX_ATTEMPTS', 'AUTH_REGISTER_DECAY_MINUTES',
        ];
        foreach ($rateKeys as $key) {
            $val = (int) $this->input($key, '5');
            $settings[$key] = (string) max(1, min(9999, $val));
        }

        // JWT
        $settings['JWT_ALGORITHM'] = trim($this->input('JWT_ALGORITHM', 'HS256'));
        $settings['JWT_LIFETIME'] = (string) max(60, (int) $this->input('JWT_LIFETIME', '3600'));
        $settings['JWT_REFRESH'] = (string) max(60, (int) $this->input('JWT_REFRESH', '2592000'));

        $jwtSecret = $this->input('JWT_SECRET', '');
        if ($jwtSecret !== '' && $jwtSecret !== '••••••••') {
            $settings['JWT_SECRET'] = $jwtSecret;
        }

        // Session
        $settings['SESSION_LIFETIME'] = (string) max(1, (int) $this->input('SESSION_LIFETIME', '120'));
        $settings['SESSION_DRIVER'] = trim($this->input('SESSION_DRIVER', 'file'));
        $settings['SESSION_DOMAIN'] = trim($this->input('SESSION_DOMAIN', ''));
        $settings['SESSION_SECURE_COOKIE'] = $this->input('SESSION_SECURE_COOKIE', 'false');

        if (!EnvFileManager::updateAndApply($settings)) {
            $this->flash('errors', ['.env file not found or not writable.']);
            $this->redirect(admin_url('settings/auth'));
            return;
        }

        $this->flash('success', 'Authentication settings updated successfully.');
        $this->redirect(admin_url('settings/auth'));
    }

}
