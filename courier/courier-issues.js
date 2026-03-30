/**
 * Courier Issue Reporting Module
 * Location: /courier/courier-issues.js
 * 
 * Provides issue reporting UI for couriers in the delivery app.
 * Shows a modal with predefined issue types, optional photo capture,
 * and submits to the report_issue API endpoint.
 * 
 * Depends on: app.js (apiCall, haptic, escapeHtml, showToast, currentUser)
 */

var CourierIssues = (function() {
    'use strict';

    // Predefined issue types
    var ISSUE_TYPES = [
        { id: 'missing_item', label: 'Missing Item', icon: '\u2753', photoRequired: false,
          description: 'Item never arrived or cannot be found', setsMissing: true },
        { id: 'damaged_in_transit', label: 'Damaged in Transit', icon: '\ud83d\udccc', photoRequired: true,
          description: 'Print was damaged during transport' },
        { id: 'wrong_order', label: 'Wrong Order', icon: '\u274c', photoRequired: false,
          description: 'Received wrong print from vendor' },
        { id: 'customer_unavailable', label: 'Customer Unavailable', icon: '\ud83d\udeab', photoRequired: false,
          description: 'Customer not at delivery location' },
        { id: 'address_issue', label: 'Address/Location Issue', icon: '\ud83d\udccd', photoRequired: false,
          description: 'Cannot find delivery location or address incorrect' },
        { id: 'vendor_not_ready', label: 'Vendor Not Ready', icon: '\u23f3', photoRequired: false,
          description: 'Print not ready for pickup at vendor' },
        { id: 'quality_concern', label: 'Quality Concern', icon: '\ud83d\udd0d', photoRequired: true,
          description: 'Print quality appears poor (colors, alignment, etc.)' },
        { id: 'not_picked_up', label: 'Not Picked Up', icon: '\ud83d\udce6', photoRequired: false,
          description: 'Customer did not pick up after event ended' },
        { id: 'other', label: 'Other Issue', icon: '\u2753', photoRequired: false,
          description: 'Something else went wrong' }
    ];

    var _currentRef = null;
    var _selectedType = null;
    var _photoData = null;
    var _isSubmitting = false;

    // ============================================
    // MODAL HTML
    // ============================================

    function getModalHTML(ref) {
        var html = '<div class="issue-modal-overlay" id="issueModalOverlay" onclick="CourierIssues.close()">';
        html += '<div class="issue-modal" onclick="event.stopPropagation()">';
        
        // Header
        html += '<div class="issue-modal-header">';
        html += '<div class="issue-modal-title">';
        html += '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
        html += '<span>Report Issue</span>';
        html += '</div>';
        html += '<div class="issue-modal-ref">' + escapeHtml(ref) + '</div>';
        html += '<button class="issue-modal-close" onclick="CourierIssues.close()">';
        html += '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>';
        html += '</button>';
        html += '</div>';

        // Step 1: Issue Type Selection
        html += '<div class="issue-step" id="issueStepType">';
        html += '<div class="issue-step-label">What went wrong?</div>';
        html += '<div class="issue-type-grid">';
        ISSUE_TYPES.forEach(function(t) {
            html += '<button class="issue-type-btn" data-type="' + t.id + '" onclick="CourierIssues.selectType(\'' + t.id + '\')">';
            html += '<span class="issue-type-icon">' + t.icon + '</span>';
            html += '<span class="issue-type-label">' + escapeHtml(t.label) + '</span>';
            html += '</button>';
        });
        html += '</div>';
        html += '</div>';

        // Step 2: Details (hidden initially)
        html += '<div class="issue-step" id="issueStepDetails" style="display:none">';
        html += '<button class="issue-back-btn" onclick="CourierIssues.backToTypes()">';
        html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg> Back';
        html += '</button>';
        html += '<div class="issue-selected-type" id="issueSelectedLabel"></div>';
        
        // Photo capture area
        html += '<div class="issue-photo-section" id="issuePhotoSection">';
        html += '<div class="issue-photo-label" id="issuePhotoLabel">Photo (optional)</div>';
        html += '<div class="issue-photo-area" id="issuePhotoArea">';
        html += '<button class="issue-photo-btn" id="issuePhotoBtn" onclick="CourierIssues.capturePhoto()">';
        html += '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>';
        html += '<span>Take Photo</span>';
        html += '</button>';
        html += '<div class="issue-photo-preview" id="issuePhotoPreview" style="display:none">';
        html += '<img id="issuePhotoImg" src="" alt="Issue photo">';
        html += '<button class="issue-photo-remove" onclick="CourierIssues.removePhoto()">';
        html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>';
        html += '</button>';
        html += '</div>';
        html += '</div>';
        html += '</div>';

        // Notes
        html += '<div class="issue-notes-section">';
        html += '<label class="issue-notes-label" for="issueNotes">Additional notes</label>';
        html += '<textarea id="issueNotes" class="issue-notes-input" rows="3" placeholder="Describe the issue..."></textarea>';
        html += '</div>';

        // Submit
        html += '<button class="issue-submit-btn" id="issueSubmitBtn" onclick="CourierIssues.submit()">';
        html += '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg>';
        html += '<span>Submit Issue Report</span>';
        html += '</button>';

        html += '</div>'; // end step 2

        html += '</div>'; // end modal
        html += '</div>'; // end overlay

        // Hidden file input for photo capture
        html += '<input type="file" id="issuePhotoInput" accept="image/*" capture="environment" style="display:none" onchange="CourierIssues.onPhotoSelected(this)">';

        return html;
    }

    // ============================================
    // MODAL ACTIONS
    // ============================================

    function open(ref) {
        _currentRef = ref;
        _selectedType = null;
        _photoData = null;
        _isSubmitting = false;
        
        if (typeof haptic !== 'undefined') haptic.tap();

        // Create modal container
        var container = document.getElementById('issueModalContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'issueModalContainer';
            document.body.appendChild(container);
        }
        container.innerHTML = getModalHTML(ref);
        
        // Animate in
        requestAnimationFrame(function() {
            var overlay = document.getElementById('issueModalOverlay');
            if (overlay) overlay.classList.add('visible');
        });
    }

    function close() {
        var overlay = document.getElementById('issueModalOverlay');
        if (overlay) {
            overlay.classList.remove('visible');
            setTimeout(function() {
                var container = document.getElementById('issueModalContainer');
                if (container) container.innerHTML = '';
            }, 300);
        }
        _currentRef = null;
        _selectedType = null;
        _photoData = null;
    }

    function selectType(typeId) {
        _selectedType = ISSUE_TYPES.find(function(t) { return t.id === typeId; });
        if (!_selectedType) return;
        
        if (typeof haptic !== 'undefined') haptic.tap();

        // Highlight selected
        document.querySelectorAll('.issue-type-btn').forEach(function(btn) {
            btn.classList.toggle('selected', btn.dataset.type === typeId);
        });

        // Show details step
        document.getElementById('issueStepType').style.display = 'none';
        document.getElementById('issueStepDetails').style.display = '';

        // Update label
        document.getElementById('issueSelectedLabel').innerHTML = 
            '<span class="issue-type-icon">' + _selectedType.icon + '</span> ' + 
            escapeHtml(_selectedType.label);

        // Photo label — required vs optional
        var photoLabel = document.getElementById('issuePhotoLabel');
        if (_selectedType.photoRequired) {
            photoLabel.innerHTML = 'Photo <span class="issue-required">(required)</span>';
        } else {
            photoLabel.innerHTML = 'Photo <span class="issue-optional">(optional)</span>';
        }
    }

    function backToTypes() {
        document.getElementById('issueStepType').style.display = '';
        document.getElementById('issueStepDetails').style.display = 'none';
        _selectedType = null;
        _photoData = null;
        
        // Reset photo preview
        document.getElementById('issuePhotoPreview').style.display = 'none';
        document.getElementById('issuePhotoBtn').style.display = '';
    }

    function capturePhoto() {
        document.getElementById('issuePhotoInput').click();
    }

    function onPhotoSelected(input) {
        if (!input.files || !input.files[0]) return;
        
        var file = input.files[0];
        var reader = new FileReader();
        reader.onload = function(e) {
            _photoData = e.target.result;
            
            // Show preview
            var img = document.getElementById('issuePhotoImg');
            img.src = _photoData;
            document.getElementById('issuePhotoPreview').style.display = '';
            document.getElementById('issuePhotoBtn').style.display = 'none';
        };
        reader.readAsDataURL(file);
    }

    function removePhoto() {
        _photoData = null;
        document.getElementById('issuePhotoPreview').style.display = 'none';
        document.getElementById('issuePhotoBtn').style.display = '';
        document.getElementById('issuePhotoInput').value = '';
    }

    function submit() {
        if (_isSubmitting) return;
        if (!_selectedType) {
            if (typeof showToast === 'function') showToast('Please select an issue type', 'error');
            return;
        }
        if (_selectedType.photoRequired && !_photoData) {
            if (typeof showToast === 'function') showToast('Photo is required for ' + _selectedType.label, 'error');
            if (typeof haptic !== 'undefined') haptic.error();
            return;
        }

        _isSubmitting = true;
        var btn = document.getElementById('issueSubmitBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="btn-spinner"></span> Submitting...';

        var notes = (document.getElementById('issueNotes') || {}).value || '';

        var postData = {
            action: 'report_issue',
            ref: _currentRef,
            issue_type: _selectedType.id,
            issue_label: _selectedType.label,
            notes: notes
        };
        if (_photoData) {
            postData.photo = _photoData;
        }

        apiCall('report_issue', postData, function(result) {
            _isSubmitting = false;
            if (result.success) {
                // If issue type sets missing status, update order status too
                if (_selectedType && _selectedType.setsMissing) {
                    apiCall('update_status', { ref: _currentRef, status: 'missing' }, function() {});
                }
                if (typeof haptic !== 'undefined') haptic.success();
                if (typeof showToast === 'function') showToast('Issue reported successfully', 'success');
                close();
                if (typeof closeDetailPanel === 'function') closeDetailPanel();
                // Refresh current tab to show updated status
                if (typeof refreshTab === 'function' && typeof currentTab !== 'undefined') {
                    refreshTab(currentTab);
                }
            } else {
                if (typeof haptic !== 'undefined') haptic.error();
                if (typeof showToast === 'function') showToast(result.error || 'Failed to report issue', 'error');
                btn.disabled = false;
                btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg><span>Submit Issue Report</span>';
            }
        });
    }

    // ============================================
    // HELPER: Generate "Report Issue" button HTML for order detail
    // ============================================

    function getReportButtonHTML(ref, orderStatus) {
        // Show for any order that has been paid for
        var showStatuses = ['paid', 'preflight', 'printing', 'ready', 'dispatched', 'shipped', 'delivered'];
        if (showStatuses.indexOf(orderStatus) === -1) return '';
        
        var html = '<div class="issue-report-trigger" style="padding:0 16px 8px;">';
        html += '<button class="issue-report-btn" onclick="CourierIssues.open(\'' + escapeAttr(ref) + '\')">';
        html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
        html += ' Report Issue';
        html += '</button>';
        html += '</div>';
        return html;
    }

    // Public API
    return {
        open: open,
        close: close,
        selectType: selectType,
        backToTypes: backToTypes,
        capturePhoto: capturePhoto,
        onPhotoSelected: onPhotoSelected,
        removePhoto: removePhoto,
        submit: submit,
        getReportButtonHTML: getReportButtonHTML,
        ISSUE_TYPES: ISSUE_TYPES
    };

})();
