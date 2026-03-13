<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\Form;
use ZephyrPHP\Cms\Models\FormField;
use ZephyrPHP\Cms\Models\FormStep;

class FormTemplateRegistry
{
    private string $templatesDir;

    public function __construct()
    {
        $this->templatesDir = __DIR__ . '/../Templates/';
    }

    /**
     * Load all available form templates.
     *
     * @return array<string, array>
     */
    public function getAll(): array
    {
        $templates = [];
        $files = glob($this->templatesDir . '*.json');

        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            $data = $this->loadJsonFile($file);
            if ($data !== null && isset($data['slug'])) {
                $templates[$data['slug']] = $data;
            }
        }

        return $templates;
    }

    /**
     * Load a specific template by slug.
     */
    public function get(string $slug): ?array
    {
        $slug = $this->sanitizeSlug($slug);
        $file = $this->templatesDir . $slug . '.json';

        if (!is_file($file)) {
            return null;
        }

        return $this->loadJsonFile($file);
    }

    /**
     * Create a Form (with FormFields and FormSteps) from a template and persist to DB.
     */
    public function createFormFromTemplate(string $templateSlug): Form
    {
        $template = $this->get($templateSlug);

        if ($template === null) {
            throw new \InvalidArgumentException("Form template '{$templateSlug}' not found.");
        }

        $form = new Form();
        $form->setName($template['name']);
        $form->setSlug($this->generateUniqueSlug($template['slug']));
        $form->setDescription($template['description'] ?? null);
        $form->setIsMultiStep($template['is_multi_step'] ?? false);
        $form->setTemplateSlug($templateSlug);
        $form->setSettings($template['settings'] ?? []);
        $form->setStatus('draft');
        $form->save();

        // Create steps if multi-step
        $stepMap = [];
        if (!empty($template['steps'])) {
            foreach ($template['steps'] as $stepData) {
                $step = new FormStep();
                $step->setForm($form);
                $step->setStepNumber($stepData['step_number']);
                $step->setTitle($stepData['title'] ?? null);
                $step->setDescription($stepData['description'] ?? null);
                $step->save();
                $stepMap[$stepData['step_number']] = $step;
            }
        }

        // Create fields
        foreach ($template['fields'] as $index => $fieldData) {
            $field = new FormField();
            $field->setForm($form);
            $field->setSlug($fieldData['slug']);
            $field->setLabel($fieldData['label']);
            $field->setType($fieldData['type']);
            $field->setPlaceholder($fieldData['placeholder'] ?? null);
            $field->setDefaultValue($fieldData['default_value'] ?? null);
            $field->setValidation($fieldData['validation'] ?? null);
            $field->setOptions($fieldData['options'] ?? null);
            $field->setIsRequired($fieldData['is_required'] ?? false);
            $field->setSortOrder($index);

            // Link to step if applicable
            $stepNumber = $fieldData['step_number'] ?? null;
            if ($stepNumber !== null && isset($stepMap[$stepNumber])) {
                $field->setStepId($stepMap[$stepNumber]->getId());
            }

            $field->save();
        }

        return $form;
    }

    /**
     * Safely load and decode a JSON template file.
     */
    private function loadJsonFile(string $path): ?array
    {
        $realPath = realpath($path);

        // Ensure the file is within the templates directory
        if ($realPath === false || !str_starts_with($realPath, realpath($this->templatesDir))) {
            return null;
        }

        $contents = file_get_contents($realPath);
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * Sanitize a slug to prevent path traversal.
     */
    private function sanitizeSlug(string $slug): string
    {
        return preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));
    }

    /**
     * Generate a unique slug by appending a suffix if the slug already exists.
     */
    private function generateUniqueSlug(string $baseSlug): string
    {
        $slug = $baseSlug;
        $counter = 1;

        while (Form::findOneBy(['slug' => $slug]) !== null) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
