<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Controllers;

use ZephyrPHP\Core\Controllers\Controller;
use ZephyrPHP\Auth\Auth;
use ZephyrPHP\Cms\Models\Form;
use ZephyrPHP\Cms\Models\FormField;
use ZephyrPHP\Cms\Models\FormStep;
use ZephyrPHP\Cms\Models\FormSubmission;
use ZephyrPHP\Cms\Services\FormTemplateRegistry;
use ZephyrPHP\Cms\Services\PermissionService;

class FormController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    // ========================================================================
    // ACCESS CONTROL
    // ========================================================================

    private function requireCmsAccess(): void
    {
        if (!Auth::check()) {
            $this->redirect('/login');
            return;
        }
        if (!PermissionService::can('cms.access')) {
            Auth::logout();
            $this->flash('errors', ['auth' => 'Access denied. You do not have CMS access.']);
            $this->redirect('/login');
        }
    }

    private function requirePermission(string $permission): void
    {
        $this->requireCmsAccess();
        if (!PermissionService::can($permission)) {
            $this->flash('errors', ['auth' => 'You do not have permission to perform this action.']);
            $this->redirect('/cms');
        }
    }

    // ========================================================================
    // CRUD
    // ========================================================================

    public function index(): string
    {
        $this->requirePermission('forms.view');

        $forms = Form::findAll();

        return $this->render('cms::forms/index', [
            'forms' => $forms,
            'user' => Auth::user(),
        ]);
    }

    public function create(): string
    {
        $this->requirePermission('forms.create');

        return $this->render('cms::forms/create', [
            'user' => Auth::user(),
        ]);
    }

    public function store(): void
    {
        $this->requirePermission('forms.create');

        $name = trim($this->input('name', ''));
        $slug = trim($this->input('slug', ''));
        $description = $this->input('description', '');
        $status = $this->input('status', 'draft');
        $settings = $this->input('settings', []);

        if (empty($slug)) {
            $slug = $this->generateSlug($name);
        } else {
            $slug = $this->generateSlug($slug);
        }

        $errors = [];
        if (empty($name)) {
            $errors['name'] = 'Form name is required.';
        }
        if (empty($slug)) {
            $errors['slug'] = 'Form slug is required.';
        }

        // Check slug uniqueness
        if (empty($errors['slug'])) {
            $existing = Form::findOneBy(['slug' => $slug]);
            if ($existing) {
                $errors['slug'] = 'A form with this slug already exists.';
            }
        }

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->flash('_old_input', [
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'status' => $status,
            ]);
            $this->back();
            return;
        }

        $form = new Form();
        $form->setName($name);
        $form->setSlug($slug);
        $form->setDescription($description ?: null);
        $form->setStatus($status);
        $form->setSettings(is_array($settings) ? $settings : []);
        $form->setCreatedBy(Auth::user()?->getId());
        $form->save();

        $this->flash('success', "Form \"{$name}\" created successfully.");
        $this->redirect("/cms/forms/{$slug}");
    }

    public function edit(string $slug): string
    {
        $this->requirePermission('forms.edit');

        $form = Form::findOneBy(['slug' => $slug]);
        if (!$form) {
            $this->flash('errors', ['form' => 'Form not found.']);
            $this->redirect('/cms/forms');
            return '';
        }

        $fieldTypes = [
            'text' => 'Text',
            'textarea' => 'Textarea',
            'email' => 'Email',
            'number' => 'Number',
            'tel' => 'Phone',
            'url' => 'URL',
            'select' => 'Select / Dropdown',
            'radio' => 'Radio Buttons',
            'checkbox' => 'Checkbox',
            'file' => 'File Upload',
            'date' => 'Date',
            'datetime' => 'Date & Time',
            'hidden' => 'Hidden',
            'heading' => 'Heading',
            'paragraph' => 'Paragraph',
            'divider' => 'Divider',
        ];

        return $this->render('cms::forms/edit', [
            'form' => $form,
            'fields' => $form->getFields()->toArray(),
            'steps' => $form->getSteps()->toArray(),
            'fieldTypes' => $fieldTypes,
            'user' => Auth::user(),
        ]);
    }

    public function update(string $slug): void
    {
        $this->requirePermission('forms.edit');

        $form = Form::findOneBy(['slug' => $slug]);
        if (!$form) {
            $this->flash('errors', ['form' => 'Form not found.']);
            $this->redirect('/cms/forms');
            return;
        }

        $name = trim($this->input('name', ''));
        $description = $this->input('description', '');
        $status = $this->input('status', $form->getStatus());
        $isMultiStep = $this->boolean('is_multi_step');
        $settings = $this->input('settings', []);

        $errors = [];
        if (empty($name)) {
            $errors['name'] = 'Form name is required.';
        }

        if (!empty($errors)) {
            $this->flash('errors', $errors);
            $this->back();
            return;
        }

        $form->setName($name);
        $form->setDescription($description ?: null);
        $form->setStatus($status);
        $form->setIsMultiStep($isMultiStep);
        $form->setSettings(is_array($settings) ? $settings : []);
        $form->save();

        $this->flash('success', 'Form updated successfully.');
        $this->back();
    }

    public function destroy(string $slug): void
    {
        $this->requirePermission('forms.delete');

        $form = Form::findOneBy(['slug' => $slug]);
        if (!$form) {
            $this->flash('errors', ['form' => 'Form not found.']);
            $this->redirect('/cms/forms');
            return;
        }

        $formName = $form->getName();
        $form->delete();

        $this->flash('success', "Form \"{$formName}\" deleted.");
        $this->redirect('/cms/forms');
    }

    public function duplicate(string $slug): void
    {
        $this->requirePermission('forms.create');

        $original = Form::findOneBy(['slug' => $slug]);
        if (!$original) {
            $this->flash('errors', ['form' => 'Form not found.']);
            $this->redirect('/cms/forms');
            return;
        }

        // Generate a unique slug for the duplicate
        $baseSlug = $original->getSlug() . '-copy';
        $newSlug = $this->ensureUniqueSlug($baseSlug);

        $newForm = new Form();
        $newForm->setName($original->getName() . ' (Copy)');
        $newForm->setSlug($newSlug);
        $newForm->setDescription($original->getDescription());
        $newForm->setStatus('draft');
        $newForm->setIsMultiStep($original->isMultiStep());
        $newForm->setSettings($original->getSettings());
        $newForm->setCreatedBy(Auth::user()?->getId());
        $newForm->save();

        // Duplicate steps
        $stepIdMap = [];
        foreach ($original->getSteps() as $step) {
            $newStep = new FormStep();
            $newStep->setForm($newForm);
            $newStep->setStepNumber($step->getStepNumber());
            $newStep->setTitle($step->getTitle());
            $newStep->setDescription($step->getDescription());
            $newStep->save();
            $stepIdMap[$step->getId()] = $newStep->getId();
        }

        // Duplicate fields
        foreach ($original->getFields() as $field) {
            $newField = new FormField();
            $newField->setForm($newForm);
            $newField->setSlug($field->getSlug());
            $newField->setLabel($field->getLabel());
            $newField->setType($field->getType());
            $newField->setPlaceholder($field->getPlaceholder());
            $newField->setDefaultValue($field->getDefaultValue());
            $newField->setValidation($field->getValidation());
            $newField->setOptions($field->getOptions());
            $newField->setSortOrder($field->getSortOrder());
            $newField->setIsRequired($field->isRequired());

            // Map step ID to the duplicated step
            $oldStepId = $field->getStepId();
            if ($oldStepId !== null && isset($stepIdMap[$oldStepId])) {
                $newField->setStepId($stepIdMap[$oldStepId]);
            }

            $newField->save();
        }

        $this->flash('success', "Form duplicated as \"{$newForm->getName()}\".");
        $this->redirect("/cms/forms/{$newSlug}");
    }

    // ========================================================================
    // FIELD MANAGEMENT (AJAX)
    // ========================================================================

    public function addField(string $slug): string
    {
        $this->requirePermission('forms.edit');

        $form = Form::findOneBy(['slug' => $slug]);
        if (!$form) {
            return $this->json(['success' => false, 'message' => 'Form not found.'], 404);
        }

        $label = trim($this->input('label', ''));
        $fieldSlug = trim($this->input('slug', ''));
        $type = $this->input('type', 'text');
        $placeholder = $this->input('placeholder', '');
        $defaultValue = $this->input('default_value', '');
        $validation = $this->input('validation', '');
        $isRequired = $this->boolean('is_required');
        $stepId = $this->input('step_id');
        $options = $this->input('options');

        if (empty($fieldSlug)) {
            $fieldSlug = $this->generateSlug($label);
        } else {
            $fieldSlug = $this->generateSlug($fieldSlug);
        }

        if (empty($label)) {
            return $this->json(['success' => false, 'message' => 'Field label is required.'], 422);
        }
        if (empty($fieldSlug)) {
            return $this->json(['success' => false, 'message' => 'Field slug is required.'], 422);
        }

        // Check slug uniqueness within this form
        foreach ($form->getFields() as $existingField) {
            if ($existingField->getSlug() === $fieldSlug) {
                return $this->json(['success' => false, 'message' => 'A field with this slug already exists in this form.'], 422);
            }
        }

        // Determine next sort order
        $maxOrder = 0;
        foreach ($form->getFields() as $f) {
            if ($f->getSortOrder() > $maxOrder) {
                $maxOrder = $f->getSortOrder();
            }
        }

        $field = new FormField();
        $field->setForm($form);
        $field->setSlug($fieldSlug);
        $field->setLabel($label);
        $field->setType($type);
        $field->setPlaceholder($placeholder ?: null);
        $field->setDefaultValue($defaultValue ?: null);
        $field->setValidation($validation ?: null);
        $field->setIsRequired($isRequired);
        $field->setSortOrder($maxOrder + 1);

        $width = $this->input('width', 'col-12');
        $fieldOptions = is_array($options) ? $options : [];
        if ($width && $width !== 'col-12') {
            $fieldOptions['width'] = $width;
        }
        $field->setOptions(!empty($fieldOptions) ? $fieldOptions : null);

        if ($stepId !== null && $stepId !== '' && (int) $stepId > 0) {
            $field->setStepId((int) $stepId);
        }

        $field->save();

        return $this->json([
            'success' => true,
            'message' => 'Field added successfully.',
            'field' => [
                'id' => $field->getId(),
                'slug' => $field->getSlug(),
                'label' => $field->getLabel(),
                'type' => $field->getType(),
                'placeholder' => $field->getPlaceholder() ?? '',
                'default_value' => $field->getDefaultValue() ?? '',
                'validation' => $field->getValidation() ?? '',
                'width' => $field->getWidth(),
                'options' => $field->getOptions(),
                'sort_order' => $field->getSortOrder(),
                'is_required' => $field->isRequired(),
                'step_id' => $field->getStepId(),
            ],
        ]);
    }

    public function updateField(string $slug, int $id): string
    {
        $this->requirePermission('forms.edit');

        $form = Form::findOneBy(['slug' => $slug]);
        if (!$form) {
            return $this->json(['success' => false, 'message' => 'Form not found.'], 404);
        }

        $field = FormField::find($id);
        if (!$field || $field->getForm()->getId() !== $form->getId()) {
            return $this->json(['success' => false, 'message' => 'Field not found.'], 404);
        }

        $label = trim($this->input('label', ''));
        $type = $this->input('type', $field->getType());
        $placeholder = $this->input('placeholder', '');
        $defaultValue = $this->input('default_value', '');
        $validation = $this->input('validation', '');
        $isRequired = $this->boolean('is_required');
        $stepId = $this->input('step_id');
        $options = $this->input('options');

        if (empty($label)) {
            return $this->json(['success' => false, 'message' => 'Field label is required.'], 422);
        }

        $field->setLabel($label);
        $field->setType($type);
        $field->setPlaceholder($placeholder ?: null);
        $field->setDefaultValue($defaultValue ?: null);
        $field->setValidation($validation ?: null);
        $field->setIsRequired($isRequired);

        $width = $this->input('width', 'col-12');
        $fieldOptions = is_array($options) ? $options : [];
        if ($width && $width !== 'col-12') {
            $fieldOptions['width'] = $width;
        }
        $field->setOptions(!empty($fieldOptions) ? $fieldOptions : null);

        if ($stepId !== null && $stepId !== '' && (int) $stepId > 0) {
            $field->setStepId((int) $stepId);
        } else {
            $field->setStepId(null);
        }

        $field->save();

        return $this->json([
            'success' => true,
            'message' => 'Field updated successfully.',
            'field' => [
                'id' => $field->getId(),
                'slug' => $field->getSlug(),
                'label' => $field->getLabel(),
                'type' => $field->getType(),
                'placeholder' => $field->getPlaceholder() ?? '',
                'default_value' => $field->getDefaultValue() ?? '',
                'validation' => $field->getValidation() ?? '',
                'width' => $field->getWidth(),
                'options' => $field->getOptions(),
                'sort_order' => $field->getSortOrder(),
                'is_required' => $field->isRequired(),
                'step_id' => $field->getStepId(),
            ],
        ]);
    }

    public function deleteField(string $slug, int $id): string
    {
        $this->requirePermission('forms.edit');

        $form = Form::findOneBy(['slug' => $slug]);
        if (!$form) {
            return $this->json(['success' => false, 'message' => 'Form not found.'], 404);
        }

        $field = FormField::find($id);
        if (!$field || $field->getForm()->getId() !== $form->getId()) {
            return $this->json(['success' => false, 'message' => 'Field not found.'], 404);
        }

        $field->delete();

        return $this->json([
            'success' => true,
            'message' => 'Field deleted successfully.',
        ]);
    }

    public function reorderFields(string $slug): string
    {
        $this->requirePermission('forms.edit');

        $form = Form::findOneBy(['slug' => $slug]);
        if (!$form) {
            return $this->json(['success' => false, 'message' => 'Form not found.'], 404);
        }

        $order = $this->input('order', []);
        if (!is_array($order)) {
            return $this->json(['success' => false, 'message' => 'Invalid order data.'], 422);
        }

        foreach ($order as $position => $fieldId) {
            $fieldId = (int) $fieldId;
            $field = FormField::find($fieldId);
            if ($field && $field->getForm()->getId() === $form->getId()) {
                $field->setSortOrder((int) $position);
                $field->save();
            }
        }

        return $this->json([
            'success' => true,
            'message' => 'Fields reordered successfully.',
        ]);
    }

    // ========================================================================
    // STEP MANAGEMENT (AJAX)
    // ========================================================================

    public function addStep(string $slug): string
    {
        $this->requirePermission('forms.edit');

        $form = Form::findOneBy(['slug' => $slug]);
        if (!$form) {
            return $this->json(['success' => false, 'message' => 'Form not found.'], 404);
        }

        $title = trim($this->input('title', ''));
        $description = $this->input('description', '');

        if (empty($title)) {
            return $this->json(['success' => false, 'message' => 'Step title is required.'], 422);
        }

        // Determine next step number
        $maxStep = 0;
        foreach ($form->getSteps() as $s) {
            if ($s->getStepNumber() > $maxStep) {
                $maxStep = $s->getStepNumber();
            }
        }

        $step = new FormStep();
        $step->setForm($form);
        $step->setStepNumber($maxStep + 1);
        $step->setTitle($title);
        $step->setDescription($description ?: null);
        $step->save();

        return $this->json([
            'success' => true,
            'message' => 'Step added successfully.',
            'step' => [
                'id' => $step->getId(),
                'step_number' => $step->getStepNumber(),
                'title' => $step->getTitle(),
                'description' => $step->getDescription(),
            ],
        ]);
    }

    public function updateStep(string $slug, int $id): string
    {
        $this->requirePermission('forms.edit');

        $form = Form::findOneBy(['slug' => $slug]);
        if (!$form) {
            return $this->json(['success' => false, 'message' => 'Form not found.'], 404);
        }

        $step = FormStep::find($id);
        if (!$step || $step->getForm()->getId() !== $form->getId()) {
            return $this->json(['success' => false, 'message' => 'Step not found.'], 404);
        }

        $title = trim($this->input('title', ''));
        $description = $this->input('description', '');

        if (empty($title)) {
            return $this->json(['success' => false, 'message' => 'Step title is required.'], 422);
        }

        $step->setTitle($title);
        $step->setDescription($description ?: null);
        $step->save();

        return $this->json([
            'success' => true,
            'message' => 'Step updated successfully.',
            'step' => [
                'id' => $step->getId(),
                'step_number' => $step->getStepNumber(),
                'title' => $step->getTitle(),
                'description' => $step->getDescription(),
            ],
        ]);
    }

    public function deleteStep(string $slug, int $id): string
    {
        $this->requirePermission('forms.edit');

        $form = Form::findOneBy(['slug' => $slug]);
        if (!$form) {
            return $this->json(['success' => false, 'message' => 'Form not found.'], 404);
        }

        $step = FormStep::find($id);
        if (!$step || $step->getForm()->getId() !== $form->getId()) {
            return $this->json(['success' => false, 'message' => 'Step not found.'], 404);
        }

        // Unassign fields from this step before deleting
        foreach ($form->getFields() as $field) {
            if ($field->getStepId() === $step->getId()) {
                $field->setStepId(null);
                $field->save();
            }
        }

        $step->delete();

        return $this->json([
            'success' => true,
            'message' => 'Step deleted successfully.',
        ]);
    }

    // ========================================================================
    // SUBMISSIONS
    // ========================================================================

    public function submissions(string $slug): string
    {
        $this->requirePermission('forms.view');

        $form = Form::findOneBy(['slug' => $slug]);
        if (!$form) {
            $this->flash('errors', ['form' => 'Form not found.']);
            $this->redirect('/cms/forms');
            return '';
        }

        $submissions = FormSubmission::findBy(
            ['form' => $form],
            ['createdAt' => 'DESC']
        );

        return $this->render('cms::forms/submissions', [
            'form' => $form,
            'submissions' => $submissions,
            'user' => Auth::user(),
        ]);
    }

    public function viewSubmission(string $slug, int $id): string
    {
        $this->requirePermission('forms.view');

        $form = Form::findOneBy(['slug' => $slug]);
        if (!$form) {
            $this->flash('errors', ['form' => 'Form not found.']);
            $this->redirect('/cms/forms');
            return '';
        }

        $submission = FormSubmission::find($id);
        if (!$submission || $submission->getForm()->getId() !== $form->getId()) {
            $this->flash('errors', ['submission' => 'Submission not found.']);
            $this->redirect("/cms/forms/{$slug}/submissions");
            return '';
        }

        return $this->render('cms::forms/submission-detail', [
            'form' => $form,
            'submission' => $submission,
            'fields' => $form->getFields()->toArray(),
            'user' => Auth::user(),
        ]);
    }

    public function deleteSubmission(string $slug, int $id): void
    {
        $this->requirePermission('forms.delete');

        $form = Form::findOneBy(['slug' => $slug]);
        if (!$form) {
            $this->flash('errors', ['form' => 'Form not found.']);
            $this->redirect('/cms/forms');
            return;
        }

        $submission = FormSubmission::find($id);
        if (!$submission || $submission->getForm()->getId() !== $form->getId()) {
            $this->flash('errors', ['submission' => 'Submission not found.']);
            $this->redirect("/cms/forms/{$slug}/submissions");
            return;
        }

        $submission->delete();

        $this->flash('success', 'Submission deleted.');
        $this->redirect("/cms/forms/{$slug}/submissions");
    }

    public function exportSubmissions(string $slug): void
    {
        $this->requirePermission('forms.view');

        $form = Form::findOneBy(['slug' => $slug]);
        if (!$form) {
            $this->flash('errors', ['form' => 'Form not found.']);
            $this->redirect('/cms/forms');
            return;
        }

        $submissions = FormSubmission::findBy(
            ['form' => $form],
            ['createdAt' => 'DESC']
        );

        $fields = $form->getSubmittableFields();

        // Build CSV headers
        $headers = ['ID', 'Status', 'Created At'];
        foreach ($fields as $field) {
            $headers[] = $field->getLabel();
        }

        // Set response headers for CSV download
        $filename = $form->getSlug() . '-submissions-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');

        $output = fopen('php://output', 'w');
        fputcsv($output, $headers);

        foreach ($submissions as $submission) {
            $row = [
                $submission->getId(),
                $submission->getStatus(),
                $submission->getCreatedAt()?->format('Y-m-d H:i:s') ?? '',
            ];

            $data = $submission->getData();
            foreach ($fields as $field) {
                $value = $data[$field->getSlug()] ?? '';
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $row[] = $value;
            }

            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }

    // ========================================================================
    // TEMPLATES
    // ========================================================================

    public function templates(): string
    {
        $this->requirePermission('forms.create');

        $registry = new FormTemplateRegistry();
        $templates = $registry->all();

        return $this->render('cms::forms/templates', [
            'templates' => $templates,
            'user' => Auth::user(),
        ]);
    }

    public function createFromTemplate(string $templateSlug): void
    {
        $this->requirePermission('forms.create');

        $registry = new FormTemplateRegistry();
        $template = $registry->get($templateSlug);

        if (!$template) {
            $this->flash('errors', ['template' => 'Template not found.']);
            $this->redirect('/cms/forms/templates');
            return;
        }

        $formSlug = $this->ensureUniqueSlug($this->generateSlug($template['name'] ?? $templateSlug));

        try {
            $form = new Form();
            $form->setName($template['name'] ?? 'Untitled Form');
            $form->setSlug($formSlug);
            $form->setDescription($template['description'] ?? null);
            $form->setStatus('draft');
            $form->setSettings($template['settings'] ?? []);
            $form->setCreatedBy(Auth::user()?->getId());
            $form->save();

            // Create fields from template definition
            $sortOrder = 0;
            foreach (($template['fields'] ?? []) as $fieldDef) {
                $field = new FormField();
                $field->setForm($form);
                $field->setSlug($this->generateSlug($fieldDef['label'] ?? 'field-' . $sortOrder));
                $field->setLabel($fieldDef['label'] ?? '');
                $field->setType($fieldDef['type'] ?? 'text');
                $field->setPlaceholder($fieldDef['placeholder'] ?? null);
                $field->setDefaultValue($fieldDef['default_value'] ?? null);
                $field->setValidation($fieldDef['validation'] ?? null);
                $field->setIsRequired($fieldDef['is_required'] ?? false);
                $field->setOptions($fieldDef['options'] ?? null);
                $field->setSortOrder($sortOrder);
                $field->save();
                $sortOrder++;
            }

            $this->flash('success', "Form created from template \"{$template['name']}\".");
            $this->redirect("/cms/forms/{$formSlug}");
        } catch (\Exception $e) {
            $this->flash('errors', ['template' => 'Failed to create form from template.']);
            $this->redirect('/cms/forms/templates');
        }
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private function generateSlug(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($name)));
    }

    private function ensureUniqueSlug(string $slug): string
    {
        $originalSlug = $slug;
        $counter = 1;

        while (Form::findOneBy(['slug' => $slug])) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
