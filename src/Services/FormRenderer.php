<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

use ZephyrPHP\Cms\Models\Form;
use ZephyrPHP\Cms\Models\FormField;
use ZephyrPHP\Security\Csrf;

class FormRenderer
{
    /**
     * Render a form by its slug.
     */
    public static function renderBySlug(string $slug, array $attrs = []): string
    {
        $form = Form::findOneBy(['slug' => $slug]);
        if (!$form || !$form->isActive()) {
            return '<!-- Form "' . htmlspecialchars($slug) . '" not found or inactive -->';
        }

        return (new self())->render($form, $attrs);
    }

    /**
     * Render a form to HTML.
     */
    public function render(Form $form, array $attrs = []): string
    {
        $fields = $form->getFields()->toArray();
        $settings = $form->getSettings();
        $cssClass = trim('fb-form fb-form--' . $form->getSlug() . ' ' . ($attrs['class'] ?? '') . ' ' . ($settings['custom_css_class'] ?? ''));
        $formId = $attrs['id'] ?? 'fb-' . $form->getSlug();
        $hasFile = $this->hasFileField($fields);

        $html = '<form method="POST" action="/forms/' . htmlspecialchars($form->getSlug()) . '/submit"';
        $html .= ' class="' . htmlspecialchars($cssClass) . '"';
        $html .= ' id="' . htmlspecialchars($formId) . '"';
        $html .= ' data-form-slug="' . htmlspecialchars($form->getSlug()) . '"';
        if ($hasFile) {
            $html .= ' enctype="multipart/form-data"';
        }
        $html .= '>';

        // CSRF token
        $html .= Csrf::getHiddenInput();

        // Honeypot field (bot detection)
        if ($settings['honeypot_enabled'] ?? true) {
            $html .= '<div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">';
            $html .= '<input type="text" name="_hp_field" tabindex="-1" autocomplete="off" value="">';
            $html .= '</div>';
        }

        // Flash errors and old input
        $errors = $this->getFlashErrors();
        $oldInput = $this->getOldInput();

        if ($form->isMultiStep()) {
            $html .= $this->renderMultiStep($form, $fields, $errors, $oldInput);
        } else {
            $html .= '<div class="fb-fields">';
            foreach ($fields as $field) {
                $html .= $this->renderField($field, $errors, $oldInput);
            }
            $html .= '</div>';
        }

        // Submit button
        $submitText = $settings['submit_button_text'] ?? 'Submit';
        if ($form->isMultiStep()) {
            $html .= '<div class="fb-nav">';
            $html .= '<button type="button" class="fb-btn fb-btn-prev" style="display:none">Back</button>';
            $html .= '<button type="button" class="fb-btn fb-btn-next">Next</button>';
            $html .= '<button type="submit" class="fb-btn fb-btn-submit" style="display:none">' . htmlspecialchars($submitText) . '</button>';
            $html .= '</div>';
        } else {
            $html .= '<div class="fb-actions">';
            $html .= '<button type="submit" class="fb-btn fb-btn-submit">' . htmlspecialchars($submitText) . '</button>';
            $html .= '</div>';
        }

        $html .= '</form>';

        // Success message flash
        $success = $this->getFlashSuccess();
        if ($success) {
            $html = '<div class="fb-success-message">' . htmlspecialchars($success) . '</div>' . $html;
        }

        return $html;
    }

    /**
     * Render a single form field.
     */
    private function renderField(FormField $field, array $errors, array $oldInput): string
    {
        $slug = $field->getSlug();
        $type = $field->getType();
        $label = $field->getLabel();
        $width = $field->getWidth();
        $hasError = isset($errors[$slug]);
        $value = $oldInput[$slug] ?? $field->getDefaultValue() ?? '';

        $wrapClass = 'fb-field fb-field--' . $type . ' ' . $width;
        if ($hasError) {
            $wrapClass .= ' fb-field--error';
        }

        $html = '<div class="' . htmlspecialchars($wrapClass) . '">';

        switch ($type) {
            case 'heading':
                $html .= '<h3 class="fb-heading">' . htmlspecialchars($label) . '</h3>';
                break;

            case 'paragraph':
                $html .= '<p class="fb-paragraph">' . htmlspecialchars($label) . '</p>';
                break;

            case 'divider':
                $html .= '<hr class="fb-divider">';
                break;

            case 'textarea':
                $html .= $this->renderLabel($field);
                $html .= '<textarea name="' . htmlspecialchars($slug) . '" id="fb-' . htmlspecialchars($slug) . '"';
                $html .= ' class="fb-input fb-textarea"';
                if ($field->getPlaceholder()) {
                    $html .= ' placeholder="' . htmlspecialchars($field->getPlaceholder()) . '"';
                }
                if ($field->isRequired()) {
                    $html .= ' required';
                }
                $html .= ' rows="4">' . htmlspecialchars($value) . '</textarea>';
                $html .= $this->renderError($errors, $slug);
                break;

            case 'select':
                $html .= $this->renderLabel($field);
                $html .= '<select name="' . htmlspecialchars($slug) . '" id="fb-' . htmlspecialchars($slug) . '"';
                $html .= ' class="fb-input fb-select"';
                if ($field->isRequired()) {
                    $html .= ' required';
                }
                $html .= '>';
                $html .= '<option value="">' . htmlspecialchars($field->getPlaceholder() ?? 'Select...') . '</option>';
                foreach ($field->getChoices() as $choice) {
                    $selected = ($value === ($choice['value'] ?? '')) ? ' selected' : '';
                    $html .= '<option value="' . htmlspecialchars($choice['value'] ?? '') . '"' . $selected . '>';
                    $html .= htmlspecialchars($choice['label'] ?? $choice['value'] ?? '');
                    $html .= '</option>';
                }
                $html .= '</select>';
                $html .= $this->renderError($errors, $slug);
                break;

            case 'radio':
                $html .= $this->renderLabel($field);
                $html .= '<div class="fb-radio-group">';
                foreach ($field->getChoices() as $choice) {
                    $checked = ($value === ($choice['value'] ?? '')) ? ' checked' : '';
                    $choiceId = 'fb-' . $slug . '-' . ($choice['value'] ?? '');
                    $html .= '<label class="fb-radio-label" for="' . htmlspecialchars($choiceId) . '">';
                    $html .= '<input type="radio" name="' . htmlspecialchars($slug) . '" id="' . htmlspecialchars($choiceId) . '"';
                    $html .= ' value="' . htmlspecialchars($choice['value'] ?? '') . '"' . $checked;
                    if ($field->isRequired()) {
                        $html .= ' required';
                    }
                    $html .= '> ' . htmlspecialchars($choice['label'] ?? $choice['value'] ?? '');
                    $html .= '</label>';
                }
                $html .= '</div>';
                $html .= $this->renderError($errors, $slug);
                break;

            case 'checkbox':
                $choices = $field->getChoices();
                if (!empty($choices)) {
                    // Multiple checkboxes
                    $html .= $this->renderLabel($field);
                    $html .= '<div class="fb-checkbox-group">';
                    $selectedValues = is_array($value) ? $value : ($value ? [$value] : []);
                    foreach ($choices as $choice) {
                        $checked = in_array($choice['value'] ?? '', $selectedValues) ? ' checked' : '';
                        $choiceId = 'fb-' . $slug . '-' . ($choice['value'] ?? '');
                        $html .= '<label class="fb-checkbox-label" for="' . htmlspecialchars($choiceId) . '">';
                        $html .= '<input type="checkbox" name="' . htmlspecialchars($slug) . '[]" id="' . htmlspecialchars($choiceId) . '"';
                        $html .= ' value="' . htmlspecialchars($choice['value'] ?? '') . '"' . $checked . '>';
                        $html .= ' ' . htmlspecialchars($choice['label'] ?? $choice['value'] ?? '');
                        $html .= '</label>';
                    }
                    $html .= '</div>';
                } else {
                    // Single checkbox (boolean toggle)
                    $checked = $value ? ' checked' : '';
                    $html .= '<label class="fb-checkbox-label" for="fb-' . htmlspecialchars($slug) . '">';
                    $html .= '<input type="checkbox" name="' . htmlspecialchars($slug) . '" id="fb-' . htmlspecialchars($slug) . '"';
                    $html .= ' value="1"' . $checked . '>';
                    $html .= ' ' . htmlspecialchars($label);
                    $html .= '</label>';
                }
                $html .= $this->renderError($errors, $slug);
                break;

            case 'file':
                $html .= $this->renderLabel($field);
                $html .= '<input type="file" name="' . htmlspecialchars($slug) . '" id="fb-' . htmlspecialchars($slug) . '"';
                $html .= ' class="fb-input fb-file"';
                $accept = $field->getOptions()['accept'] ?? '';
                if ($accept) {
                    $html .= ' accept="' . htmlspecialchars($accept) . '"';
                }
                if ($field->isRequired()) {
                    $html .= ' required';
                }
                $html .= '>';
                $html .= $this->renderError($errors, $slug);
                break;

            case 'hidden':
                $html .= '<input type="hidden" name="' . htmlspecialchars($slug) . '" value="' . htmlspecialchars($value) . '">';
                break;

            default:
                // text, email, number, date, phone, url, password, range, color
                $inputType = match ($type) {
                    'phone' => 'tel',
                    default => $type,
                };
                $html .= $this->renderLabel($field);
                $html .= '<input type="' . htmlspecialchars($inputType) . '" name="' . htmlspecialchars($slug) . '"';
                $html .= ' id="fb-' . htmlspecialchars($slug) . '"';
                $html .= ' class="fb-input"';
                $html .= ' value="' . htmlspecialchars($value) . '"';
                if ($field->getPlaceholder()) {
                    $html .= ' placeholder="' . htmlspecialchars($field->getPlaceholder()) . '"';
                }
                if ($field->isRequired()) {
                    $html .= ' required';
                }
                // Min/max for number/range
                $options = $field->getOptions() ?? [];
                if (isset($options['min'])) {
                    $html .= ' min="' . htmlspecialchars((string)$options['min']) . '"';
                }
                if (isset($options['max'])) {
                    $html .= ' max="' . htmlspecialchars((string)$options['max']) . '"';
                }
                if (isset($options['step'])) {
                    $html .= ' step="' . htmlspecialchars((string)$options['step']) . '"';
                }
                $html .= '>';
                $html .= $this->renderError($errors, $slug);
                break;
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Render multi-step form with step containers.
     */
    private function renderMultiStep(Form $form, array $fields, array $errors, array $oldInput): string
    {
        $steps = $form->getSteps()->toArray();
        $fieldsByStep = $form->getFieldsByStep();

        // Step indicator
        $html = '<div class="fb-step-indicator">';
        foreach ($steps as $i => $step) {
            $active = $i === 0 ? ' fb-step-dot--active' : '';
            $html .= '<div class="fb-step-dot' . $active . '" data-step="' . ($i + 1) . '">';
            $html .= '<span class="fb-step-number">' . ($i + 1) . '</span>';
            if ($step->getTitle()) {
                $html .= '<span class="fb-step-title">' . htmlspecialchars($step->getTitle()) . '</span>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';

        // Step containers
        foreach ($steps as $i => $step) {
            $display = $i === 0 ? '' : ' style="display:none"';
            $html .= '<div class="fb-step" data-step="' . ($i + 1) . '"' . $display . '>';
            if ($step->getTitle()) {
                $html .= '<h3 class="fb-step-heading">' . htmlspecialchars($step->getTitle()) . '</h3>';
            }
            if ($step->getDescription()) {
                $html .= '<p class="fb-step-desc">' . htmlspecialchars($step->getDescription()) . '</p>';
            }
            $html .= '<div class="fb-fields">';
            $stepFields = $fieldsByStep[$step->getId()] ?? [];
            foreach ($stepFields as $field) {
                $html .= $this->renderField($field, $errors, $oldInput);
            }
            $html .= '</div>';
            $html .= '</div>';
        }

        // Fields without a step (fallback — shown in step 1 area)
        $orphanFields = $fieldsByStep[0] ?? [];
        if (!empty($orphanFields) && empty($steps)) {
            $html .= '<div class="fb-fields">';
            foreach ($orphanFields as $field) {
                $html .= $this->renderField($field, $errors, $oldInput);
            }
            $html .= '</div>';
        }

        return $html;
    }

    private function renderLabel(FormField $field): string
    {
        $html = '<label class="fb-label" for="fb-' . htmlspecialchars($field->getSlug()) . '">';
        $html .= htmlspecialchars($field->getLabel());
        if ($field->isRequired()) {
            $html .= ' <span class="fb-required">*</span>';
        }
        $html .= '</label>';
        return $html;
    }

    private function renderError(array $errors, string $slug): string
    {
        if (isset($errors[$slug])) {
            $msg = is_array($errors[$slug]) ? ($errors[$slug][0] ?? '') : $errors[$slug];
            return '<div class="fb-error">' . htmlspecialchars($msg) . '</div>';
        }
        return '';
    }

    private function hasFileField(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($field->getType() === 'file') {
                return true;
            }
        }
        return false;
    }

    private function getFlashErrors(): array
    {
        try {
            $session = \ZephyrPHP\Session\Session::getInstance();
            return $session->getFlash('errors', []);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getOldInput(): array
    {
        try {
            $session = \ZephyrPHP\Session\Session::getInstance();
            return $session->getFlash('_old_input', []);
        } catch (\Exception $e) {
            return [];
        }
    }

    private function getFlashSuccess(): ?string
    {
        try {
            $session = \ZephyrPHP\Session\Session::getInstance();
            return $session->getFlash('success');
        } catch (\Exception $e) {
            return null;
        }
    }
}
