/**
 * Moderation Panel JavaScript — in.peoples.ru
 *
 * Handles keyboard shortcuts, bulk actions, AJAX review actions,
 * user management actions, and auto-refresh of queue badge.
 */

(function () {
    'use strict';

    // ========================================================================
    // CSRF Token
    // ========================================================================

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // ========================================================================
    // Toast Notifications
    // ========================================================================

    function showToast(message, type) {
        type = type || 'success';
        var container = document.querySelector('.mod-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'mod-toast-container';
            document.body.appendChild(container);
        }

        var iconMap = {
            success: 'bi-check-circle-fill',
            danger: 'bi-exclamation-triangle-fill',
            warning: 'bi-exclamation-circle-fill',
            info: 'bi-info-circle-fill'
        };

        var toastEl = document.createElement('div');
        toastEl.className = 'toast align-items-center text-bg-' + type + ' border-0';
        toastEl.setAttribute('role', 'alert');
        toastEl.innerHTML =
            '<div class="d-flex">' +
                '<div class="toast-body">' +
                    '<i class="bi ' + (iconMap[type] || iconMap.info) + ' me-2"></i>' +
                    escapeHtml(message) +
                '</div>' +
                '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
            '</div>';

        container.appendChild(toastEl);
        var bsToast = new bootstrap.Toast(toastEl, { delay: 4000 });
        bsToast.show();

        toastEl.addEventListener('hidden.bs.toast', function () {
            toastEl.remove();
        });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // ========================================================================
    // AJAX Helper
    // ========================================================================

    function ajaxPost(url, data) {
        data.csrf_token = getCsrfToken();

        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken()
            },
            credentials: 'same-origin',
            body: JSON.stringify(data)
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok || !json.success) {
                    var msg = (json.error && json.error.message) ? json.error.message : 'Unknown error';
                    throw new Error(msg);
                }
                return json;
            });
        });
    }

    // ========================================================================
    // Queue: Keyboard Navigation
    // ========================================================================

    var highlightedRowIndex = -1;

    function getQueueRows() {
        return document.querySelectorAll('.queue-table tbody tr[data-id]');
    }

    function highlightRow(index) {
        var rows = getQueueRows();
        if (rows.length === 0) return;

        // Remove previous highlight
        rows.forEach(function (r) { r.classList.remove('row-highlighted'); });

        if (index < 0) index = 0;
        if (index >= rows.length) index = rows.length - 1;

        highlightedRowIndex = index;
        rows[index].classList.add('row-highlighted');
        rows[index].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function openHighlightedRow() {
        var rows = getQueueRows();
        if (highlightedRowIndex >= 0 && highlightedRowIndex < rows.length) {
            var id = rows[highlightedRowIndex].getAttribute('data-id');
            if (id) {
                window.location.href = '/moderate/review.php?id=' + id;
            }
        }
    }

    // ========================================================================
    // Queue: Bulk Select
    // ========================================================================

    function initBulkSelect() {
        var selectAll = document.getElementById('selectAll');
        var bulkBar = document.querySelector('.bulk-actions-bar');
        if (!selectAll || !bulkBar) return;

        selectAll.addEventListener('change', function () {
            var checked = this.checked;
            document.querySelectorAll('.row-checkbox').forEach(function (cb) {
                cb.checked = checked;
                var row = cb.closest('tr');
                if (row) {
                    row.classList.toggle('row-selected', checked);
                }
            });
            updateBulkBar();
        });

        document.querySelectorAll('.row-checkbox').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var row = this.closest('tr');
                if (row) {
                    row.classList.toggle('row-selected', this.checked);
                }
                updateBulkBar();
            });
        });

        function updateBulkBar() {
            var count = document.querySelectorAll('.row-checkbox:checked').length;
            var countEl = document.getElementById('selectedCount');
            if (countEl) countEl.textContent = count;

            if (count > 0) {
                bulkBar.classList.add('visible');
            } else {
                bulkBar.classList.remove('visible');
            }
        }

        // Bulk approve
        var bulkApproveBtn = document.getElementById('bulkApprove');
        if (bulkApproveBtn) {
            bulkApproveBtn.addEventListener('click', function () {
                executeBulkAction('approve');
            });
        }

        // Bulk reject
        var bulkRejectBtn = document.getElementById('bulkReject');
        if (bulkRejectBtn) {
            bulkRejectBtn.addEventListener('click', function () {
                executeBulkAction('reject');
            });
        }
    }

    function getSelectedIds() {
        var ids = [];
        document.querySelectorAll('.row-checkbox:checked').forEach(function (cb) {
            ids.push(parseInt(cb.value, 10));
        });
        return ids;
    }

    function executeBulkAction(action) {
        var ids = getSelectedIds();
        if (ids.length === 0) return;

        var actionLabel = action === 'approve' ? 'одобрить' : 'отклонить';
        if (!confirm('Вы уверены, что хотите ' + actionLabel + ' выбранные записи (' + ids.length + ' шт.)?')) {
            return;
        }

        var progressEl = document.getElementById('bulkProgress');
        var progressBar = progressEl ? progressEl.querySelector('.progress-bar') : null;
        if (progressEl) progressEl.style.display = 'block';

        var completed = 0;
        var errors = 0;

        function processNext(index) {
            if (index >= ids.length) {
                var msg = 'Обработано: ' + completed + ' из ' + ids.length;
                if (errors > 0) msg += ' (ошибок: ' + errors + ')';
                showToast(msg, errors > 0 ? 'warning' : 'success');
                setTimeout(function () { window.location.reload(); }, 1500);
                return;
            }

            ajaxPost('/api/v1/moderate/review.php', {
                submission_id: ids[index],
                action: action,
                note: ''
            }).then(function () {
                completed++;
            }).catch(function () {
                errors++;
            }).finally(function () {
                var pct = Math.round(((index + 1) / ids.length) * 100);
                if (progressBar) {
                    progressBar.style.width = pct + '%';
                    progressBar.textContent = pct + '%';
                }
                processNext(index + 1);
            });
        }

        processNext(0);
    }

    // ========================================================================
    // Review Page: Actions
    // ========================================================================

    function initReviewActions() {
        var approveBtn = document.getElementById('btnApprove');
        var revisionBtn = document.getElementById('btnRevision');
        var rejectBtn = document.getElementById('btnReject');
        var noteEl = document.getElementById('moderatorNote');
        var submissionId = document.querySelector('[data-submission-id]');

        if (!submissionId) return;
        var sid = parseInt(submissionId.getAttribute('data-submission-id'), 10);

        function submitAction(action) {
            var note = noteEl ? noteEl.value.trim() : '';

            if (action === 'request_revision' && note === '') {
                showToast('Для возврата на доработку необходимо указать комментарий', 'warning');
                if (noteEl) noteEl.focus();
                return;
            }

            // Disable buttons during request
            [approveBtn, revisionBtn, rejectBtn].forEach(function (btn) {
                if (btn) btn.disabled = true;
            });

            ajaxPost('/api/v1/moderate/review.php', {
                submission_id: sid,
                action: action,
                note: note
            }).then(function (json) {
                var labels = {
                    approve: 'Материал одобрен',
                    reject: 'Материал отклонен',
                    request_revision: 'Отправлено на доработку'
                };
                showToast(labels[action] || 'Действие выполнено', 'success');

                // Redirect to next item or queue after 2 seconds
                setTimeout(function () {
                    var nextLink = document.querySelector('[data-next-id]');
                    if (nextLink) {
                        window.location.href = '/moderate/review.php?id=' + nextLink.getAttribute('data-next-id');
                    } else {
                        window.location.href = '/moderate/queue.php';
                    }
                }, 2000);

            }).catch(function (err) {
                showToast('Ошибка: ' + err.message, 'danger');
                [approveBtn, revisionBtn, rejectBtn].forEach(function (btn) {
                    if (btn) btn.disabled = false;
                });
            });
        }

        if (approveBtn) {
            approveBtn.addEventListener('click', function () { submitAction('approve'); });
        }
        if (revisionBtn) {
            revisionBtn.addEventListener('click', function () { submitAction('request_revision'); });
        }
        if (rejectBtn) {
            rejectBtn.addEventListener('click', function () { submitAction('reject'); });
        }
    }

    // ========================================================================
    // User Management: Actions
    // ========================================================================

    function initUserActions() {
        document.querySelectorAll('[data-user-action]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var action = this.getAttribute('data-user-action');
                var userId = this.getAttribute('data-user-id');
                var userName = this.getAttribute('data-user-name') || 'пользователя';

                var labels = {
                    ban: 'забанить',
                    unban: 'разбанить',
                    promote_moderator: 'назначить модератором',
                    demote_to_user: 'разжаловать до пользователя'
                };

                var confirmMsg = 'Вы уверены, что хотите ' + (labels[action] || action) + ' ' + userName + '?';
                if (!confirm(confirmMsg)) return;

                ajaxPost('/api/v1/moderate/users.php', {
                    user_id: parseInt(userId, 10),
                    action: action,
                    note: ''
                }).then(function () {
                    showToast('Действие выполнено', 'success');
                    setTimeout(function () { window.location.reload(); }, 1000);
                }).catch(function (err) {
                    showToast('Ошибка: ' + err.message, 'danger');
                });
            });
        });
    }

    // ========================================================================
    // Keyboard Shortcuts
    // ========================================================================

    function initKeyboardShortcuts() {
        document.addEventListener('keydown', function (e) {
            // Don't trigger shortcuts when typing in inputs
            var tag = e.target.tagName.toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable) {
                return;
            }

            var key = e.key.toLowerCase();

            // Queue page navigation
            if (document.querySelector('.queue-table')) {
                if (key === 'j') {
                    e.preventDefault();
                    highlightRow(highlightedRowIndex + 1);
                    return;
                }
                if (key === 'k') {
                    e.preventDefault();
                    highlightRow(highlightedRowIndex - 1);
                    return;
                }
                if (key === 'enter' && highlightedRowIndex >= 0) {
                    e.preventDefault();
                    openHighlightedRow();
                    return;
                }
            }

            // Review page actions
            if (document.querySelector('[data-submission-id]')) {
                if (key === 'a') {
                    e.preventDefault();
                    var approveBtn = document.getElementById('btnApprove');
                    if (approveBtn && !approveBtn.disabled) approveBtn.click();
                    return;
                }
                if (key === 'e') {
                    e.preventDefault();
                    var revisionBtn = document.getElementById('btnRevision');
                    if (revisionBtn && !revisionBtn.disabled) revisionBtn.click();
                    return;
                }
                if (key === 'r') {
                    e.preventDefault();
                    var rejectBtn = document.getElementById('btnReject');
                    if (rejectBtn && !rejectBtn.disabled) rejectBtn.click();
                    return;
                }
            }

            // Escape: back to queue
            if (key === 'escape') {
                if (document.querySelector('[data-submission-id]')) {
                    window.location.href = '/moderate/queue.php';
                }
            }
        });
    }

    // ========================================================================
    // Queue Row Click
    // ========================================================================

    function initQueueRowClick() {
        document.querySelectorAll('.queue-table tbody tr[data-id]').forEach(function (row) {
            row.addEventListener('click', function (e) {
                // Don't navigate if clicking checkbox
                if (e.target.type === 'checkbox' || e.target.closest('.form-check')) return;

                var id = this.getAttribute('data-id');
                if (id) {
                    window.location.href = '/moderate/review.php?id=' + id;
                }
            });
        });
    }

    // ========================================================================
    // Auto-refresh Queue Badge
    // ========================================================================

    function initBadgeRefresh() {
        setInterval(function () {
            fetch('/api/v1/moderate/stats.php', {
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json.success && json.data) {
                    var badge = document.querySelector('.pending-badge');
                    var count = json.data.queue_size || 0;
                    if (badge) {
                        badge.textContent = count;
                        badge.style.display = count > 0 ? '' : 'none';
                    } else if (count > 0) {
                        // Create badge if it doesn't exist
                        var queueLink = document.querySelector('a[href="/moderate/queue.php"]');
                        if (queueLink) {
                            var newBadge = document.createElement('span');
                            newBadge.className = 'badge bg-warning text-dark ms-1 pending-badge';
                            newBadge.textContent = count;
                            queueLink.appendChild(newBadge);
                        }
                    }
                }
            })
            .catch(function () {
                // Silently ignore refresh errors
            });
        }, 60000);
    }

    // ========================================================================
    // Initialize on DOM Ready
    // ========================================================================

    document.addEventListener('DOMContentLoaded', function () {
        initBulkSelect();
        initReviewActions();
        initUserActions();
        initKeyboardShortcuts();
        initQueueRowClick();
        initBadgeRefresh();
    });

})();
