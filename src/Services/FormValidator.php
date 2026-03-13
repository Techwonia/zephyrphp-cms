<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\FormField;
use ZephyrPHP\Validation\Validator;

class FormValidator
{
    /**
     * Build validation rules array from form field definitions.
     *
     * @param FormField[] $fields
     * @return array<string, string> Keyed by field slug
     */
    public function buildRules(array $fields): array
    {
        $rules = [];

        foreach ($fields as $field) {
            if ($field->isDisplayOnly()) {
                continue;
            }

            $fieldRules = [];

            // Add required rule if field is required
            if ($field->isRequired()) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }

            // Add type-specific validation
            switch ($field->getType()) {
                case 'email':
                    $fieldRules[] = 'email';
                    break;
                case 'url':
                    $fieldRules[] = 'url';
                    break;
                case 'number':
                case 'range':
                    $fieldRules[] = 'numeric';
                    break;
                case 'phone':
                    $fieldRules[] = 'phone';
                    break;
                case 'date':
                    $fieldRules[] = 'date';
                    break;
                case 'select':
                case 'radio':
                    $choices = $field->getChoices();
                    if (!empty($choices)) {
                        $values = array_column($choices, 'value');
                        $fieldRules[] = 'in:' . implode(',', $values);
                    }
                    break;
            }

            // Append any custom validation rules defined on the field
            $customValidation = $field->getValidation();
            if ($customValidation) {
                $customParts = explode('|', $customValidation);
                foreach ($customParts as $part) {
                    $ruleName = explode(':', $part)[0];
                    // Avoid duplicating rules already added above
                    $existing = array_map(fn($r) => explode(':', $r)[0], $fieldRules);
                    if (!in_array($ruleName, $existing, true)) {
                        $fieldRules[] = $part;
                    }
                }
            }

            $rules[$field->getSlug()] = implode('|', $fieldRules);
        }

        return $rules;
    }

    /**
     * Validate form submission data against field rules.
     *
     * @param array $data Input data
     * @param array $rules Validation rules
     * @param array $messages Custom error messages
     * @return Validator
     */
    public function validate(array $data, array $rules, array $messages = []): Validator
    {
        return Validator::make($data, $rules, $messages);
    }
}
