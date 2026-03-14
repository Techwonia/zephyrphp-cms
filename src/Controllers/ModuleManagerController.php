<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;
use ZephyrPHP\Module\ModuleManager;

class ModuleManagerController extends Controller
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

        $modules = $this->getModulesInfo();

        return $this->render('cms::system/modules', [
            'modules' => $modules,
            'user' => Auth::user(),
        ]);
    }

    public function toggle(string $name): void
    {
        $this->requirePermission('settings.edit');

        // Prevent disabling core modules
        $coreModules = ['session', 'validation', 'view'];
        if (in_array($name, $coreModules, true)) {
            $this->flash('errors', ['Cannot disable core module \'' . $name . '\'.']);
            $this->redirect('/cms/system/modules');
            return;
        }

        try {
            $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
            $configPath = $basePath . '/config/modules.php';

            $config = file_exists($configPath) ? require $configPath : [];

            if (isset($config[$name]) && $config[$name]) {
                $config[$name] = false;
                $action = 'disabled';
            } else {
                $config[$name] = true;
                $action = 'enabled';
            }

            $this->writeConfigFile($configPath, $config);

            $this->flash('success', 'Module \'' . $name . '\' ' . $action . '. Restart the application for changes to take effect.');
        } catch (\Throwable $e) {
            $this->flash('errors', ['Failed to toggle module: ' . $e->getMessage()]);
        }

        $this->redirect('/cms/system/modules');
    }

    private function getModulesInfo(): array
    {
        $modules = [];
        $manager = ModuleManager::getInstance();
        $enabledModules = $manager->getEnabled();

        // Known framework modules
        $knownModules = [
            'session' => ['description' => 'Session management with file/database drivers', 'core' => true],
            'validation' => ['description' => 'Form validation with extensible rules', 'core' => true],
            'view' => ['description' => 'Twig template engine integration', 'core' => true],
            'auth' => ['description' => 'Authentication with login, register, password reset', 'core' => false],
            'authorization' => ['description' => 'Role-based access control and policies', 'core' => false],
            'database' => ['description' => 'Doctrine DBAL database abstraction layer', 'core' => false],
            'cache' => ['description' => 'Caching with file, database, and memory drivers', 'core' => false],
            'queue' => ['description' => 'Background job processing', 'core' => false],
            'mail' => ['description' => 'Email sending via SMTP or PHP mail()', 'core' => false],
            'api' => ['description' => 'REST API framework with rate limiting', 'core' => false],
        ];

        // Merge known with enabled
        $allModules = array_unique(array_merge(array_keys($knownModules), $enabledModules));
        sort($allModules);

        foreach ($allModules as $name) {
            $info = $knownModules[$name] ?? ['description' => '', 'core' => false];
            $modules[] = [
                'name' => $name,
                'description' => $info['description'],
                'enabled' => in_array($name, $enabledModules, true),
                'core' => $info['core'],
            ];
        }

        return $modules;
    }

    private function writeConfigFile(string $path, array $config): void
    {
        $content = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        file_put_contents($path, $content, LOCK_EX);
    }
}
