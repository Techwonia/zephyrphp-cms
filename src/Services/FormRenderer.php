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

        // Flash errors and old input
        $errors = $this->getFlashErrors();
        $oldInput = $this->getOldInput();
        $success = $this->getFlashSuccess();

        // Include form styles (once per page)
        $html = $this->getFormStyles();

        // Success message — hide form, show only success
        if ($success) {
            $html .= '<div class="fb-success-message">';
            $html .= '<div class="fb-success-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>';
            $html .= '<h3>Submitted Successfully</h3>';
            $html .= '<p>' . htmlspecialchars($success) . '</p>';
            $html .= '</div>';
            return $html;
        }

        // Form tag
        $html .= '<form method="POST" action="/forms/' . htmlspecialchars($form->getSlug()) . '/submit"';
        $html .= ' class="' . htmlspecialchars($cssClass) . '"';
        $html .= ' id="' . htmlspecialchars($formId) . '"';
        $html .= ' data-form-slug="' . htmlspecialchars($form->getSlug()) . '"';
        $html .= ' novalidate'; // We handle validation via JS
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

        // Global form-level error
        if (isset($errors['_form'])) {
            $msg = is_array($errors['_form']) ? ($errors['_form'][0] ?? $errors['_form']) : $errors['_form'];
            $html .= '<div class="fb-alert fb-alert-error" role="alert">' . htmlspecialchars((string)$msg) . '</div>';
        }

        // Build validation rules for client-side
        $validator = new FormValidator();
        $submittableFields = $form->getSubmittableFields();
        $validationRules = $validator->buildRules($submittableFields);

        if ($form->isMultiStep()) {
            $html .= $this->renderMultiStep($form, $fields, $errors, $oldInput, $validationRules);
        } else {
            $html .= '<div class="fb-fields">';
            foreach ($fields as $field) {
                $html .= $this->renderField($field, $errors, $oldInput, $validationRules);
            }
            $html .= '</div>';
        }

        // Submit button
        $html .= $this->renderSubmitButton($form, $settings);

        $html .= '</form>';

        // Include form JS (always)
        $html .= $this->getFormScript('form-submit.js');

        // Include multi-step JS if needed
        if ($form->isMultiStep()) {
            $html .= $this->getFormScript('form-multi-step.js');
        }

        return $html;
    }

    /**
     * Render the submit button with custom styling from settings.
     */
    private function renderSubmitButton(Form $form, array $settings): string
    {
        $submitText = $settings['submit_button_text'] ?? 'Submit';
        $btnClass = 'fb-btn fb-btn-submit';
        $btnStyle = '';

        // Size
        $size = $settings['submit_button_size'] ?? 'md';
        $btnClass .= ' fb-btn-' . htmlspecialchars($size);

        // Custom color
        if (!empty($settings['submit_button_color'])) {
            $color = htmlspecialchars($settings['submit_button_color']);
            $btnStyle .= "background:{$color};border-color:{$color};";
        }

        // Custom text color
        if (!empty($settings['submit_button_text_color'])) {
            $btnStyle .= 'color:' . htmlspecialchars($settings['submit_button_text_color']) . ';';
        }

        // Full width
        if (!empty($settings['submit_button_full_width'])) {
            $btnStyle .= 'width:100%;';
        }

        // Custom CSS class
        if (!empty($settings['submit_button_css_class'])) {
            $btnClass .= ' ' . htmlspecialchars($settings['submit_button_css_class']);
        }

        $styleAttr = $btnStyle ? ' style="' . $btnStyle . '"' : '';

        $isMultiStep = $form->isMultiStep();
        $wrapStyle = $isMultiStep ? ' style="display:none"' : '';

        $html = '<div class="fb-actions"' . $wrapStyle . '>';
        $html .= '<button type="submit" class="' . $btnClass . '"' . $styleAttr . '>';
        $html .= '<span class="fb-btn-text">' . htmlspecialchars($submitText) . '</span>';
        $html .= '<span class="fb-btn-spinner"></span>';
        $html .= '</button>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render a single form field.
     */
    private function renderField(FormField $field, array $errors, array $oldInput, array $validationRules = []): string
    {
        $slug = $field->getSlug();
        $type = $field->getType();
        $label = $field->getLabel();
        $width = $field->getWidth();
        $hasError = isset($errors[$slug]);
        $value = $oldInput[$slug] ?? $field->getDefaultValue() ?? '';
        $rules = $validationRules[$slug] ?? '';

        $wrapClass = 'fb-field fb-field--' . $type . ' ' . $width;
        if ($hasError) {
            $wrapClass .= ' fb-field--error';
        }

        $errorId = 'fb-error-' . htmlspecialchars($slug);
        $inputId = 'fb-' . htmlspecialchars($slug);
        $inputClass = 'fb-input' . ($hasError ? ' is-invalid' : '');
        $ariaAttrs = ' aria-describedby="' . $errorId . '"';
        if ($hasError) {
            $ariaAttrs .= ' aria-invalid="true"';
        }
        $rulesAttr = $rules ? ' data-rules="' . htmlspecialchars($rules) . '"' : '';

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
                $html .= '<textarea name="' . htmlspecialchars($slug) . '" id="' . $inputId . '"';
                $html .= ' class="' . $inputClass . ' fb-textarea"';
                $html .= $ariaAttrs . $rulesAttr;
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
                $html .= '<select name="' . htmlspecialchars($slug) . '" id="' . $inputId . '"';
                $html .= ' class="' . $inputClass . ' fb-select"';
                $html .= $ariaAttrs . $rulesAttr;
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
                $html .= '<div class="fb-radio-group"' . $rulesAttr . ' data-field="' . htmlspecialchars($slug) . '">';
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
                    $html .= $this->renderLabel($field);
                    $html .= '<div class="fb-checkbox-group"' . $rulesAttr . ' data-field="' . htmlspecialchars($slug) . '">';
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
                    $checked = $value ? ' checked' : '';
                    $html .= '<label class="fb-checkbox-label" for="' . $inputId . '">';
                    $html .= '<input type="checkbox" name="' . htmlspecialchars($slug) . '" id="' . $inputId . '"';
                    $html .= ' value="1"' . $checked . $rulesAttr . '>';
                    $html .= ' ' . htmlspecialchars($label);
                    $html .= '</label>';
                }
                $html .= $this->renderError($errors, $slug);
                break;

            case 'file':
                $html .= $this->renderLabel($field);
                $html .= '<input type="file" name="' . htmlspecialchars($slug) . '" id="' . $inputId . '"';
                $html .= ' class="' . $inputClass . ' fb-file"';
                $html .= $ariaAttrs . $rulesAttr;
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
                // text, email, number, date, phone, url, password, range, color, time, datetime
                $inputType = match ($type) {
                    'phone' => 'tel',
                    'datetime' => 'datetime-local',
                    default => $type,
                };
                $html .= $this->renderLabel($field);
                $html .= '<input type="' . htmlspecialchars($inputType) . '" name="' . htmlspecialchars($slug) . '"';
                $html .= ' id="' . $inputId . '"';
                $html .= ' class="' . $inputClass . '"';
                $html .= ' value="' . htmlspecialchars($value) . '"';
                $html .= $ariaAttrs . $rulesAttr;
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
    private function renderMultiStep(Form $form, array $fields, array $errors, array $oldInput, array $validationRules = []): string
    {
        $steps = $form->getSteps()->toArray();
        $fieldsByStep = $form->getFieldsByStep();

        // Step indicator
        $html = '<div class="fb-step-indicator">';
        foreach ($steps as $i => $step) {
            if ($i > 0) {
                $html .= '<div class="fb-step-connector"></div>';
            }
            $active = $i === 0 ? ' active' : '';
            $html .= '<div class="fb-step-dot' . $active . '" data-step="' . ($i + 1) . '">';
            $html .= ($i + 1);
            $html .= '</div>';
        }
        $html .= '</div>';

        // Step containers
        foreach ($steps as $i => $step) {
            $activeClass = $i === 0 ? ' active' : '';
            $html .= '<div class="fb-step' . $activeClass . '" data-step="' . ($i + 1) . '">';
            if ($step->getTitle()) {
                $html .= '<h3 class="fb-step-heading">' . htmlspecialchars($step->getTitle()) . '</h3>';
            }
            if ($step->getDescription()) {
                $html .= '<p class="fb-step-desc">' . htmlspecialchars($step->getDescription()) . '</p>';
            }
            $html .= '<div class="fb-fields">';
            $stepFields = $fieldsByStep[$step->getId()] ?? [];
            foreach ($stepFields as $field) {
                $html .= $this->renderField($field, $errors, $oldInput, $validationRules);
            }
            $html .= '</div>';
            $html .= '</div>';
        }

        // Fields without a step
        $orphanFields = $fieldsByStep[0] ?? [];
        if (!empty($orphanFields) && empty($steps)) {
            $html .= '<div class="fb-fields">';
            foreach ($orphanFields as $field) {
                $html .= $this->renderField($field, $errors, $oldInput, $validationRules);
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

    /**
     * Render error container — always present for aria-describedby, hidden when no error.
     */
    private function renderError(array $errors, string $slug): string
    {
        $id = 'fb-error-' . htmlspecialchars($slug);
        if (isset($errors[$slug])) {
            $msg = is_array($errors[$slug]) ? ($errors[$slug][0] ?? '') : $errors[$slug];
            return '<div class="fb-error" id="' . $id . '" role="alert">' . htmlspecialchars($msg) . '</div>';
        }
        return '<div class="fb-error" id="' . $id . '" style="display:none"></div>';
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

    private static bool $stylesIncluded = false;

    private function getFormStyles(): string
    {
        if (self::$stylesIncluded) {
            return '';
        }
        self::$stylesIncluded = true;

        $cssFile = dirname(__DIR__, 2) . '/assets/css/form-public.css';
        if (!file_exists($cssFile)) {
            return '';
        }

        return '<style>' . file_get_contents($cssFile) . '</style>';
    }

    private static array $scriptsIncluded = [];

    private function getFormScript(string $filename): string
    {
        if (isset(self::$scriptsIncluded[$filename])) {
            return '';
        }
        self::$scriptsIncluded[$filename] = true;

        $jsFile = dirname(__DIR__, 2) . '/assets/js/' . $filename;
        if (!file_exists($jsFile)) {
            return '';
        }

        return '<script>' . file_get_contents($jsFile) . '</script>';
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
