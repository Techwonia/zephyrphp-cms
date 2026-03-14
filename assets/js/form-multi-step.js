/**
 * Multi-Step Form Navigation
 *
 * Supports two class schemes:
 * - Legacy: .zephyr-form[data-multi-step="1"] with .form-step, .step-dot, .step-line
 * - Form Builder: [data-form-slug] with .fb-step, .fb-step-dot, .fb-step-connector
 *
 * Auto-initializes on DOMContentLoaded. Handles next/back navigation,
 * per-step HTML5 validation, step indicator updates, submit button on last step.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // Legacy multi-step forms
        document.querySelectorAll('.zephyr-form[data-multi-step="1"]').forEach(function (form) {
            initLegacyMultiStep(form);
        });

        // Form Builder multi-step forms
        document.querySelectorAll('[data-form-slug]').forEach(function (form) {
            var fbSteps = form.querySelectorAll('.fb-step');
            if (fbSteps.length >= 2) {
                initFormBuilderMultiStep(form, fbSteps);
            }
        });
    });

    // ========================================================
    // Legacy multi-step (.zephyr-form)
    // ========================================================
    function initLegacyMultiStep(form) {
        var steps = form.querySelectorAll('.form-step');
        var dots = form.querySelectorAll('.step-dot');
        var lines = form.querySelectorAll('.step-line');
        var currentStep = 0;

        if (steps.length < 2) return;

        showStep(currentStep);

        // Next buttons
        form.querySelectorAll('.btn-step-next').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (validateCurrentStep()) {
                    currentStep++;
                    showStep(currentStep);
                }
            });
        });

        // Previous buttons
        form.querySelectorAll('.btn-step-prev').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (currentStep > 0) {
                    currentStep--;
                    showStep(currentStep);
                }
            });
        });

        // Step indicator clicks
        dots.forEach(function (dot, index) {
            dot.addEventListener('click', function () {
                if (index <= currentStep) {
                    currentStep = index;
                    showStep(currentStep);
                }
            });
        });

        function showStep(index) {
            steps.forEach(function (step, i) {
                step.classList.toggle('active', i === index);
            });

            dots.forEach(function (dot, i) {
                dot.classList.remove('active', 'completed');
                if (i === index) {
                    dot.classList.add('active');
                } else if (i < index) {
                    dot.classList.add('completed');
                }
            });

            lines.forEach(function (line, i) {
                line.classList.toggle('completed', i < index);
            });

            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function validateCurrentStep() {
            var step = steps[currentStep];
            if (!step) return true;

            var inputs = step.querySelectorAll('input[required], select[required], textarea[required]');
            var valid = true;

            inputs.forEach(function (input) {
                var feedback = input.parentNode.querySelector('.invalid-feedback');

                if (!input.value.trim()) {
                    valid = false;
                    input.classList.add('is-invalid');
                    if (!feedback) {
                        feedback = document.createElement('div');
                        feedback.className = 'invalid-feedback';
                        input.parentNode.appendChild(feedback);
                    }
                    feedback.textContent = 'This field is required.';
                } else {
                    input.classList.remove('is-invalid');
                    if (feedback) feedback.remove();
                }
            });

            return valid;
        }
    }

    // ========================================================
    // Form Builder multi-step (.fb-step)
    // ========================================================
    function initFormBuilderMultiStep(form, steps) {
        var indicator = form.querySelector('.fb-step-indicator');
        var dots = indicator ? indicator.querySelectorAll('.fb-step-dot') : [];
        var connectors = indicator ? indicator.querySelectorAll('.fb-step-connector') : [];
        var currentStep = 0;

        // Build navigation in each step
        steps.forEach(function (step, index) {
            var nav = step.querySelector('.fb-nav');
            if (!nav) {
                nav = document.createElement('div');
                nav.className = 'fb-nav';
                step.appendChild(nav);
            }

            nav.innerHTML = '';

            // Back button (not on first step)
            if (index > 0) {
                var backBtn = document.createElement('button');
                backBtn.type = 'button';
                backBtn.className = 'fb-btn fb-btn-secondary fb-step-back';
                backBtn.textContent = 'Back';
                nav.appendChild(backBtn);
            } else {
                var spacer = document.createElement('div');
                spacer.className = 'fb-nav-spacer';
                nav.appendChild(spacer);
            }

            if (index < steps.length - 1) {
                // Next button on non-last steps
                var nextBtn = document.createElement('button');
                nextBtn.type = 'button';
                nextBtn.className = 'fb-btn fb-step-next';
                nextBtn.textContent = 'Next';
                nav.appendChild(nextBtn);

                // Hide submit buttons in non-last steps
                step.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
                    btn.style.display = 'none';
                });
            } else {
                // Last step: ensure submit button is present
                var existingSubmit = step.querySelector('button[type="submit"], input[type="submit"]');
                if (!existingSubmit) {
                    var formSubmit = form.querySelector('button[type="submit"], input[type="submit"]');
                    if (formSubmit) {
                        nav.appendChild(formSubmit);
                    }
                }
            }
        });

        // Show first step
        goToStep(0);

        // Event delegation for navigation
        form.addEventListener('click', function (e) {
            if (e.target.classList.contains('fb-step-next')) {
                e.preventDefault();
                if (validateStep(currentStep)) {
                    goToStep(currentStep + 1);
                }
            } else if (e.target.classList.contains('fb-step-back')) {
                e.preventDefault();
                goToStep(currentStep - 1);
            }
        });

        // Clear error on input
        form.addEventListener('input', function (e) {
            var target = e.target;
            if (target.classList.contains('is-invalid')) {
                target.classList.remove('is-invalid');
                var error = target.parentNode.querySelector('.fb-error');
                if (error) error.remove();
            }
        });

        function goToStep(index) {
            if (index < 0 || index >= steps.length) return;

            steps.forEach(function (step) {
                step.classList.remove('active');
            });
            steps[index].classList.add('active');
            currentStep = index;

            updateIndicator(index);
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function updateIndicator(activeIndex) {
            dots.forEach(function (dot, i) {
                dot.classList.remove('active', 'completed');
                if (i === activeIndex) {
                    dot.classList.add('active');
                } else if (i < activeIndex) {
                    dot.classList.add('completed');
                }
            });

            connectors.forEach(function (conn, i) {
                conn.classList.remove('completed');
                if (i < activeIndex) {
                    conn.classList.add('completed');
                }
            });
        }

        function validateStep(stepIndex) {
            var step = steps[stepIndex];
            if (!step) return true;

            // Use shared validation utils if available
            var utils = window.fbFormUtils;
            var inputs = step.querySelectorAll('input, textarea, select');
            var isValid = true;
            var validated = {};

            // Clear previous errors in this step
            if (utils) {
                step.querySelectorAll('.is-invalid').forEach(function(el) {
                    utils.clearFieldError(el);
                });
            } else {
                step.querySelectorAll('.fb-error').forEach(function(el) { el.style.display = 'none'; el.textContent = ''; });
                step.querySelectorAll('.is-invalid').forEach(function(el) { el.classList.remove('is-invalid'); });
                step.querySelectorAll('.fb-field--error').forEach(function(el) { el.classList.remove('fb-field--error'); });
            }

            inputs.forEach(function(input) {
                if (input.type === 'hidden' || input.type === 'submit' || input.disabled) return;
                if (input.name === '_hp_field' || input.name === 'csrf_token') return;

                var name = input.name.replace('[]', '');
                if (validated[name]) return;

                if (utils) {
                    // Use shared client-side validation
                    if (input.type === 'radio') {
                        var anyChecked = false;
                        form.querySelectorAll('input[name="' + input.name + '"]').forEach(function(r) { if (r.checked) anyChecked = true; });
                        var groupEl = input.closest('.fb-radio-group');
                        var rulesStr = groupEl ? (groupEl.dataset.rules || '') : '';
                        if (rulesStr.indexOf('required') !== -1 && !anyChecked) {
                            utils.showFieldError(input, 'Please select an option.');
                            isValid = false;
                        }
                    } else {
                        var error = utils.validateInput(input);
                        if (error) {
                            utils.showFieldError(input, error);
                            isValid = false;
                        }
                    }
                } else {
                    // Fallback: HTML5 validation
                    if (!input.checkValidity()) {
                        isValid = false;
                        input.classList.add('is-invalid');
                        var field = input.closest('.fb-field');
                        if (field) field.classList.add('fb-field--error');
                        var errorEl = document.getElementById('fb-error-' + name);
                        if (errorEl) {
                            errorEl.textContent = input.validationMessage;
                            errorEl.style.display = '';
                        }
                    }
                }
                validated[name] = true;
            });

            if (!isValid) {
                var firstInvalid = step.querySelector('.is-invalid, .fb-field--error');
                if (firstInvalid) {
                    var focusEl = firstInvalid.querySelector('input, textarea, select') || firstInvalid;
                    if (focusEl.focus) focusEl.focus();
                }
            }

            return isValid;
        }
    }

})();
