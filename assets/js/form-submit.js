/**
 * AJAX Form Submission — Optional progressive enhancement
 * Include this script to enable AJAX form submissions with loading states.
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.zephyr-form[data-ajax="1"]').forEach(initAjaxForm);
    });

    function initAjaxForm(form) {
        var submitBtn = form.querySelector('.btn-submit');

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            // Clear previous errors
            form.querySelectorAll('.invalid-feedback').forEach(function(el) { el.remove(); });
            form.querySelectorAll('.is-invalid').forEach(function(el) { el.classList.remove('is-invalid'); });
            var alertEl = form.querySelector('.form-alert');
            if (alertEl) alertEl.remove();

            // Loading state
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
                resetButton();
                var response;
                try {
                    response = JSON.parse(xhr.responseText);
                } catch(err) {
                    showAlert(form, 'An unexpected error occurred.', 'error');
                    return;
                }

                if (response.success) {
                    if (response.redirect_url) {
                        window.location.href = response.redirect_url;
                        return;
                    }
                    showAlert(form, response.message || 'Submitted successfully!', 'success');
                    form.reset();
                } else {
                    if (response.errors) {
                        showFieldErrors(form, response.errors);
                    }
                    showAlert(form, response.message || 'Please fix the errors below.', 'error');
                }
            };

            xhr.onerror = function() {
                resetButton();
                showAlert(form, 'Network error. Please try again.', 'error');
            };

            xhr.send(formData);
        });

        function resetButton() {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = submitBtn.dataset.originalText || 'Submit';
            }
        }
    }

    function showAlert(form, message, type) {
        var existing = form.querySelector('.form-alert');
        if (existing) existing.remove();

        var alert = document.createElement('div');
        alert.className = 'form-alert form-alert-' + type;
        alert.textContent = message;
        form.insertBefore(alert, form.firstChild);
    }

    function showFieldErrors(form, errors) {
        for (var field in errors) {
            if (!errors.hasOwnProperty(field)) continue;
            if (field === '_form') continue;

            var input = form.querySelector('[name="' + field + '"]');
            if (!input) continue;

            input.classList.add('is-invalid');
            var feedback = document.createElement('div');
            feedback.className = 'invalid-feedback';
            feedback.textContent = Array.isArray(errors[field]) ? errors[field][0] : errors[field];
            input.parentNode.appendChild(feedback);
        }
    }
})();
