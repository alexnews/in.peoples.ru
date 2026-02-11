/**
 * Booking Pages JavaScript — peoples.ru
 *
 * Handles: form submission, person search autocomplete, bot protection, validation.
 */

(function () {
    'use strict';

    // ========================================================================
    // Bot protection token — generated after page load delay
    // ========================================================================

    var botToken = '';

    setTimeout(function () {
        botToken = 'ok_' + Date.now();
    }, 2000);

    // ========================================================================
    // Person search autocomplete (hero search)
    // ========================================================================

    var heroSearch = document.getElementById('heroPersonSearch');
    var heroResults = document.getElementById('heroSearchResults');
    var searchTimeout = null;

    if (heroSearch && heroResults) {
        heroSearch.addEventListener('input', function () {
            var q = this.value.trim();
            clearTimeout(searchTimeout);

            if (q.length < 2) {
                heroResults.style.display = 'none';
                return;
            }

            searchTimeout = setTimeout(function () {
                fetch('/api/v1/persons/search.php?q=' + encodeURIComponent(q) + '&limit=8')
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success || !data.data.length) {
                            heroResults.style.display = 'none';
                            return;
                        }

                        var html = '';
                        data.data.forEach(function (p) {
                            html += '<a href="/booking/person/' + p.id + '/" ' +
                                'class="list-group-item list-group-item-action d-flex align-items-center py-2">' +
                                '<strong class="me-2">' + escapeHtml(p.name || '') + '</strong>' +
                                '<small class="text-muted">' + escapeHtml(p.famous_for || '') + '</small>' +
                                '</a>';
                        });

                        heroResults.innerHTML = html;
                        heroResults.style.display = 'block';
                    })
                    .catch(function () {
                        heroResults.style.display = 'none';
                    });
            }, 300);
        });

        // Close on outside click
        document.addEventListener('click', function (e) {
            if (!heroSearch.contains(e.target) && !heroResults.contains(e.target)) {
                heroResults.style.display = 'none';
            }
        });
    }

    // ========================================================================
    // Booking form submission
    // ========================================================================

    var bookingForms = document.querySelectorAll('.booking-request-form');

    bookingForms.forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var submitBtn = form.querySelector('[type="submit"]');
            var resultDiv = form.querySelector('.form-result');

            // Collect form data
            var data = {
                person_id: form.querySelector('[name="person_id"]')?.value || null,
                booking_person_id: form.querySelector('[name="booking_person_id"]')?.value || null,
                client_name: (form.querySelector('[name="client_name"]')?.value || '').trim(),
                client_phone: (form.querySelector('[name="client_phone"]')?.value || '').trim(),
                client_email: (form.querySelector('[name="client_email"]')?.value || '').trim(),
                client_company: (form.querySelector('[name="client_company"]')?.value || '').trim(),
                event_type: form.querySelector('[name="event_type"]')?.value || '',
                event_date: form.querySelector('[name="event_date"]')?.value || '',
                event_city: (form.querySelector('[name="event_city"]')?.value || '').trim(),
                event_venue: (form.querySelector('[name="event_venue"]')?.value || '').trim(),
                guest_count: form.querySelector('[name="guest_count"]')?.value || null,
                budget_from: form.querySelector('[name="budget_from"]')?.value || null,
                budget_to: form.querySelector('[name="budget_to"]')?.value || null,
                message: (form.querySelector('[name="message"]')?.value || '').trim(),
                website: form.querySelector('[name="website"]')?.value || '',
                bot_token: botToken
            };

            // Client-side validation
            var errors = [];
            if (!data.client_name || data.client_name.length < 2) {
                errors.push('Укажите ваше имя');
            }
            if (!data.client_phone || data.client_phone.length < 6) {
                errors.push('Укажите номер телефона');
            }

            if (errors.length) {
                showFormResult(resultDiv, errors.join('. '), 'danger');
                return;
            }

            // Disable button
            submitBtn.disabled = true;
            var origText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Отправка...';

            fetch('/api/v1/booking/request.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            })
                .then(function (r) { return r.json(); })
                .then(function (result) {
                    if (result.success) {
                        showFormResult(resultDiv, result.data.message, 'success');
                        form.reset();
                        // Re-set hidden fields
                        if (data.person_id) {
                            var pidField = form.querySelector('[name="person_id"]');
                            if (pidField) pidField.value = data.person_id;
                        }
                        if (data.booking_person_id) {
                            var bpidField = form.querySelector('[name="booking_person_id"]');
                            if (bpidField) bpidField.value = data.booking_person_id;
                        }
                    } else {
                        var msg = result.error?.message || 'Произошла ошибка. Попробуйте позже.';
                        showFormResult(resultDiv, msg, 'danger');
                    }
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = origText;
                })
                .catch(function () {
                    showFormResult(resultDiv, 'Ошибка сети. Проверьте соединение и попробуйте снова.', 'danger');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = origText;
                });
        });
    });

    // ========================================================================
    // Celebrity registration form
    // ========================================================================

    var registerForms = document.querySelectorAll('.booking-register-form');

    registerForms.forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var submitBtn = form.querySelector('[type="submit"]');
            var resultDiv = form.querySelector('.form-result');

            var data = {
                full_name: (form.querySelector('[name="full_name"]')?.value || '').trim(),
                phone: (form.querySelector('[name="phone"]')?.value || '').trim(),
                email: (form.querySelector('[name="email"]')?.value || '').trim(),
                city: (form.querySelector('[name="city"]')?.value || '').trim(),
                category_id: form.querySelector('[name="category_id"]')?.value || null,
                activity_description: (form.querySelector('[name="activity_description"]')?.value || '').trim(),
                website: form.querySelector('[name="website"]')?.value || '',
                bot_token: botToken
            };

            // Client-side validation
            var errors = [];
            if (!data.full_name || data.full_name.length < 2) {
                errors.push('Укажите ваше имя');
            }
            if (!data.phone || data.phone.length < 6) {
                errors.push('Укажите номер телефона');
            }

            if (errors.length) {
                showFormResult(resultDiv, errors.join('. '), 'danger');
                return;
            }

            submitBtn.disabled = true;
            var origText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Отправка...';

            fetch('/api/v1/booking/register.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            })
                .then(function (r) { return r.json(); })
                .then(function (result) {
                    if (result.success) {
                        showFormResult(resultDiv, result.data.message, 'success');
                        form.reset();
                    } else {
                        var msg = result.error?.message || 'Произошла ошибка. Попробуйте позже.';
                        showFormResult(resultDiv, msg, 'danger');
                    }
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = origText;
                })
                .catch(function () {
                    showFormResult(resultDiv, 'Ошибка сети. Проверьте соединение и попробуйте снова.', 'danger');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = origText;
                });
        });
    });

    // ========================================================================
    // Info change form — person search + submission
    // ========================================================================

    var infoChangeForms = document.querySelectorAll('.info-change-form');

    infoChangeForms.forEach(function (form) {
        var personSearch = form.querySelector('[name="person_search"]');
        var personIdField = form.querySelector('[name="person_id"]');
        var personManualField = form.querySelector('[name="person_name_manual"]');
        var searchResults = form.querySelector('.person-search-results');
        var personSearchTimeout = null;

        if (personSearch && searchResults) {
            personSearch.addEventListener('input', function () {
                var q = this.value.trim();
                clearTimeout(personSearchTimeout);

                // Reset person_id when typing
                if (personIdField) personIdField.value = '';
                if (personManualField) personManualField.value = q;

                if (q.length < 2) {
                    searchResults.style.display = 'none';
                    return;
                }

                personSearchTimeout = setTimeout(function () {
                    fetch('/api/v1/persons/search.php?q=' + encodeURIComponent(q) + '&limit=8')
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (!data.success || !data.data.length) {
                                searchResults.innerHTML = '<div class="list-group-item text-muted small">Не найдено — имя будет отправлено как есть</div>';
                                searchResults.style.display = 'block';
                                return;
                            }

                            var html = '';
                            data.data.forEach(function (p) {
                                html += '<a href="#" class="list-group-item list-group-item-action person-pick-item py-2" ' +
                                    'data-person-id="' + p.id + '" data-person-name="' + escapeHtml(p.name || '') + '">' +
                                    '<strong>' + escapeHtml(p.name || '') + '</strong> ' +
                                    '<small class="text-muted">' + escapeHtml(p.famous_for || '') + '</small>' +
                                    '</a>';
                            });

                            searchResults.innerHTML = html;
                            searchResults.style.display = 'block';

                            searchResults.querySelectorAll('.person-pick-item').forEach(function (item) {
                                item.addEventListener('click', function (e) {
                                    e.preventDefault();
                                    if (personIdField) personIdField.value = this.dataset.personId;
                                    if (personManualField) personManualField.value = '';
                                    personSearch.value = this.dataset.personName;
                                    searchResults.style.display = 'none';
                                });
                            });
                        })
                        .catch(function () {
                            searchResults.style.display = 'none';
                        });
                }, 300);
            });

            // Close on outside click
            document.addEventListener('click', function (e) {
                if (!personSearch.contains(e.target) && !searchResults.contains(e.target)) {
                    searchResults.style.display = 'none';
                }
            });
        }

        // Form submission
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var submitBtn = form.querySelector('[type="submit"]');
            var resultDiv = form.querySelector('.form-result');

            // Collect checked change_fields
            var changeFields = [];
            form.querySelectorAll('[name="change_fields[]"]:checked').forEach(function (cb) {
                changeFields.push(cb.value);
            });

            var data = {
                person_id: (form.querySelector('[name="person_id"]')?.value || '').trim() || null,
                person_name_manual: (form.querySelector('[name="person_name_manual"]')?.value || '').trim() || null,
                requester_name: (form.querySelector('[name="requester_name"]')?.value || '').trim(),
                requester_phone: (form.querySelector('[name="requester_phone"]')?.value || '').trim(),
                requester_email: (form.querySelector('[name="requester_email"]')?.value || '').trim(),
                requester_role: form.querySelector('[name="requester_role"]')?.value || 'other',
                change_fields: changeFields.join(','),
                description: (form.querySelector('[name="description"]')?.value || '').trim(),
                evidence_url: (form.querySelector('[name="evidence_url"]')?.value || '').trim(),
                website: form.querySelector('[name="website"]')?.value || '',
                bot_token: botToken
            };

            // Client-side validation
            var errors = [];
            if (!data.requester_name || data.requester_name.length < 2) {
                errors.push('Укажите ваше имя');
            }
            if (!data.requester_phone || data.requester_phone.length < 6) {
                errors.push('Укажите номер телефона');
            }
            if (!data.description || data.description.length < 10) {
                errors.push('Опишите изменения (минимум 10 символов)');
            }

            if (errors.length) {
                showFormResult(resultDiv, errors.join('. '), 'danger');
                return;
            }

            submitBtn.disabled = true;
            var origText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Отправка...';

            fetch('/api/v1/info-change/submit.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            })
                .then(function (r) { return r.json(); })
                .then(function (result) {
                    if (result.success) {
                        showFormResult(resultDiv, result.data.message, 'success');
                        form.reset();
                    } else {
                        var msg = result.error?.message || 'Произошла ошибка. Попробуйте позже.';
                        showFormResult(resultDiv, msg, 'danger');
                    }
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = origText;
                })
                .catch(function () {
                    showFormResult(resultDiv, 'Ошибка сети. Проверьте соединение и попробуйте снова.', 'danger');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = origText;
                });
        });
    });

    // ========================================================================
    // Helpers
    // ========================================================================

    function showFormResult(el, message, type) {
        if (!el) return;
        el.className = 'form-result alert alert-' + type + ' mt-3';
        el.textContent = message;
        el.style.display = 'block';
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})();
