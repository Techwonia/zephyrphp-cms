/**
 * Form Builder — Client-Side Validation & Submission
 *
 * Features:
 * - Reads data-rules attributes and validates before submit
 * - Shows inline errors (red border + message below input)
 * - Real-time: clears error on input, validates on blur
 * - Loading spinner on submit button
 * - AJAX submission support (add data-ajax="1" to form)
 * - Works with both .fb-form (form builder) and .zephyr-form (legacy)
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        // Form Builder forms
        document.querySelectorAll('.fb-form').forEach(initForm);
        // Legacy forms (AJAX only)
        document.querySelectorAll('.zephyr-form[data-ajax="1"]').forEach(initLegacyAjax);
    });

    /* ================================================================
       Validation Rules Engine
       ================================================================ */
    var validators = {
        required: function(value, param, input) {
            if (input && input.type === 'checkbox') {
                return input.checked ? null : 'This field is required.';
            }
            return (value !== null && value !== undefined && String(value).trim() !== '')
                ? null : 'This field is required.';
        },
        email: function(value) {
            if (!value) return null;
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) ? null : 'Please enter a valid email address.';
        },
        url: function(value) {
            if (!value) return null;
            try { new URL(value); return null; } catch(e) { return 'Please enter a valid URL.'; }
        },
        numeric: function(value) {
            if (!value) return null;
            return !isNaN(parseFloat(value)) && isFinite(value) ? null : 'Please enter a number.';
        },
        phone: function(value) {
            if (!value) return null;
            return /^[\+]?[\d\s\-\(\)\.]{7,20}$/.test(value) ? null : 'Please enter a valid phone number.';
        },
        min: function(value, param, input) {
            if (!value) return null;
            var n = parseFloat(param);
            if (input && (input.type === 'number' || input.type === 'range')) {
                return parseFloat(value) >= n ? null : 'Must be at least ' + n + '.';
            }
            return value.length >= n ? null : 'Must be at least ' + n + ' characters.';
        },
        max: function(value, param, input) {
            if (!value) return null;
            var n = parseFloat(param);
            if (input && (input.type === 'number' || input.type === 'range')) {
                return parseFloat(value) <= n ? null : 'Must be no more than ' + n + '.';
            }
            return value.length <= n ? null : 'Must be no more than ' + n + ' characters.';
        },
        'in': function(value, param) {
            if (!value) return null;
            var allowed = param.split(',');
            return allowed.indexOf(value) !== -1 ? null : 'Please select a valid option.';
        },
        date: function(value) {
            if (!value) return null;
            return !isNaN(Date.parse(value)) ? null : 'Please enter a valid date.';
        },
        regex: function(value, param) {
            if (!value) return null;
            try {
                var pattern = param.replace(/^\/|\/$/g, '');
                return new RegExp(pattern).test(value) ? null : 'Invalid format.';
            } catch(e) { return null; }
        }
    };

    /**
     * Parse pipe-delimited rules string into array of {name, param}
     */
    function parseRules(rulesStr) {
        if (!rulesStr) return [];
        return rulesStr.split('|').map(function(rule) {
            var parts = rule.split(':');
            return { name: parts[0], param: parts.slice(1).join(':') };
        }).filter(function(r) { return r.name && r.name !== 'nullable'; });
    }

    /**
     * Validate a single input against its data-rules
     */
    function validateInput(input) {
        var rulesStr = input.dataset.rules || '';
        // Also check parent group for radio/checkbox
        if (!rulesStr && input.closest('.fb-radio-group, .fb-checkbox-group')) {
            var group = input.closest('.fb-radio-group, .fb-checkbox-group');
            rulesStr = group.dataset.rules || '';
        }
        if (!rulesStr) {
            // Fallback: if required attribute is set, validate that
            if (input.hasAttribute('required') && !input.value.trim()) {
                return 'This field is required.';
            }
            return null;
        }

        var rules = parseRules(rulesStr);
        var value = input.value ? input.value.trim() : '';

        for (var i = 0; i < rules.length; i++) {
            var rule = rules[i];
            var fn = validators[rule.name];
            if (!fn) continue; // Skip unknown rules
            var error = fn(value, rule.param, input);
            if (error) return error;
        }
        return null;
    }

    /* ================================================================
       Error Display
       ================================================================ */
    function showFieldError(input, message) {
        input.classList.add('is-invalid');
        input.setAttribute('aria-invalid', 'true');

        var field = input.closest('.fb-field');
        if (field) field.classList.add('fb-field--error');

        // Find the error container
        var name = input.name.replace('[]', '');
        var errorEl = document.getElementById('fb-error-' + name);
        if (errorEl) {
            errorEl.textContent = message;
            errorEl.style.display = '';
        } else {
            // Create one if not present (legacy forms)
            var parent = field || input.parentNode;
            errorEl = document.createElement('div');
            errorEl.className = 'fb-error';
            errorEl.id = 'fb-error-' + name;
            errorEl.setAttribute('role', 'alert');
            errorEl.textContent = message;
            parent.appendChild(errorEl);
        }
    }

    function clearFieldError(input) {
        input.classList.remove('is-invalid');
        input.removeAttribute('aria-invalid');

        var field = input.closest('.fb-field');
        if (field) field.classList.remove('fb-field--error');

        var name = input.name.replace('[]', '');
        var errorEl = document.getElementById('fb-error-' + name);
        if (errorEl) {
            errorEl.textContent = '';
            errorEl.style.display = 'none';
        }
    }

    function clearAllErrors(form) {
        form.querySelectorAll('.is-invalid').forEach(function(el) {
            el.classList.remove('is-invalid');
            el.removeAttribute('aria-invalid');
        });
        form.querySelectorAll('.fb-field--error').forEach(function(el) {
            el.classList.remove('fb-field--error');
        });
        form.querySelectorAll('.fb-error').forEach(function(el) {
            el.textContent = '';
            el.style.display = 'none';
        });
        var alert = form.querySelector('.fb-alert');
        if (alert) alert.remove();
    }

    function showFormAlert(form, message, type) {
        var existing = form.querySelector('.fb-alert');
        if (existing) existing.remove();

        var alert = document.createElement('div');
        alert.className = 'fb-alert fb-alert-' + type;
        alert.setAttribute('role', 'alert');
        alert.textContent = message;
        form.insertBefore(alert, form.firstChild);
    }

    function scrollToFirstError(form) {
        var first = form.querySelector('.fb-field--error, .is-invalid');
        if (first) {
            first.scrollIntoView({ behavior: 'smooth', block: 'center' });
            var input = first.querySelector('input, textarea, select') || first;
            if (input.focus) input.focus();
        }
    }

    /* ================================================================
       Validate All Fields
       ================================================================ */
    function validateAllFields(form) {
        var inputs = form.querySelectorAll('input, textarea, select');
        var isValid = true;
        var validated = {};

        inputs.forEach(function(input) {
            if (input.type === 'hidden' || input.type === 'submit' || input.disabled) return;
            if (input.name === '_hp_field' || input.name === 'csrf_token') return;

            // For radio/checkbox groups, only validate once per name
            var name = input.name.replace('[]', '');
            if (validated[name]) return;

            // For radio groups, check if any is selected
            if (input.type === 'radio') {
                var group = form.querySelectorAll('input[name="' + input.name + '"]');
                var anyChecked = false;
                group.forEach(function(r) { if (r.checked) anyChecked = true; });

                var rulesStr = '';
                var groupEl = input.closest('.fb-radio-group');
                if (groupEl) rulesStr = groupEl.dataset.rules || '';
                if (!rulesStr) rulesStr = input.dataset.rules || '';

                if (rulesStr && rulesStr.indexOf('required') !== -1 && !anyChecked) {
                    showFieldError(input, 'Please select an option.');
                    isValid = false;
                }
                validated[name] = true;
                return;
            }

            // For checkbox groups
            if (input.type === 'checkbox' && input.name.indexOf('[]') !== -1) {
                var checkboxes = form.querySelectorAll('input[name="' + input.name + '"]');
                var anyChecked = false;
                checkboxes.forEach(function(c) { if (c.checked) anyChecked = true; });

                var groupEl = input.closest('.fb-checkbox-group');
                var rulesStr = groupEl ? (groupEl.dataset.rules || '') : '';
                if (rulesStr && rulesStr.indexOf('required') !== -1 && !anyChecked) {
                    showFieldError(input, 'Please select at least one option.');
                    isValid = false;
                }
                validated[name] = true;
                return;
            }

            var error = validateInput(input);
            if (error) {
                showFieldError(input, error);
                isValid = false;
            }
            validated[name] = true;
        });

        return isValid;
    }

    /* ================================================================
       Loading State
       ================================================================ */
    function setLoading(form, loading) {
        var btn = form.querySelector('.fb-btn-submit');
        if (!btn) return;
        if (loading) {
            btn.classList.add('fb-loading');
            btn.disabled = true;
        } else {
            btn.classList.remove('fb-loading');
            btn.disabled = false;
        }
    }

    /* ================================================================
       Init Form Builder Form
       ================================================================ */
    function initForm(form) {
        var isAjax = form.dataset.ajax === '1';

        // Real-time: clear error when user types
        form.addEventListener('input', function(e) {
            var input = e.target;
            if (input.classList.contains('is-invalid')) {
                clearFieldError(input);
            }
        });

        // On change for select, radio, checkbox
        form.addEventListener('change', function(e) {
            var input = e.target;
            if (input.classList.contains('is-invalid') || input.closest('.fb-field--error')) {
                clearFieldError(input);
            }
        });

        // Validate on blur (only if field has been interacted with and has a value)
        form.addEventListener('focusout', function(e) {
            var input = e.target;
            if (!input.matches('input, textarea, select')) return;
            if (input.type === 'hidden' || input.type === 'submit') return;
            if (input.name === '_hp_field' || input.name === 'csrf_token') return;

            // Only validate if field has been touched (has value or previously errored)
            if (input.value.trim() || input.dataset.touched) {
                input.dataset.touched = '1';
                var error = validateInput(input);
                if (error) {
                    showFieldError(input, error);
                } else {
                    clearFieldError(input);
                }
            }
        });

        // Submit handler
        form.addEventListener('submit', function(e) {
            clearAllErrors(form);

            if (!validateAllFields(form)) {
                e.preventDefault();
                scrollToFirstError(form);
                return;
            }

            if (isAjax) {
                e.preventDefault();
                submitFormAjax(form);
                return;
            }

            // Normal submit — show loading state
            setLoading(form, true);
        });
    }

    /* ================================================================
       AJAX Submission
       ================================================================ */
    function submitFormAjax(form) {
        setLoading(form, true);
        clearAllErrors(form);

        var formData = new FormData(form);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', form.action, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.onload = function() {
            setLoading(form, false);
            var response;
            try { response = JSON.parse(xhr.responseText); }
            catch(err) {
                showFormAlert(form, 'An unexpected error occurred. Please try again.', 'error');
                return;
            }

            if (response.success) {
                if (response.redirect_url) {
                    window.location.href = response.redirect_url;
                    return;
                }
                // Replace form with success message
                var successHtml = '<div class="fb-success-message">';
                successHtml += '<div class="fb-success-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>';
                successHtml += '<h3>Submitted Successfully</h3>';
                successHtml += '<p>' + escapeHtml(response.message || 'Thank you!') + '</p>';
                successHtml += '</div>';
                form.outerHTML = successHtml;
            } else {
                if (response.errors) {
                    displayServerErrors(form, response.errors);
                }
                showFormAlert(form, response.message || 'Please fix the errors below.', 'error');
                scrollToFirstError(form);
            }
        };

        xhr.onerror = function() {
            setLoading(form, false);
            showFormAlert(form, 'Network error. Please check your connection and try again.', 'error');
        };

        xhr.send(formData);
    }

    /**
     * Display server-side validation errors on each field.
     */
    function displayServerErrors(form, errors) {
        for (var field in errors) {
            if (!errors.hasOwnProperty(field)) continue;
            if (field === '_form') continue;

            var msg = Array.isArray(errors[field]) ? errors[field][0] : errors[field];
            var input = form.querySelector('[name="' + field + '"]')
                     || form.querySelector('[name="' + field + '[]"]');
            if (input) {
                showFieldError(input, msg);
            }
        }
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    /* ================================================================
       Legacy AJAX Form Support (.zephyr-form)
       ================================================================ */
    function initLegacyAjax(form) {
        var submitBtn = form.querySelector('.btn-submit');

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            form.querySelectorAll('.invalid-feedback').forEach(function(el) { el.remove(); });
            form.querySelectorAll('.is-invalid').forEach(function(el) { el.classList.remove('is-invalid'); });
            var alertEl = form.querySelector('.form-alert');
            if (alertEl) alertEl.remove();

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.dataset.originalText = submitBtn.textContent;
                submitBtn.textContent = 'Submitting...';
            }

            var formData = new FormData(form);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', form.action, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.onload = function() {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = submitBtn.dataset.originalText || 'Submit';
                }
                var response;
                try { response = JSON.parse(xhr.responseText); }
                catch(err) {
                    showLegacyAlert(form, 'An unexpected error occurred.', 'error');
                    return;
                }
                if (response.success) {
                    if (response.redirect_url) { window.location.href = response.redirect_url; return; }
                    showLegacyAlert(form, response.message || 'Submitted successfully!', 'success');
                    form.reset();
                } else {
                    if (response.errors) { showLegacyFieldErrors(form, response.errors); }
                    showLegacyAlert(form, response.message || 'Please fix the errors below.', 'error');
                }
            };

            xhr.onerror = function() {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = submitBtn.dataset.originalText || 'Submit';
                }
                showLegacyAlert(form, 'Network error. Please try again.', 'error');
            };

            xhr.send(formData);
        });
    }

    function showLegacyAlert(form, message, type) {
        var existing = form.querySelector('.form-alert');
        if (existing) existing.remove();
        var alert = document.createElement('div');
        alert.className = 'form-alert form-alert-' + type;
        alert.textContent = message;
        form.insertBefore(alert, form.firstChild);
    }

    function showLegacyFieldErrors(form, errors) {
        for (var field in errors) {
            if (!errors.hasOwnProperty(field) || field === '_form') continue;
            var input = form.querySelector('[name="' + field + '"]');
            if (!input) continue;
            input.classList.add('is-invalid');
            var feedback = document.createElement('div');
            feedback.className = 'invalid-feedback';
            feedback.textContent = Array.isArray(errors[field]) ? errors[field][0] : errors[field];
            input.parentNode.appendChild(feedback);
        }
    }

    // Expose for multi-step JS
    window.fbFormUtils = {
        validateInput: validateInput,
        showFieldError: showFieldError,
        clearFieldError: clearFieldError,
        clearAllErrors: clearAllErrors,
        validateAllFields: validateAllFields,
        scrollToFirstError: scrollToFirstError,
        setLoading: setLoading
    };
})();
