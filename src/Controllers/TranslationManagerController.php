<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Services\PermissionService;

class TranslationManagerController extends Controller
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

        $langPath = $this->getLangPath();
        $locales = $this->getLocales($langPath);
        $selectedLocale = $this->input('locale', $locales[0] ?? 'en');
        $selectedGroup = $this->input('group', '');
        $search = $this->input('search', '');

        $groups = $this->getGroups($langPath, $selectedLocale);
        $translations = [];

        if ($selectedGroup !== '') {
            $translations = $this->loadTranslations($langPath, $selectedLocale, $selectedGroup);

            if ($search !== '') {
                $translations = array_filter($translations, function ($value, $key) use ($search) {
                    return stripos($key, $search) !== false || stripos((string) $value, $search) !== false;
                }, ARRAY_FILTER_USE_BOTH);
            }
        }

        // Load comparison locale
        $compareLocale = $this->input('compare', '');
        $compareTranslations = [];
        if ($compareLocale !== '' && $selectedGroup !== '') {
            $compareTranslations = $this->loadTranslations($langPath, $compareLocale, $selectedGroup);
        }

        return $this->render('cms::system/translations', [
            'locales' => $locales,
            'selectedLocale' => $selectedLocale,
            'groups' => $groups,
            'selectedGroup' => $selectedGroup,
            'translations' => $translations,
            'compareLocale' => $compareLocale,
            'compareTranslations' => $compareTranslations,
            'search' => $search,
            'user' => Auth::user(),
        ]);
    }

    public function update(): void
    {
        $this->requirePermission('settings.edit');

        $locale = $this->input('locale', '');
        $group = $this->input('group', '');
        $keys = $this->input('keys', []);
        $values = $this->input('values', []);

        if ($locale === '' || $group === '' || !is_array($keys) || !is_array($values)) {
            $this->flash('errors', ['Invalid request.']);
            $this->redirect('/cms/system/translations');
            return;
        }

        // Validate locale name (prevent directory traversal)
        if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale)) {
            $this->flash('errors', ['Invalid locale.']);
            $this->redirect('/cms/system/translations');
            return;
        }

        $langPath = $this->getLangPath();
        $dir = $langPath . '/' . $locale;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Build translations array
        $translations = [];
        foreach ($keys as $i => $key) {
            if (isset($values[$i]) && $key !== '') {
                $translations[$key] = $values[$i];
            }
        }

        // Save as PHP file
        $filePath = $dir . '/' . $group . '.php';
        $content = "<?php\n\nreturn " . var_export($translations, true) . ";\n";
        file_put_contents($filePath, $content, LOCK_EX);

        $this->flash('success', "Translations for '{$group}' ({$locale}) saved.");
        $this->redirect('/cms/system/translations?locale=' . $locale . '&group=' . $group);
    }

    public function addKey(): void
    {
        $this->requirePermission('settings.edit');

        $locale = $this->input('locale', '');
        $group = $this->input('group', '');
        $key = trim($this->input('new_key', ''));
        $value = trim($this->input('new_value', ''));

        if ($locale === '' || $group === '' || $key === '') {
            $this->flash('errors', ['Key is required.']);
            $this->redirect('/cms/system/translations?locale=' . $locale . '&group=' . $group);
            return;
        }

        $langPath = $this->getLangPath();
        $translations = $this->loadTranslations($langPath, $locale, $group);
        $translations[$key] = $value;

        $dir = $langPath . '/' . $locale;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filePath = $dir . '/' . $group . '.php';
        $content = "<?php\n\nreturn " . var_export($translations, true) . ";\n";
        file_put_contents($filePath, $content, LOCK_EX);

        $this->flash('success', "Key '{$key}' added.");
        $this->redirect('/cms/system/translations?locale=' . $locale . '&group=' . $group);
    }

    public function createGroup(): void
    {
        $this->requirePermission('settings.edit');

        $locale = $this->input('locale', '');
        $group = trim($this->input('new_group', ''));

        if ($locale === '' || $group === '') {
            $this->flash('errors', ['Group name is required.']);
            $this->redirect('/cms/system/translations');
            return;
        }

        // Validate group name
        if (!preg_match('/^[a-z0-9_-]+$/', $group)) {
            $this->flash('errors', ['Group name can only contain lowercase letters, numbers, hyphens, and underscores.']);
            $this->redirect('/cms/system/translations?locale=' . $locale);
            return;
        }

        $langPath = $this->getLangPath();
        $dir = $langPath . '/' . $locale;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filePath = $dir . '/' . $group . '.php';
        if (!file_exists($filePath)) {
            file_put_contents($filePath, "<?php\n\nreturn [];\n", LOCK_EX);
        }

        $this->flash('success', "Translation group '{$group}' created.");
        $this->redirect('/cms/system/translations?locale=' . $locale . '&group=' . $group);
    }

    public function progress(): string
    {
        $this->requirePermission('settings.view');

        $langPath = $this->getLangPath();
        $locales = $this->getLocales($langPath);

        if (empty($locales)) {
            return $this->render('cms::system/translation-progress', [
                'locales' => [],
                'progress' => [],
                'missing' => [],
                'defaultLocale' => '',
                'user' => Auth::user(),
            ]);
        }

        $defaultLocale = $locales[0]; // First locale is the reference
        $defaultGroups = $this->getGroups($langPath, $defaultLocale);

        // Count keys per locale per group
        $progress = [];
        $missing = [];

        foreach ($locales as $locale) {
            $groups = $this->getGroups($langPath, $locale);
            $totalKeys = 0;
            $translatedKeys = 0;

            foreach ($defaultGroups as $group) {
                $defaultTranslations = $this->loadTranslations($langPath, $defaultLocale, $group);
                $localeTranslations = $this->loadTranslations($langPath, $locale, $group);
                $defaultCount = count($defaultTranslations);
                $localeCount = 0;

                foreach ($defaultTranslations as $key => $val) {
                    $totalKeys++;
                    if (isset($localeTranslations[$key]) && $localeTranslations[$key] !== '') {
                        $translatedKeys++;
                        $localeCount++;
                    } else {
                        $missing[$locale][$group][] = $key;
                    }
                }
            }

            $pct = $totalKeys > 0 ? round(($translatedKeys / $totalKeys) * 100) : 0;
            $progress[$locale] = [
                'total' => $totalKeys,
                'translated' => $translatedKeys,
                'missing' => $totalKeys - $translatedKeys,
                'percentage' => $pct,
            ];
        }

        return $this->render('cms::system/translation-progress', [
            'locales' => $locales,
            'progress' => $progress,
            'missing' => $missing,
            'defaultLocale' => $defaultLocale,
            'user' => Auth::user(),
        ]);
    }

    private function getLangPath(): string
    {
        $basePath = defined('BASE_PATH') ? BASE_PATH : getcwd();
        return $basePath . '/lang';
    }

    private function getLocales(string $langPath): array
    {
        if (!is_dir($langPath)) {
            return [];
        }

        $locales = [];
        foreach (scandir($langPath) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($langPath . '/' . $entry) && preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $entry)) {
                $locales[] = $entry;
            }
        }

        sort($locales);
        return $locales;
    }

    private function getGroups(string $langPath, string $locale): array
    {
        $dir = $langPath . '/' . $locale;
        if (!is_dir($dir)) {
            return [];
        }

        $groups = [];
        foreach (glob($dir . '/*.php') as $file) {
            $groups[] = basename($file, '.php');
        }
        foreach (glob($dir . '/*.json') as $file) {
            $name = basename($file, '.json');
            if (!in_array($name, $groups, true)) {
                $groups[] = $name;
            }
        }

        sort($groups);
        return $groups;
    }

    private function loadTranslations(string $langPath, string $locale, string $group): array
    {
        // Try PHP file
        $phpFile = $langPath . '/' . $locale . '/' . $group . '.php';
        if (file_exists($phpFile)) {
            $data = require $phpFile;
            return is_array($data) ? $this->flattenArray($data) : [];
        }

        // Try JSON file
        $jsonFile = $langPath . '/' . $locale . '/' . $group . '.json';
        if (file_exists($jsonFile)) {
            $data = json_decode(file_get_contents($jsonFile), true);
            return is_array($data) ? $this->flattenArray($data) : [];
        }

        return [];
    }

    private function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $fullKey = $prefix ? $prefix . '.' . $key : $key;
            if (is_array($value)) {
                $result = array_merge($result, $this->flattenArray($value, $fullKey));
            } else {
                $result[$fullKey] = (string) $value;
            }
        }
        return $result;
    }
}
