/**
 * MTCC Issue Reporting Module
 * Location: /js/mtcc-issues.js
 *
 * Bottom-sheet issue reporter for MTCC staff. Mirrors the courier app's
 * two-step flow (type picker → details with optional photo + notes), but
 * uses MTCC-relevant categories and POSTs to the existing
 * mtcc_report_issue endpoint on admin-orders.php.
 *
 * Reuses CSS classes from /courier/courier-issues.css, loaded via
 * admin-orders.php for MTCC staff.
 *
 * Public API:
 *   MtccIssues.open(ref)  — open the modal for a single order reference
 *   MtccIssues.close()    — programmatically dismiss
 */
var MtccIssues = (function () {
    'use strict';

    // MTCC-relevant issue types (pickup-counter perspective, not delivery)
    var ISSUE_TYPES = [
        { id: 'damaged', label: 'Damaged on Pickup', icon: '\u26A0\uFE0F',
          description: 'Order is damaged (bent, torn, smudged, etc.)' },
        { id: 'wrong_order', label: 'Wrong Order', icon: '\u274C',
          description: 'Order does not match what was ordered' },
        { id: 'missing', label: 'Cannot Find Order', icon: '\u2753',
          description: 'Order is not where expected / not labeled clearly' },
        { id: 'quality', label: 'Quality Concern', icon: '\uD83D\uDD0D',
          description: 'Print quality appears poor (color, alignment, paper)' },
        { id: 'customer_complaint', label: 'Customer Complaint', icon: '\uD83D\uDDE3\uFE0F',
          description: 'Customer expressed dissatisfaction' },
        { id: 'refund_request', label: 'Refund Requested', icon: '\uD83D\uDCB8',
          description: 'Customer is asking for a refund' },
        { id: 'other', label: 'Other Issue', icon: '\u2026',
          description: 'Something else — describe in notes' }
    ];

    var _ref = null;
    var _selectedType = null;
    var _photoData = null;
    var _submitting = false;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function notify(msg, type) {
        if (typeof showNotification === 'function') showNotification(msg, type || 'info');
        else alert(msg);
    }

    function getModalHTML(ref) {
        var h = '';
        h += '<div class="issue-modal-overlay" id="mtccIssueOverlay" onclick="MtccIssues.close()">';
        h += '<div class="issue-modal" onclick="event.stopPropagation()">';

        // Header (no X — tap overlay or swipe down to dismiss, courier-style)
        h += '<div class="issue-modal-header">';
        h += '<div class="issue-modal-title">';
        h += '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
        h += '<span>Report Issue</span>';
        h += '</div>';
        h += '<div class="issue-modal-ref">#' + esc(ref) + '</div>';
        h += '<button class="issue-modal-close" onclick="MtccIssues.close()" aria-label="Close">';
        h += '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>';
        h += '</button>';
        h += '</div>';

        // Step 1: pick type
        h += '<div class="issue-step" id="mtccIssueStepType">';
        h += '<div class="issue-step-label">What went wrong?</div>';
        h += '<div class="issue-type-grid">';
        ISSUE_TYPES.forEach(function (t) {
            h += '<button class="issue-type-btn" data-type="' + t.id + '" onclick="MtccIssues.selectType(\'' + t.id + '\')">';
            h += '<span class="issue-type-icon">' + t.icon + '</span>';
            h += '<span class="issue-type-label">' + esc(t.label) + '</span>';
            h += '</button>';
        });
        h += '</div>';
        h += '</div>';

        // Step 2: details
        h += '<div class="issue-step" id="mtccIssueStepDetails" style="display:none">';
        h += '<button class="issue-back-btn" onclick="MtccIssues.back()">';
        h += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg> Back';
        h += '</button>';
        h += '<div class="issue-selected-type" id="mtccIssueSelectedLabel"></div>';

        // Photo (optional, with camera capture on mobile)
        h += '<div class="issue-photo-section">';
        h += '<div class="issue-photo-label">Photo <span class="issue-optional">(optional)</span></div>';
        h += '<div class="issue-photo-area">';
        h += '<button class="issue-photo-btn" id="mtccIssuePhotoBtn" onclick="MtccIssues.capturePhoto()">';
        h += '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>';
        h += '<span>Take Photo</span>';
        h += '</button>';
        h += '<div class="issue-photo-preview" id="mtccIssuePhotoPreview" style="display:none">';
        h += '<img id="mtccIssuePhotoImg" src="" alt="Issue photo">';
        h += '<button class="issue-photo-remove" onclick="MtccIssues.removePhoto()" aria-label="Remove photo">';
        h += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>';
        h += '</button>';
        h += '</div>';
        h += '</div>';
        h += '</div>';

        // Notes
        h += '<div class="issue-notes-section">';
        h += '<label class="issue-notes-label" for="mtccIssueNotes">Additional notes</label>';
        h += '<textarea id="mtccIssueNotes" class="issue-notes-input" rows="3" placeholder="Describe the issue..."></textarea>';
        h += '</div>';

        // Submit
        h += '<button class="issue-submit-btn" id="mtccIssueSubmitBtn" onclick="MtccIssues.submit()">';
        h += '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg>';
        h += '<span>Submit Issue Report</span>';
        h += '</button>';

        h += '</div>'; // step 2
        h += '</div>'; // modal
        h += '</div>'; // overlay

        // Hidden file input for photo capture
        h += '<input type="file" id="mtccIssuePhotoInput" accept="image/*" capture="environment" style="display:none" onchange="MtccIssues.onPhotoSelected(this)">';
        return h;
    }

    function open(ref) {
        _ref = ref;
        _selectedType = null;
        _photoData = null;
        _submitting = false;

        if (window.mtccHaptic) window.mtccHaptic.tap();

        var container = document.getElementById('mtccIssueContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'mtccIssueContainer';
            document.body.appendChild(container);
        }
        container.innerHTML = getModalHTML(ref);

        requestAnimationFrame(function () {
            var ov = document.getElementById('mtccIssueOverlay');
            if (ov) ov.classList.add('visible');
        });
    }

    function close() {
        var ov = document.getElementById('mtccIssueOverlay');
        if (ov) {
            ov.classList.remove('visible');
            setTimeout(function () {
                var c = document.getElementById('mtccIssueContainer');
                if (c) c.innerHTML = '';
            }, 320);
        }
        _ref = null;
        _selectedType = null;
        _photoData = null;
    }

    function selectType(id) {
        _selectedType = ISSUE_TYPES.find(function (t) { return t.id === id; });
        if (!_selectedType) return;
        if (window.mtccHaptic) window.mtccHaptic.tap();

        document.querySelectorAll('.issue-type-btn').forEach(function (b) {
            b.classList.toggle('selected', b.dataset.type === id);
        });
        document.getElementById('mtccIssueStepType').style.display = 'none';
        document.getElementById('mtccIssueStepDetails').style.display = '';
        document.getElementById('mtccIssueSelectedLabel').innerHTML =
            '<span class="issue-type-icon">' + _selectedType.icon + '</span> ' +
            esc(_selectedType.label);
    }

    function back() {
        document.getElementById('mtccIssueStepType').style.display = '';
        document.getElementById('mtccIssueStepDetails').style.display = 'none';
        _selectedType = null;
        _photoData = null;
        var pv = document.getElementById('mtccIssuePhotoPreview');
        var bt = document.getElementById('mtccIssuePhotoBtn');
        if (pv) pv.style.display = 'none';
        if (bt) bt.style.display = '';
    }

    function capturePhoto() {
        var input = document.getElementById('mtccIssuePhotoInput');
        if (input) input.click();
    }

    function onPhotoSelected(input) {
        if (!input.files || !input.files[0]) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            _photoData = e.target.result;
            var img = document.getElementById('mtccIssuePhotoImg');
            if (img) img.src = _photoData;
            document.getElementById('mtccIssuePhotoPreview').style.display = '';
            document.getElementById('mtccIssuePhotoBtn').style.display = 'none';
        };
        reader.readAsDataURL(input.files[0]);
    }

    function removePhoto() {
        _photoData = null;
        var pv = document.getElementById('mtccIssuePhotoPreview');
        var bt = document.getElementById('mtccIssuePhotoBtn');
        var input = document.getElementById('mtccIssuePhotoInput');
        if (pv) pv.style.display = 'none';
        if (bt) bt.style.display = '';
        if (input) input.value = '';
    }

    function submit() {
        if (_submitting) return;
        if (!_selectedType) { notify('Please select an issue type', 'error'); return; }

        var notes = (document.getElementById('mtccIssueNotes') || {}).value || '';
        var description = _selectedType.label;
        if (notes.trim()) description += '\n\n' + notes.trim();

        _submitting = true;
        var btn = document.getElementById('mtccIssueSubmitBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="btn-spinner"></span> Submitting...';
        }

        var fd = new FormData();
        fd.append('mtcc_report_issue', '1');
        fd.append('reference_code', _ref);
        fd.append('description', description);
        fd.append('issue_type', _selectedType.id);
        fd.append('issue_label', _selectedType.label);
        if (_photoData) fd.append('photo', _photoData);

        fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                _submitting = false;
                if (data && data.success) {
                    if (window.mtccHaptic) window.mtccHaptic.success();
                    notify('Issue reported to Print Stuff. Thank you.', 'success');
                    close();
                } else {
                    if (window.mtccHaptic) window.mtccHaptic.error();
                    var err = (data && data.error) || 'Unknown error';
                    notify('Failed to report issue: ' + err, 'error');
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg><span>Submit Issue Report</span>';
                    }
                }
            })
            .catch(function () {
                _submitting = false;
                if (window.mtccHaptic) window.mtccHaptic.error();
                notify('Network error. Please try again.', 'error');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg><span>Submit Issue Report</span>';
                }
            });
    }

    return {
        open: open,
        close: close,
        selectType: selectType,
        back: back,
        capturePhoto: capturePhoto,
        onPhotoSelected: onPhotoSelected,
        removePhoto: removePhoto,
        submit: submit
    };
})();
