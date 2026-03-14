<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\Language;
use ZephyrPHP\Cms\Models\Translation;
use ZephyrPHP\Database\EntityManager;

class TranslationService
{
    /**
     * Translatable field types (only text-based fields make sense to translate).
     */
    private const TRANSLATABLE_TYPES = ['text', 'textarea', 'richtext', 'slug', 'email', 'url'];

    /**
     * Get translations for an entry in a specific locale.
     *
     * @return array<string, string> field_slug => translated value
     */
    public static function getTranslations(string $tableName, string|int $entryId, string $locale): array
    {
        $translations = Translation::findBy([
            'tableName' => $tableName,
            'entryId' => (string) $entryId,
            'locale' => $locale,
        ]);

        $result = [];
        foreach ($translations as $t) {
            $result[$t->getFieldSlug()] = $t->getValue();
        }
        return $result;
    }

    /**
     * Save translations for an entry in a specific locale.
     *
     * @param array<string, string> $fieldValues field_slug => translated value
     */
    public static function saveTranslations(string $tableName, string|int $entryId, string $locale, array $fieldValues): void
    {
        $conn = EntityManager::getConnection();
        $entryIdStr = (string) $entryId;
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        foreach ($fieldValues as $fieldSlug => $value) {
            // Sanitize field slug
            if (!preg_match('/^[a-z0-9_]+$/', $fieldSlug)) {
                continue;
            }

            // Check if translation exists
            $existing = Translation::findOneBy([
                'tableName' => $tableName,
                'entryId' => $entryIdStr,
                'locale' => $locale,
                'fieldSlug' => $fieldSlug,
            ]);

            if ($existing) {
                $existing->setValue($value ?: null);
                $existing->save();
            } else {
                $translation = new Translation();
                $translation->setTableName($tableName);
                $translation->setEntryId($entryIdStr);
                $translation->setLocale($locale);
                $translation->setFieldSlug($fieldSlug);
                $translation->setValue($value ?: null);
                $translation->save();
            }
        }
    }

    /**
     * Resolve an entry with translations merged (translated fields override source).
     * Falls back to default locale if translation is missing.
     */
    public static function resolveEntry(array $entry, string $tableName, string $locale): array
    {
        $defaultLocale = self::getDefaultLocale();

        // If requesting the default locale, return as-is
        if ($locale === $defaultLocale) {
            return $entry;
        }

        $entryId = $entry['id'] ?? null;
        if (!$entryId) {
            return $entry;
        }

        $translations = self::getTranslations($tableName, $entryId, $locale);

        // Merge translations over the source entry (non-empty translations override)
        foreach ($translations as $fieldSlug => $value) {
            if ($value !== null && $value !== '') {
                $entry[$fieldSlug] = $value;
            }
        }

        // Add locale metadata
        $entry['_locale'] = $locale;
        $entry['_translated_fields'] = array_keys(array_filter($translations, fn($v) => $v !== null && $v !== ''));

        return $entry;
    }

    /**
     * Resolve multiple entries with translations.
     */
    public static function resolveEntries(array $entries, string $tableName, string $locale): array
    {
        $defaultLocale = self::getDefaultLocale();
        if ($locale === $defaultLocale) {
            return $entries;
        }

        return array_map(
            fn(array $entry) => self::resolveEntry($entry, $tableName, $locale),
            $entries
        );
    }

    /**
     * Delete all translations for an entry.
     */
    public static function deleteEntryTranslations(string $tableName, string|int $entryId): void
    {
        try {
            $conn = EntityManager::getConnection();
            $conn->executeStatement(
                "DELETE FROM cms_translations WHERE table_name = ? AND entry_id = ?",
                [$tableName, (string) $entryId]
            );
        } catch (\Exception $e) {
            // Non-critical
        }
    }

    /**
     * Get all available locales for an entry (locales that have at least one translation).
     */
    public static function getEntryLocales(string $tableName, string|int $entryId): array
    {
        try {
            $conn = EntityManager::getConnection();
            return $conn->createQueryBuilder()
                ->select('DISTINCT locale')
                ->from('cms_translations')
                ->where('table_name = :table')
                ->andWhere('entry_id = :entry')
                ->setParameter('table', $tableName)
                ->setParameter('entry', (string) $entryId)
                ->executeQuery()
                ->fetchFirstColumn();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get translation progress for an entry across all active locales.
     *
     * @return array<string, array{translated: int, total: int, percentage: int}>
     */
    public static function getTranslationProgress(string $tableName, string|int $entryId, array $translatableFields): array
    {
        $totalFields = count($translatableFields);
        if ($totalFields === 0) {
            return [];
        }

        $languages = self::getActiveLanguages();
        $defaultLocale = self::getDefaultLocale();
        $progress = [];

        foreach ($languages as $lang) {
            if ($lang->getCode() === $defaultLocale) {
                continue;
            }

            $translations = self::getTranslations($tableName, $entryId, $lang->getCode());
            $translated = 0;
            foreach ($translatableFields as $field) {
                $slug = is_object($field) ? $field->getSlug() : $field;
                if (!empty($translations[$slug])) {
                    $translated++;
                }
            }

            $progress[$lang->getCode()] = [
                'translated' => $translated,
                'total' => $totalFields,
                'percentage' => (int) round($translated / $totalFields * 100),
            ];
        }

        return $progress;
    }

    /**
     * Detect the current locale from the request.
     * Priority: ?locale= > URL prefix > Accept-Language > default
     */
    public static function detectLocale(): string
    {
        // 1. Query parameter
        $locale = $_GET['locale'] ?? '';
        if (!empty($locale) && self::isValidLocale($locale)) {
            return $locale;
        }

        // 2. URL prefix (e.g., /fr/blog or /es/products)
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($path, PHP_URL_PATH) ?: '/';
        $segments = explode('/', trim($path, '/'));
        if (!empty($segments[0]) && strlen($segments[0]) <= 5 && self::isValidLocale($segments[0])) {
            return $segments[0];
        }

        // 3. Accept-Language header
        $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if (!empty($acceptLanguage)) {
            $preferred = self::parseAcceptLanguage($acceptLanguage);
            foreach ($preferred as $lang) {
                if (self::isValidLocale($lang)) {
                    return $lang;
                }
            }
        }

        // 4. Default
        return self::getDefaultLocale();
    }

    /**
     * Get the default locale code.
     */
    public static function getDefaultLocale(): string
    {
        try {
            $default = Language::findOneBy(['isDefault' => true, 'isActive' => true]);
            if ($default) {
                return $default->getCode();
            }
        } catch (\Exception $e) {
            // Table may not exist yet
        }
        return 'en';
    }

    /**
     * Get all active languages.
     *
     * @return Language[]
     */
    public static function getActiveLanguages(): array
    {
        try {
            return Language::findBy(['isActive' => true], ['sortOrder' => 'ASC']);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Check if a locale code is valid (active language exists).
     */
    public static function isValidLocale(string $code): bool
    {
        if (empty($code) || !preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $code)) {
            return false;
        }

        try {
            $lang = Language::findOneBy(['code' => $code, 'isActive' => true]);
            return $lang !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get translatable fields from a collection's field list.
     */
    public static function getTranslatableFields(array $fields): array
    {
        return array_filter($fields, function ($field) {
            return in_array($field->getType(), self::TRANSLATABLE_TYPES);
        });
    }

    /**
     * Parse Accept-Language header into sorted locale list.
     */
    private static function parseAcceptLanguage(string $header): array
    {
        $langs = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if (empty($part)) continue;

            $bits = explode(';', $part);
            $code = trim($bits[0]);
            $q = 1.0;

            if (isset($bits[1])) {
                $qPart = trim($bits[1]);
                if (str_starts_with($qPart, 'q=')) {
                    $q = (float) substr($qPart, 2);
                }
            }

            // Normalize: en-US -> en
            $short = explode('-', $code)[0];
            $langs[$short] = max($langs[$short] ?? 0, $q);
        }

        arsort($langs);
        return array_keys($langs);
    }
}
