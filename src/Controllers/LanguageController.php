<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\Language;
use ZephyrPHP\Cms\Services\PermissionService;

class LanguageController extends Controller
{
    private function requireAccess(): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!PermissionService::can('cms.settings')) {
            $this->flash('errors', ['auth' => 'Access denied.']);
            $this->redirect('/cms');
        }
    }

    /**
     * List all languages.
     */
    public function index(): string
    {
        $this->requireAccess();

        $languages = Language::findBy([], ['sortOrder' => 'ASC']);

        return $this->render('cms::languages/index', [
            'languages' => $languages,
            'user' => Auth::user(),
        ]);
    }

    /**
     * Store a new language.
     */
    public function store(): void
    {
        $this->requireAccess();

        $code = strtolower(trim($this->input('code', '')));
        $name = trim($this->input('name', ''));
        $nativeName = trim($this->input('native_name', ''));
        $isDefault = $this->boolean('is_default');

        $errors = [];
        if (empty($code) || !preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $code)) {
            $errors['code'] = 'Language code must be a valid locale (e.g., en, fr, pt-br).';
        }
        if (empty($name)) {
            $errors['name'] = 'Language name is required.';
        }
        if (empty($nativeName)) {
            $nativeName = $name;
        }

        // Check code uniqueness
        if (empty($errors['code'])) {
            $existing = Language::findOneBy(['code' => $code]);
            if ($existing) {
                $errors['code'] = 'A language with this code already exists.';
            }
        }

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->flash('_old_input', ['code' => $code, 'name' => $name, 'native_name' => $nativeName]);
            $this->back();
            return;
        }

        // If this is set as default, unset any existing default
        if ($isDefault) {
            $this->clearDefaults();
        }

        // If no languages exist yet, make this the default
        $existingCount = Language::count();
        if ($existingCount === 0) {
            $isDefault = true;
        }

        $maxSort = 0;
        try {
            $all = Language::findAll();
            foreach ($all as $l) {
                $maxSort = max($maxSort, $l->getSortOrder());
            }
        } catch (\Exception $e) {}

        $language = new Language();
        $language->setCode($code);
        $language->setName($name);
        $language->setNativeName($nativeName);
        $language->setIsDefault($isDefault);
        $language->setIsActive(true);
        $language->setSortOrder($maxSort + 1);
        $language->save();

        $this->flash('success', "Language \"{$name}\" added.");
        $this->redirect('/cms/languages');
    }

    /**
     * Update a language.
     */
    public function update(string $id): void
    {
        $this->requireAccess();

        $language = Language::find((int) $id);
        if (!$language) {
            $this->flash('errors', ['language' => 'Language not found.']);
            $this->redirect('/cms/languages');
            return;
        }

        $name = trim($this->input('name', ''));
        $nativeName = trim($this->input('native_name', ''));
        $isActive = $this->boolean('is_active');
        $isDefault = $this->boolean('is_default');

        if (empty($name)) {
            $this->flash('errors', ['name' => 'Name is required.']);
            $this->back();
            return;
        }

        // Cannot deactivate default language
        if ($language->isDefault() && !$isActive) {
            $this->flash('errors', ['is_active' => 'Cannot deactivate the default language.']);
            $this->back();
            return;
        }

        if ($isDefault && !$language->isDefault()) {
            $this->clearDefaults();
        }

        $language->setName($name);
        $language->setNativeName($nativeName ?: $name);
        $language->setIsActive($isActive);
        $language->setIsDefault($isDefault);
        $language->save();

        $this->flash('success', "Language \"{$name}\" updated.");
        $this->redirect('/cms/languages');
    }

    /**
     * Delete a language.
     */
    public function destroy(string $id): void
    {
        $this->requireAccess();

        $language = Language::find((int) $id);
        if (!$language) {
            $this->flash('errors', ['language' => 'Language not found.']);
            $this->redirect('/cms/languages');
            return;
        }

        if ($language->isDefault()) {
            $this->flash('errors', ['language' => 'Cannot delete the default language.']);
            $this->redirect('/cms/languages');
            return;
        }

        // Delete all translations for this locale
        try {
            $conn = \ZephyrPHP\Database\EntityManager::getConnection();
            $conn->executeStatement(
                "DELETE FROM cms_translations WHERE locale = ?",
                [$language->getCode()]
            );
        } catch (\Exception $e) {
            // Non-critical
        }

        $name = $language->getName();
        $language->delete();

        $this->flash('success', "Language \"{$name}\" deleted.");
        $this->redirect('/cms/languages');
    }

    /**
     * Clear default flag from all languages.
     */
    private function clearDefaults(): void
    {
        try {
            $conn = \ZephyrPHP\Database\EntityManager::getConnection();
            $conn->executeStatement("UPDATE cms_languages SET is_default = 0 WHERE is_default = 1");
        } catch (\Exception $e) {
            // Non-critical
        }
    }
}
