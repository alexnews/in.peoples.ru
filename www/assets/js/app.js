/**
 * in.peoples.ru — Application JavaScript
 *
 * Handles: person autocomplete, photo upload, draft autosave,
 * form validation, CSRF, logout, delete confirmation, section switching,
 * and submission filters.
 */
(function ($) {
    'use strict';

    /* ================================================================
       CSRF — attach token to every AJAX request
       ================================================================ */
    var csrfToken = $('meta[name="csrf-token"]').attr('content') || '';

    $.ajaxSetup({
        headers: { 'X-CSRF-Token': csrfToken }
    });

    function getCsrfToken() {
        return $('meta[name="csrf-token"]').attr('content') || csrfToken;
    }

    /* ================================================================
       PERSON AUTOCOMPLETE
       ================================================================ */
    var acTimer = null;

    function initPersonAutocomplete() {
        var $input = $('#person-search');
        if (!$input.length) return;

        var $dropdown = $('#person-autocomplete-dropdown');
        var $hidden = $('#KodPersons');
        var $selected = $('#selected-person');
        var activeIdx = -1;

        $input.on('input', function () {
            var q = $.trim($input.val());
            activeIdx = -1;
            if (q.length < 2) {
                $dropdown.removeClass('show').empty();
                return;
            }
            clearTimeout(acTimer);
            acTimer = setTimeout(function () {
                $.ajax({
                    url: '/api/v1/persons/search.php',
                    data: { q: q, limit: 8 },
                    dataType: 'json',
                    success: function (resp) {
                        if (!resp.success || !resp.data || !resp.data.length) {
                            $dropdown.removeClass('show').empty();
                            return;
                        }
                        renderAutocomplete(resp.data, $dropdown);
                    }
                });
            }, 300);
        });

        $input.on('keydown', function (e) {
            var $items = $dropdown.find('.person-autocomplete-item');
            if (!$items.length) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIdx = Math.min(activeIdx + 1, $items.length - 1);
                $items.removeClass('active').eq(activeIdx).addClass('active');
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIdx = Math.max(activeIdx - 1, 0);
                $items.removeClass('active').eq(activeIdx).addClass('active');
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (activeIdx >= 0) {
                    $items.eq(activeIdx).trigger('click');
                }
            } else if (e.key === 'Escape') {
                $dropdown.removeClass('show').empty();
            }
        });

        // Close on outside click
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.person-autocomplete-wrapper').length) {
                $dropdown.removeClass('show').empty();
            }
        });

        // Handle remove selected person
        $(document).on('click', '#selected-person .btn-remove', function () {
            $hidden.val('');
            $selected.hide().empty();
            $input.val('').prop('disabled', false).focus();
        });
    }

    var ruMonths = ['', '\u044f\u043d\u0432\u0430\u0440\u044f', '\u0444\u0435\u0432\u0440\u0430\u043b\u044f', '\u043c\u0430\u0440\u0442\u0430', '\u0430\u043f\u0440\u0435\u043b\u044f', '\u043c\u0430\u044f', '\u0438\u044e\u043d\u044f', '\u0438\u044e\u043b\u044f', '\u0430\u0432\u0433\u0443\u0441\u0442\u0430', '\u0441\u0435\u043d\u0442\u044f\u0431\u0440\u044f', '\u043e\u043a\u0442\u044f\u0431\u0440\u044f', '\u043d\u043e\u044f\u0431\u0440\u044f', '\u0434\u0435\u043a\u0430\u0431\u0440\u044f'];

    function formatRuDate(dateStr) {
        if (!dateStr) return '';
        var parts = dateStr.split('-');
        if (parts.length !== 3) return dateStr;
        var day = parseInt(parts[2], 10);
        var month = parseInt(parts[1], 10);
        return day + ' ' + (ruMonths[month] || '') + ' ' + parts[0];
    }

    function renderAutocomplete(items, $dropdown) {
        $dropdown.empty();
        $.each(items, function (i, p) {
            var photoUrl = (p.photo && p.path) ? p.path + p.photo : '';
            var dates = '';
            if (p.dates && p.dates.birth) {
                dates = formatRuDate(p.dates.birth);
                if (p.dates.death) dates += ' \u2014 ' + formatRuDate(p.dates.death);
            }
            var $item = $('<div class="person-autocomplete-item" />')
                .attr('data-id', p.id)
                .attr('data-name', p.name)
                .attr('data-dates', dates)
                .attr('data-photo', photoUrl);

            if (photoUrl) {
                $item.append('<img src="' + photoUrl + '" alt="" />');
            } else {
                $item.append('<img src="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'40\' height=\'40\'%3E%3Crect fill=\'%23ddd\' width=\'40\' height=\'40\'/%3E%3C/svg%3E" alt="" />');
            }
            $item.append(
                '<div class="person-info">' +
                    '<div class="person-name">' + escHtml(p.name) + '</div>' +
                    (dates ? '<div class="person-dates">' + escHtml(dates) + '</div>' : '') +
                    (p.epigraph ? '<div class="person-epigraph">' + escHtml(p.epigraph) + '</div>' : '') +
                '</div>'
            );
            $dropdown.append($item);
        });

        // "Suggest new person" link at the bottom
        var $suggest = $('<div class="person-autocomplete-suggest" />')
            .html('<i class="bi bi-person-plus me-1"></i>Не нашли? <a href="/user/suggest-person.php">Предложить новую персону</a>');
        $dropdown.append($suggest);

        $dropdown.addClass('show');

        $dropdown.find('.person-autocomplete-item').on('click', function () {
            var $el = $(this);
            selectPerson($el.data('id'), $el.data('name'), $el.data('dates'), $el.data('photo'));
            $dropdown.removeClass('show').empty();
        });
    }

    function selectPerson(id, name, dates, photo) {
        $('#KodPersons').val(id);
        var $input = $('#person-search');
        $input.val(name).prop('disabled', true);

        var imgTag = photo
            ? '<img src="' + photo + '" alt="" />'
            : '';
        var html = '<div class="selected-person-card">' +
            imgTag +
            '<div class="info">' +
                '<div class="name">' + escHtml(name) + '</div>' +
                (dates ? '<div class="dates">' + escHtml(dates) + '</div>' : '') +
            '</div>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary btn-remove" title="Убрать">&times;</button>' +
        '</div>';
        $('#selected-person').html(html).show();
    }

    /* ================================================================
       PHOTO UPLOAD with drag-and-drop
       ================================================================ */
    function initPhotoUpload() {
        var $zone = $('#photo-dropzone');
        if (!$zone.length) return;

        var $fileInput = $('#photo-file-input');
        var $grid = $('#photo-preview-grid');

        // Click on zone opens file dialog
        $zone.on('click', function () {
            $fileInput.trigger('click');
        });

        $fileInput.on('change', function () {
            handleFiles(this.files);
            $fileInput.val(''); // reset so same file can be re-selected
        });

        // Drag events
        $zone.on('dragover dragenter', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $zone.addClass('dragover');
        });
        $zone.on('dragleave drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $zone.removeClass('dragover');
        });
        $zone.on('drop', function (e) {
            var files = e.originalEvent.dataTransfer.files;
            handleFiles(files);
        });
    }

    function handleFiles(files) {
        if (!files || !files.length) return;
        for (var i = 0; i < files.length; i++) {
            if (!files[i].type.match(/^image\//)) continue;
            previewAndUpload(files[i]);
        }
    }

    function previewAndUpload(file) {
        var $grid = $('#photo-preview-grid');
        var itemId = 'photo-' + Date.now() + '-' + Math.random().toString(36).substr(2, 5);

        // Create preview element
        var $item = $(
            '<div class="photo-preview-item" id="' + itemId + '">' +
                '<img src="" alt="" />' +
                '<button type="button" class="remove-btn" title="Удалить">&times;</button>' +
                '<input type="text" class="caption-input" placeholder="Подпись к фото..." />' +
                '<div class="photo-upload-progress"><div class="bar"></div></div>' +
            '</div>'
        );
        $grid.append($item);

        // Show local preview
        var reader = new FileReader();
        reader.onload = function (e) {
            $item.find('img').attr('src', e.target.result);
        };
        reader.readAsDataURL(file);

        // Upload
        var submissionId = getSubmissionId();
        if (!submissionId) {
            // Need to save draft first to get a submission ID
            $item.find('.photo-upload-progress .bar').css({ width: '100%', background: '#ffc107' });
            $item.attr('data-pending-file', 'true');
            // Store file reference for later
            $item.data('file', file);
            return;
        }

        uploadFile(file, submissionId, $item);
    }

    function uploadFile(file, submissionId, $item) {
        var fd = new FormData();
        fd.append('photo', file);
        fd.append('submission_id', submissionId);
        fd.append('csrf_token', getCsrfToken());

        $.ajax({
            url: '/api/v1/photos/upload.php',
            type: 'POST',
            data: fd,
            contentType: false,
            processData: false,
            xhr: function () {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function (e) {
                    if (e.lengthComputable) {
                        var pct = Math.round(e.loaded / e.total * 100);
                        $item.find('.photo-upload-progress .bar').css('width', pct + '%');
                    }
                });
                return xhr;
            },
            success: function (resp) {
                if (resp.success) {
                    $item.find('.photo-upload-progress').fadeOut(300);
                    var data = resp.data;
                    if (data.thumb_path) {
                        $item.find('img').attr('src', data.thumb_path);
                    }
                    $item.attr('data-file-path', data.file_path || '');
                }
            },
            error: function (xhr) {
                var msg = 'Ошибка загрузки';
                try { msg = JSON.parse(xhr.responseText).error.message; } catch (e) {}
                $item.find('.photo-upload-progress .bar').css({ width: '100%', background: '#dc3545' });
                alert(msg);
            }
        });
    }

    function getSubmissionId() {
        return $('#submission-id').val() || '';
    }

    // Upload pending files after submission save
    function uploadPendingPhotos(submissionId) {
        $('#photo-preview-grid .photo-preview-item[data-pending-file="true"]').each(function () {
            var $item = $(this);
            var file = $item.data('file');
            if (file) {
                $item.removeAttr('data-pending-file');
                uploadFile(file, submissionId, $item);
            }
        });
    }

    // Remove photo
    $(document).on('click', '.photo-preview-item .remove-btn', function () {
        var $item = $(this).closest('.photo-preview-item');
        var filePath = $item.attr('data-file-path');
        if (filePath) {
            var subId = getSubmissionId();
            if (subId) {
                $.ajax({
                    url: '/api/v1/photos/delete.php',
                    type: 'POST',
                    data: JSON.stringify({ submission_id: subId, csrf_token: getCsrfToken() }),
                    contentType: 'application/json'
                });
            }
        }
        $item.fadeOut(200, function () { $(this).remove(); });
    });

    /* ================================================================
       DRAFT AUTOSAVE (every 60s if form dirty)
       ================================================================ */
    var formDirty = false;
    var autosaveTimer = null;
    var lastSavedData = '';

    function initAutosave() {
        var $form = $('#submit-form');
        if (!$form.length) return;

        $form.on('change input', 'input, select, textarea', function () {
            formDirty = true;
        });

        autosaveTimer = setInterval(function () {
            if (!formDirty) return;
            var currentData = getFormData();
            if (currentData === lastSavedData) return;
            saveDraft();
        }, 60000);
    }

    function getFormData() {
        var data = {
            section_id: $('#section_id').val(),
            KodPersons: $('#KodPersons').val(),
            title: $('#title').val(),
            epigraph: $('#epigraph').val(),
            source_url: $('#source_url').val()
        };
        // TinyMCE content
        if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
            data.content = tinymce.get('content').getContent();
        } else {
            data.content = $('#content').val();
        }
        return JSON.stringify(data);
    }

    function saveDraft(callback) {
        var data = {
            section_id: $('#section_id').val(),
            KodPersons: $('#KodPersons').val() || null,
            title: $('#title').val(),
            epigraph: $('#epigraph').val(),
            source_url: $('#source_url').val(),
            status: 'draft',
            csrf_token: getCsrfToken()
        };

        if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
            data.content = tinymce.get('content').getContent();
        } else {
            data.content = $('#content').val();
        }

        // Minimal validation
        if (!data.title || !data.section_id) {
            if (callback) callback(false);
            return;
        }
        if (!data.content) data.content = ' '; // API requires non-empty content

        var subId = getSubmissionId();
        var url, method;

        if (subId) {
            url = '/api/v1/submissions/update.php';
            data.id = subId;
        } else {
            url = '/api/v1/submissions/index.php';
        }

        $.ajax({
            url: url,
            type: 'POST',
            data: JSON.stringify(data),
            contentType: 'application/json',
            dataType: 'json',
            success: function (resp) {
                if (resp.success) {
                    formDirty = false;
                    lastSavedData = getFormData();
                    if (!subId && resp.data && resp.data.id) {
                        $('#submission-id').val(resp.data.id);
                        uploadPendingPhotos(resp.data.id);
                    }
                    showAutosaveIndicator();
                    if (callback) callback(true, resp.data);
                }
            },
            error: function () {
                if (callback) callback(false);
            }
        });
    }

    function showAutosaveIndicator() {
        var $ind = $('#autosave-indicator');
        if (!$ind.length) return;
        var now = new Date();
        var time = ('0' + now.getHours()).slice(-2) + ':' + ('0' + now.getMinutes()).slice(-2);
        $ind.text('Черновик сохранён в ' + time).addClass('saved');
        setTimeout(function () { $ind.removeClass('saved'); }, 3000);
    }

    /* ================================================================
       FORM VALIDATION & SUBMISSION
       ================================================================ */
    function initSubmitForm() {
        var $form = $('#submit-form');
        if (!$form.length) return;

        // Save Draft button
        $('#btn-save-draft').on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            $btn.prop('disabled', true).text('Сохранение...');
            saveDraft(function (ok, data) {
                $btn.prop('disabled', false).text('Сохранить черновик');
                if (ok) {
                    showFlash('Черновик сохранён', 'success');
                } else {
                    showFlash('Не удалось сохранить черновик. Заполните название и раздел.', 'danger');
                }
            });
        });

        // Submit for review button
        $('#btn-submit-review').on('click', function (e) {
            e.preventDefault();
            if (!validateSubmitForm()) return;

            var $btn = $(this);
            $btn.prop('disabled', true).text('Отправка...');

            var data = {
                section_id: $('#section_id').val(),
                KodPersons: $('#KodPersons').val() || null,
                title: $('#title').val(),
                epigraph: $('#epigraph').val(),
                source_url: $('#source_url').val(),
                status: 'pending',
                csrf_token: getCsrfToken()
            };

            if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
                data.content = tinymce.get('content').getContent();
            } else {
                data.content = $('#content').val();
            }

            var subId = getSubmissionId();
            var url;
            if (subId) {
                url = '/api/v1/submissions/update.php';
                data.id = subId;
            } else {
                url = '/api/v1/submissions/index.php';
            }

            $.ajax({
                url: url,
                type: 'POST',
                data: JSON.stringify(data),
                contentType: 'application/json',
                dataType: 'json',
                success: function (resp) {
                    if (resp.success) {
                        window.location.href = '/user/submissions.php';
                    }
                },
                error: function (xhr) {
                    $btn.prop('disabled', false).text('Отправить на проверку');
                    var msg = 'Ошибка при отправке';
                    try {
                        var r = JSON.parse(xhr.responseText);
                        msg = r.error.message;
                        if (r.error.fields) {
                            $.each(r.error.fields, function (field, err) {
                                var $f = $('#' + field);
                                $f.addClass('is-invalid');
                                $f.next('.invalid-feedback').text(err);
                            });
                        }
                    } catch (e) {}
                    showFlash(msg, 'danger');
                }
            });
        });
    }

    function validateSubmitForm() {
        var valid = true;
        // Clear previous errors
        $('#submit-form .is-invalid').removeClass('is-invalid');

        if (!$('#section_id').val()) {
            $('#section_id').addClass('is-invalid');
            valid = false;
        }
        if (!$.trim($('#title').val())) {
            $('#title').addClass('is-invalid');
            valid = false;
        }

        var sectionId = parseInt($('#section_id').val(), 10);
        // Sections that don't require body content (photo=3)
        if (sectionId !== 3) {
            var content = '';
            if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
                content = tinymce.get('content').getContent({ format: 'text' });
            } else {
                content = $.trim($('#content').val());
            }
            if (!content) {
                $('#content').addClass('is-invalid');
                valid = false;
            }
        }

        if (!valid) {
            showFlash('Пожалуйста, заполните обязательные поля', 'warning');
        }
        return valid;
    }

    /* ================================================================
       SECTION CHANGE HANDLER — show/hide fields
       ================================================================ */
    function initSectionToggle() {
        var $sel = $('#section_id');
        if (!$sel.length) return;

        $sel.on('change', function () {
            toggleSectionFields(parseInt($(this).val(), 10));
        });
        // Apply on load
        toggleSectionFields(parseInt($sel.val(), 10) || 0);
    }

    function toggleSectionFields(sectionId) {
        var $contentGroup = $('#content-group');
        var $photoGroup = $('#photo-upload-group');
        var $epigraphGroup = $('#epigraph-group');

        if (sectionId === 3) {
            // Photo section: hide content editor, show photo upload prominently
            $contentGroup.hide();
            $photoGroup.show().addClass('photo-prominent');
        } else {
            $contentGroup.show();
            $photoGroup.show().removeClass('photo-prominent');
        }

        // Show epigraph for all sections
        $epigraphGroup.show();
    }

    /* ================================================================
       LOGOUT
       ================================================================ */
    function initLogout() {
        $(document).on('click', '#btn-logout', function (e) {
            e.preventDefault();
            $.ajax({
                url: '/api/v1/auth/logout.php',
                type: 'POST',
                data: JSON.stringify({ csrf_token: getCsrfToken() }),
                contentType: 'application/json',
                success: function () {
                    window.location.href = '/user/login.php';
                },
                error: function () {
                    // Force redirect even on error
                    window.location.href = '/user/login.php';
                }
            });
        });
    }

    /* ================================================================
       DELETE CONFIRMATION (submissions list)
       ================================================================ */
    function initDeleteConfirmation() {
        var deleteId = null;

        $(document).on('click', '.btn-delete-submission', function () {
            deleteId = $(this).data('id');
            var title = $(this).data('title') || '';
            $('#delete-modal-title').text(title);
            var modal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
            modal.show();
        });

        $('#btn-confirm-delete').on('click', function () {
            if (!deleteId) return;
            var $btn = $(this);
            $btn.prop('disabled', true);

            $.ajax({
                url: '/api/v1/submissions/delete.php',
                type: 'POST',
                data: JSON.stringify({ id: deleteId, csrf_token: getCsrfToken() }),
                contentType: 'application/json',
                dataType: 'json',
                success: function (resp) {
                    if (resp.success) {
                        bootstrap.Modal.getInstance(document.getElementById('deleteConfirmModal')).hide();
                        $('tr[data-id="' + deleteId + '"]').fadeOut(300, function () { $(this).remove(); });
                        showFlash('Материал удалён', 'success');
                    }
                },
                error: function (xhr) {
                    var msg = 'Ошибка удаления';
                    try { msg = JSON.parse(xhr.responseText).error.message; } catch (e) {}
                    showFlash(msg, 'danger');
                },
                complete: function () {
                    $btn.prop('disabled', false);
                    deleteId = null;
                }
            });
        });
    }

    /* ================================================================
       SUBMISSIONS LIST — filter & load
       ================================================================ */
    function initSubmissionsList() {
        var $table = $('#submissions-table-body');
        if (!$table.length) return;

        loadSubmissions();

        // Filters
        $('#filter-status, #filter-section').on('change', function () {
            loadSubmissions();
        });
        $('#filter-search').on('input', debounce(function () {
            loadSubmissions();
        }, 400));

        // Pagination
        $(document).on('click', '.pagination .page-link[data-page]', function (e) {
            e.preventDefault();
            var page = $(this).data('page');
            loadSubmissions(page);
        });
    }

    function loadSubmissions(page) {
        page = page || 1;
        var params = {
            page: page,
            per_page: 20
        };

        var status = $('#filter-status').val();
        if (status) params.status = status;

        var section = $('#filter-section').val();
        if (section) params.section_id = section;

        var $table = $('#submissions-table-body');
        $table.html('<tr><td colspan="6" class="text-center py-3"><div class="spinner-border spinner-border-sm text-muted" role="status"></div></td></tr>');

        $.ajax({
            url: '/api/v1/submissions/index.php',
            data: params,
            dataType: 'json',
            success: function (resp) {
                if (!resp.success) return;
                renderSubmissionsTable(resp.data, resp.pagination);
            },
            error: function () {
                $table.html('<tr><td colspan="6" class="text-center text-danger py-3">Ошибка загрузки</td></tr>');
            }
        });
    }

    var sectionIcons = {
        2: 'bi-book',
        3: 'bi-camera',
        4: 'bi-newspaper',
        5: 'bi-chat-quote',
        7: 'bi-lightbulb',
        8: 'bi-star',
        19: 'bi-file-text'
    };

    var statusLabels = {
        draft: 'Черновик',
        pending: 'На проверке',
        approved: 'Опубликовано',
        rejected: 'Отклонено',
        revision_requested: 'На доработку'
    };

    var statusBadgeClass = {
        draft: 'badge-draft',
        pending: 'badge-pending',
        approved: 'badge-approved',
        rejected: 'badge-rejected',
        revision_requested: 'badge-revision'
    };

    function renderSubmissionsTable(items, pagination) {
        var $table = $('#submissions-table-body');
        $table.empty();

        if (!items || !items.length) {
            $table.html('<tr><td colspan="6" class="text-center text-muted py-4">Нет материалов</td></tr>');
            renderPagination(pagination);
            return;
        }

        $.each(items, function (i, item) {
            var icon = sectionIcons[item.section_id] || 'bi-file-text';
            var statusLabel = statusLabels[item.status] || item.status;
            var badgeClass = statusBadgeClass[item.status] || 'badge-draft';
            var personName = item.person_name || '—';
            var dateStr = item.created_at ? item.created_at.substr(0, 10) : '';
            var canEdit = (item.status === 'draft' || item.status === 'revision_requested');
            var canDelete = (item.status === 'draft' || item.status === 'pending');

            var tr = '<tr data-id="' + item.id + '">' +
                '<td><span class="section-icon"><i class="bi ' + icon + '"></i></span></td>' +
                '<td><a href="/user/view.php?id=' + item.id + '">' + escHtml(item.title || 'Без названия') + '</a></td>' +
                '<td>' + escHtml(personName) + '</td>' +
                '<td><span class="badge ' + badgeClass + '">' + statusLabel + '</span></td>' +
                '<td>' + escHtml(dateStr) + '</td>' +
                '<td class="text-nowrap">';

            if (canEdit) {
                tr += '<a href="/user/submit.php?edit=' + item.id + '" class="btn btn-sm btn-outline-secondary me-1" title="Редактировать"><i class="bi bi-pencil"></i></a>';
            }
            if (canDelete) {
                tr += '<button class="btn btn-sm btn-outline-danger btn-delete-submission" data-id="' + item.id + '" data-title="' + escAttr(item.title || '') + '" title="Удалить"><i class="bi bi-trash"></i></button>';
            }

            tr += '</td></tr>';
            $table.append(tr);
        });

        renderPagination(pagination);
    }

    function renderPagination(pagination) {
        var $pag = $('#submissions-pagination');
        if (!$pag.length || !pagination) return;
        $pag.empty();

        if (pagination.pages <= 1) return;

        var html = '<nav><ul class="pagination pagination-sm justify-content-center">';

        // Previous
        if (pagination.page > 1) {
            html += '<li class="page-item"><a class="page-link" data-page="' + (pagination.page - 1) + '" href="#">&laquo;</a></li>';
        }

        for (var p = 1; p <= pagination.pages; p++) {
            if (pagination.pages > 7 && Math.abs(p - pagination.page) > 2 && p !== 1 && p !== pagination.pages) {
                if (p === 2 || p === pagination.pages - 1) {
                    html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
                continue;
            }
            html += '<li class="page-item ' + (p === pagination.page ? 'active' : '') + '"><a class="page-link" data-page="' + p + '" href="#">' + p + '</a></li>';
        }

        // Next
        if (pagination.page < pagination.pages) {
            html += '<li class="page-item"><a class="page-link" data-page="' + (pagination.page + 1) + '" href="#">&raquo;</a></li>';
        }

        html += '</ul></nav>';
        $pag.html(html);
    }

    /* ================================================================
       UTILITIES
       ================================================================ */
    function escHtml(str) {
        if (!str) return '';
        return $('<span/>').text(str).html();
    }

    function escAttr(str) {
        if (!str) return '';
        return str.replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function debounce(fn, delay) {
        var timer;
        return function () {
            var context = this, args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(context, args); }, delay);
        };
    }

    function showFlash(message, type) {
        type = type || 'info';
        var $container = $('#flash-messages');
        if (!$container.length) {
            $container = $('<div id="flash-messages" class="position-fixed top-0 end-0 p-3" style="z-index:1080;"></div>');
            $('body').append($container);
        }
        var $alert = $('<div class="alert alert-' + type + ' alert-dismissible fade show flash-message" role="alert">' +
            escHtml(message) +
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
        '</div>');
        $container.append($alert);
        setTimeout(function () { $alert.alert('close'); }, 5000);
    }

    // Make showFlash available globally for PHP pages
    window.showFlash = showFlash;

    /* ================================================================
       INITIALIZATION
       ================================================================ */
    $(document).ready(function () {
        initPersonAutocomplete();
        initPhotoUpload();
        initAutosave();
        initSubmitForm();
        initSectionToggle();
        initLogout();
        initDeleteConfirmation();
        initSubmissionsList();
    });

})(jQuery);
