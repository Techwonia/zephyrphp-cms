/**
 * Form Builder Admin - Field Management
 *
 * Handles: add/edit/delete fields via AJAX, auto-slug, drag-and-drop reorder,
 * multi-step management. Reads CSRF token from <meta name="csrf-token">.
 */
(function () {
    'use strict';

    // ===== Config =====
    var formId = (function () {
        var el = document.getElementById('field-list');
        return el ? el.dataset.formId : null;
    })();

    if (!formId) return;

    var csrfToken = (function () {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    })();

    var API_BASE = '/cms/forms/' + formId + '/fields';

    // ===== DOM References =====
    var fieldList = document.getElementById('field-list');
    var fieldForm = document.getElementById('field-form');
    var fieldFormTitle = document.getElementById('field-form-title');
    var addFieldBtn = document.getElementById('add-field-btn');
    var saveFieldBtn = document.getElementById('field-form-save');
    var cancelFieldBtn = document.getElementById('field-form-cancel');
    var emptyState = document.getElementById('empty-fields');

    // Field form inputs
    var ffId = document.getElementById('ff-id');
    var ffLabel = document.getElementById('ff-label');
    var ffSlug = document.getElementById('ff-slug');
    var ffType = document.getElementById('ff-type');
    var ffPlaceholder = document.getElementById('ff-placeholder');
    var ffDefault = document.getElementById('ff-default');
    var ffValidation = document.getElementById('ff-validation');
    var ffRequired = document.getElementById('ff-required');
    var ffWidth = document.getElementById('ff-width');
    var ffOptions = document.getElementById('ff-options');
    var ffOptionsGroup = document.getElementById('ff-options-group');
    var ffStep = document.getElementById('ff-step');

    // ===== Helpers =====
    function slugify(text) {
        return text.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
    }

    function apiRequest(method, url, body) {
        var opts = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            }
        };
        if (body) opts.body = JSON.stringify(body);
        return fetch(url, opts).then(function (res) {
            if (!res.ok) {
                return res.json().then(function (err) {
                    throw new Error(err.message || 'Request failed');
                });
            }
            return res.json();
        });
    }

    // ===== Auto-slug from label =====
    if (ffLabel && ffSlug) {
        ffLabel.addEventListener('input', function () {
            if (!ffSlug.dataset.manual) {
                ffSlug.value = slugify(this.value);
            }
        });
        ffSlug.addEventListener('input', function () {
            this.dataset.manual = '1';
        });
    }

    // ===== Toggle options field for select/radio/checkbox types =====
    if (ffType && ffOptionsGroup) {
        ffType.addEventListener('change', function () {
            var needsOptions = ['select', 'radio', 'checkbox'].indexOf(this.value) !== -1;
            ffOptionsGroup.style.display = needsOptions ? '' : 'none';
        });
    }

    // ===== Show/Hide field form =====
    function showFieldForm(editing) {
        fieldForm.style.display = '';
        if (addFieldBtn) addFieldBtn.style.display = 'none';
        fieldFormTitle.textContent = editing ? 'Edit Field' : 'Add New Field';
        if (!editing) resetFieldForm();
        ffLabel.focus();
    }

    function hideFieldForm() {
        fieldForm.style.display = 'none';
        if (addFieldBtn) addFieldBtn.style.display = '';
        resetFieldForm();
    }

    function resetFieldForm() {
        ffId.value = '';
        ffLabel.value = '';
        ffSlug.value = '';
        ffSlug.dataset.manual = '';
        ffType.value = 'text';
        if (ffPlaceholder) ffPlaceholder.value = '';
        if (ffDefault) ffDefault.value = '';
        if (ffValidation) ffValidation.value = '';
        if (ffRequired) ffRequired.checked = false;
        if (ffWidth) ffWidth.value = 'col-12';
        if (ffOptions) ffOptions.value = '';
        if (ffOptionsGroup) ffOptionsGroup.style.display = 'none';
        if (ffStep) ffStep.value = '0';
    }

    if (addFieldBtn) {
        addFieldBtn.addEventListener('click', function () {
            showFieldForm(false);
        });
    }

    if (cancelFieldBtn) {
        cancelFieldBtn.addEventListener('click', hideFieldForm);
    }

    // ===== Save Field (Add or Update) =====
    if (saveFieldBtn) {
        saveFieldBtn.addEventListener('click', function () {
            var label = ffLabel.value.trim();
            if (!label) {
                ffLabel.focus();
                return;
            }

            var slug = ffSlug.value.trim() || slugify(label);
            var data = {
                label: label,
                slug: slug,
                type: ffType.value,
                placeholder: ffPlaceholder ? ffPlaceholder.value.trim() : '',
                default_value: ffDefault ? ffDefault.value.trim() : '',
                validation: ffValidation ? ffValidation.value.trim() : '',
                required: ffRequired ? ffRequired.checked : false,
                width: ffWidth ? ffWidth.value : 'col-12',
                step: ffStep ? parseInt(ffStep.value, 10) : 0
            };

            // Parse options JSON for select/radio/checkbox
            if (ffOptions && ['select', 'radio', 'checkbox'].indexOf(ffType.value) !== -1) {
                try {
                    data.options = JSON.parse(ffOptions.value || '[]');
                } catch (e) {
                    alert('Invalid JSON in Options field. Please enter a valid JSON array.');
                    ffOptions.focus();
                    return;
                }
            }

            var editingId = ffId.value;
            var method = editingId ? 'PUT' : 'POST';
            var url = editingId ? API_BASE + '/' + editingId : API_BASE;

            saveFieldBtn.disabled = true;
            saveFieldBtn.textContent = 'Saving...';

            apiRequest(method, url, data)
                .then(function (result) {
                    if (editingId) {
                        updateFieldCard(result.field || result);
                    } else {
                        appendFieldCard(result.field || result);
                    }
                    hideFieldForm();
                    toggleEmptyState();
                })
                .catch(function (err) {
                    alert('Error: ' + err.message);
                })
                .finally(function () {
                    saveFieldBtn.disabled = false;
                    saveFieldBtn.textContent = 'Save Field';
                });
        });
    }

    // ===== Build Field Card HTML =====
    function createFieldCardEl(field) {
        var card = document.createElement('div');
        card.className = 'fb-field-card';
        card.draggable = true;
        card.dataset.fieldId = field.id;
        card.dataset.step = field.step || 0;

        var meta = '<code>' + escapeHtml(field.slug) + '</code>' +
            '<span class="fb-type-badge">' + escapeHtml(field.type) + '</span>';
        if (field.required) {
            meta += '<span class="fb-required-badge">required</span>';
        }
        if (field.width && field.width !== 'col-12') {
            meta += '<span class="fb-width-badge">' + escapeHtml(field.width) + '</span>';
        }

        card.innerHTML =
            '<div class="fb-drag-handle" title="Drag to reorder">&#x2630;</div>' +
            '<div class="fb-field-info">' +
            '  <div class="fb-field-label">' + escapeHtml(field.label) + '</div>' +
            '  <div class="fb-field-meta">' + meta + '</div>' +
            '</div>' +
            '<div class="fb-field-actions">' +
            '  <button type="button" class="btn btn-sm btn-outline fb-edit-field" data-field-id="' + field.id + '">Edit</button>' +
            '  <button type="button" class="btn btn-sm btn-danger fb-delete-field" data-field-id="' + field.id + '" data-field-label="' + escapeHtml(field.label) + '">Delete</button>' +
            '</div>';

        bindCardEvents(card);
        bindDragEvents(card);
        return card;
    }

    function appendFieldCard(field) {
        var card = createFieldCardEl(field);
        fieldList.appendChild(card);
    }

    function updateFieldCard(field) {
        var existing = fieldList.querySelector('[data-field-id="' + field.id + '"]');
        if (existing) {
            var newCard = createFieldCardEl(field);
            existing.replaceWith(newCard);
        }
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function toggleEmptyState() {
        if (!emptyState) return;
        var cards = fieldList.querySelectorAll('.fb-field-card');
        emptyState.style.display = cards.length === 0 ? '' : 'none';
    }

    // ===== Edit Field =====
    function handleEditField(fieldId) {
        apiRequest('GET', API_BASE + '/' + fieldId)
            .then(function (result) {
                var field = result.field || result;
                ffId.value = field.id;
                ffLabel.value = field.label || '';
                ffSlug.value = field.slug || '';
                ffSlug.dataset.manual = '1';
                ffType.value = field.type || 'text';
                if (ffPlaceholder) ffPlaceholder.value = field.placeholder || '';
                if (ffDefault) ffDefault.value = field.default_value || '';
                if (ffValidation) ffValidation.value = field.validation || '';
                if (ffRequired) ffRequired.checked = !!field.required;
                if (ffWidth) ffWidth.value = field.width || 'col-12';
                if (ffStep) ffStep.value = field.step || 0;

                if (ffOptions && field.options) {
                    ffOptions.value = JSON.stringify(field.options, null, 2);
                }

                // Show options group if needed
                if (ffOptionsGroup) {
                    var needsOptions = ['select', 'radio', 'checkbox'].indexOf(field.type) !== -1;
                    ffOptionsGroup.style.display = needsOptions ? '' : 'none';
                }

                showFieldForm(true);
            })
            .catch(function (err) {
                alert('Failed to load field: ' + err.message);
            });
    }

    // ===== Delete Field =====
    function handleDeleteField(fieldId, fieldLabel) {
        if (!confirm('Delete field "' + fieldLabel + '"? This cannot be undone.')) return;

        apiRequest('DELETE', API_BASE + '/' + fieldId)
            .then(function () {
                var card = fieldList.querySelector('[data-field-id="' + fieldId + '"]');
                if (card) card.remove();
                toggleEmptyState();
            })
            .catch(function (err) {
                alert('Error: ' + err.message);
            });
    }

    // ===== Bind card button events =====
    function bindCardEvents(card) {
        var editBtn = card.querySelector('.fb-edit-field');
        var deleteBtn = card.querySelector('.fb-delete-field');

        if (editBtn) {
            editBtn.addEventListener('click', function () {
                handleEditField(this.dataset.fieldId);
            });
        }
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function () {
                handleDeleteField(this.dataset.fieldId, this.dataset.fieldLabel);
            });
        }
    }

    // Bind events for cards rendered server-side
    fieldList.querySelectorAll('.fb-field-card').forEach(function (card) {
        bindCardEvents(card);
    });

    // ===== Drag and Drop Reorder =====
    var dragSrcEl = null;

    function bindDragEvents(card) {
        card.addEventListener('dragstart', function (e) {
            dragSrcEl = this;
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', this.dataset.fieldId);
        });

        card.addEventListener('dragend', function () {
            this.classList.remove('dragging');
            fieldList.querySelectorAll('.fb-field-card').forEach(function (c) {
                c.classList.remove('drag-over');
            });
        });

        card.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (this !== dragSrcEl) {
                this.classList.add('drag-over');
            }
        });

        card.addEventListener('dragleave', function () {
            this.classList.remove('drag-over');
        });

        card.addEventListener('drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('drag-over');

            if (dragSrcEl && dragSrcEl !== this) {
                // Determine insert position
                var rect = this.getBoundingClientRect();
                var midY = rect.top + rect.height / 2;
                if (e.clientY < midY) {
                    fieldList.insertBefore(dragSrcEl, this);
                } else {
                    fieldList.insertBefore(dragSrcEl, this.nextSibling);
                }
                saveFieldOrder();
            }
        });
    }

    // Bind drag events for server-rendered cards
    fieldList.querySelectorAll('.fb-field-card').forEach(function (card) {
        bindDragEvents(card);
    });

    function saveFieldOrder() {
        var ids = [];
        fieldList.querySelectorAll('.fb-field-card').forEach(function (card) {
            ids.push(card.dataset.fieldId);
        });

        apiRequest('POST', API_BASE + '/reorder', { order: ids })
            .catch(function (err) {
                console.error('Failed to save field order:', err.message);
            });
    }

    // ===== Multi-step: Step Tabs =====
    var stepTabs = document.getElementById('step-tabs');
    var addStepBtn = document.getElementById('add-step-btn');

    if (stepTabs) {
        stepTabs.addEventListener('click', function (e) {
            var tab = e.target.closest('.fb-step-tab');
            if (!tab) return;

            stepTabs.querySelectorAll('.fb-step-tab').forEach(function (t) {
                t.classList.remove('active');
            });
            tab.classList.add('active');

            var stepIndex = tab.dataset.step;
            filterFieldsByStep(stepIndex);
        });
    }

    function filterFieldsByStep(stepIndex) {
        fieldList.querySelectorAll('.fb-field-card').forEach(function (card) {
            var cardStep = card.dataset.step || '0';
            card.style.display = (cardStep === String(stepIndex)) ? '' : 'none';
        });
    }

    if (addStepBtn) {
        addStepBtn.addEventListener('click', function () {
            var label = prompt('Step label:', 'Step ' + (stepTabs.children.length + 1));
            if (!label) return;

            apiRequest('POST', '/cms/forms/' + formId + '/steps', { label: label })
                .then(function (result) {
                    var step = result.step || result;
                    var tab = document.createElement('button');
                    tab.type = 'button';
                    tab.className = 'fb-step-tab';
                    tab.dataset.step = step.index !== undefined ? step.index : stepTabs.children.length;
                    tab.textContent = label;
                    stepTabs.appendChild(tab);

                    // Add option to step select in field form
                    if (ffStep) {
                        var opt = document.createElement('option');
                        opt.value = tab.dataset.step;
                        opt.textContent = label;
                        ffStep.appendChild(opt);
                    }
                })
                .catch(function (err) {
                    alert('Error: ' + err.message);
                });
        });
    }

})();
