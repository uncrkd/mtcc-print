/**
 * Order Detail Page JavaScript - Separate File
 * This file contains all order detail functionality to avoid PHP/JS parsing conflicts
 */

// Global variables
var currentOrderReference = null;
var currentTrackingNumber = null;

// Initialize order detail page
function initializeOrderDetail(orderReference, trackingNumber) {
    console.log('Initializing order detail for:', orderReference);
    
    currentOrderReference = orderReference;
    currentTrackingNumber = trackingNumber;
    
    // Setup all event listeners
    setupEventListeners();
    
    // Generate barcode after delay
    setTimeout(function() {
        generateOrderBarcode();
    }, 2000);
    
    console.log('Order detail initialization complete');
}

function setupEventListeners() {
    // File upload
    setupFileUpload();
    
    // Notes system
    setupNotesSystem();
    
    // Barcode actions
    setupBarcodeActions();
    
    // Pricing updates
    setupPricingListeners();
}

// File Upload System
function setupFileUpload() {
    var uploadZone = document.getElementById('uploadZone');
    var fileInput = document.getElementById('fileInput');
    
    if (uploadZone && fileInput) {
        uploadZone.addEventListener('click', function() {
            fileInput.click();
        });
        
        fileInput.addEventListener('change', function(e) {
            if (e.target.files.length > 0) {
                handleFileSelect(e.target.files[0]);
            }
        });
        
        // Drag and drop
        uploadZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            uploadZone.classList.add('dragover');
        });
        
        uploadZone.addEventListener('dragleave', function() {
            uploadZone.classList.remove('dragover');
        });
        
        uploadZone.addEventListener('drop', function(e) {
            e.preventDefault();
            uploadZone.classList.remove('dragover');
            if (e.dataTransfer.files.length > 0) {
                handleFileSelect(e.dataTransfer.files[0]);
            }
        });
    }
}

function handleFileSelect(file) {
    var maxSize = 100 * 1024 * 1024; // 100MB
    var allowedTypes = ['.pdf', '.ai', '.eps', '.psd', '.png', '.jpg', '.jpeg', '.tiff', '.tif', '.webp', '.gif', '.bmp', '.svg', '.pptx', '.indd'];
    
    var fileExt = '.' + file.name.split('.').pop().toLowerCase();
    
    if (file.size > maxSize) {
        alert('File is too large. Maximum size is 100MB.');
        return;
    }
    
    if (allowedTypes.indexOf(fileExt) === -1) {
        alert('File type not supported.');
        return;
    }
    
    displaySelectedFile(file);
}

function displaySelectedFile(file) {
    var uploadZone = document.getElementById('uploadZone');
    if (!uploadZone) return;
    
    var fileSize = formatFileSize(file.size);
    var fileExt = file.name.split('.').pop().toUpperCase();
    
    uploadZone.classList.add('has-file');
    uploadZone.innerHTML = '<div style="display: flex; align-items: center; gap: 1rem; padding: 1rem; border: 1px solid #e5e7eb; border-radius: 4px; background: #f8fafc;"><span style="color: #059669; font-size: 1.25rem;">Ã¢Å“â€¦</span><div style="flex: 1;"><div style="font-weight: 600; color: #374151;">' + file.name + '</div><div style="font-size: 0.9rem; color: #6b7280;">' + fileSize + ' Ã¢â‚¬Â¢ ' + fileExt + '</div></div><button type="button" onclick="removeSelectedFile()" class="btn btn-danger">Remove</button></div>';
}

function removeSelectedFile() {
    var uploadZone = document.getElementById('uploadZone');
    var fileInput = document.getElementById('fileInput');
    
    if (uploadZone) {
        uploadZone.classList.remove('has-file');
        uploadZone.innerHTML = '<div class="upload-content"><div class="upload-icon">📤</div><div class="upload-text"><p><strong>Click to upload</strong> or drag and drop your design file</p><p class="upload-note">PDF, AI, EPS, PSD, PNG, JPG, TIFF, WebP, GIF, BMP, SVG, PPTX, INDD (Max file size: 100MB)</p></div></div>';
    }
    
    if (fileInput) {
        fileInput.value = '';
    }
    
    setupFileUpload();
}

function removeExistingFile() {
    var uploadZone = document.getElementById('uploadZone');
    if (uploadZone) {
        uploadZone.classList.remove('has-file');
        uploadZone.innerHTML = '<div class="upload-content"><div class="upload-icon">📤</div><div class="upload-text"><p><strong>Click to upload</strong> or drag and drop your design file</p><p class="upload-note">PDF, AI, EPS, PSD, PNG, JPG, TIFF, WebP, GIF, BMP, SVG, PPTX, INDD (Max file size: 100MB)</p></div></div>';
        
        // Add hidden input to signal file removal
        var removeInput = document.createElement('input');
        removeInput.type = 'hidden';
        removeInput.name = 'remove_file';
        removeInput.value = '1';
        
        var form = document.querySelector('form');
        if (form) {
            form.appendChild(removeInput);
        }
        
        setupFileUpload();
    }
}

// Notes System
function setupNotesSystem() {
    var addNoteBtn = document.getElementById('addNoteBtn');
    var addNoteForm = document.getElementById('addNoteForm');
    var saveNoteBtn = document.getElementById('saveNoteBtn');
    var cancelNoteBtn = document.getElementById('cancelNoteBtn');
    
    if (addNoteBtn && addNoteForm) {
        addNoteBtn.addEventListener('click', function() {
            addNoteForm.classList.add('show');
            addNoteBtn.style.display = 'none';
            
            var noteUsername = document.getElementById('noteUsername');
            if (noteUsername) {
                noteUsername.focus();
            }
        });
    }
    
    if (cancelNoteBtn) {
        cancelNoteBtn.addEventListener('click', function() {
            if (addNoteForm) addNoteForm.classList.remove('show');
            if (addNoteBtn) addNoteBtn.style.display = 'inline-flex';
            clearNoteForm();
        });
    }
    
    if (saveNoteBtn) {
        saveNoteBtn.addEventListener('click', function() {
            var noteUsername = document.getElementById('noteUsername');
            var noteContent = document.getElementById('noteContent');
            
            if (!noteUsername || !noteContent) {
                alert('Note form elements not found');
                return;
            }
            
            var username = noteUsername.value.trim();
            var content = noteContent.value.trim();
            
            if (!username || !content) {
                alert('Please fill in both username and note content.');
                return;
            }
            
            saveNote(username, content);
        });
    }
}

function saveNote(username, content) {
    var saveNoteBtn = document.getElementById('saveNoteBtn');
    if (saveNoteBtn) {
        saveNoteBtn.disabled = true;
        saveNoteBtn.textContent = 'Saving...';
    }
    
    var formData = new FormData();
    formData.append('add_internal_note', '1');
    formData.append('reference_code', currentOrderReference);
    formData.append('username', username);
    formData.append('note_content', content);
    
    fetch('admin-orders.php', {
        method: 'POST',
        body: formData
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(data) {
        if (data.success) {
            location.reload();
        } else {
            alert('Failed to save note: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(function(error) {
        console.error('Error saving note:', error);
        alert('Failed to save note. Please try again.');
    })
    .finally(function() {
        if (saveNoteBtn) {
            saveNoteBtn.disabled = false;
            saveNoteBtn.textContent = 'Ã°Å¸â€™Â¾ Save Note';
        }
    });
}

function clearNoteForm() {
    var noteUsername = document.getElementById('noteUsername');
    var noteContent = document.getElementById('noteContent');
    var noteFormMessage = document.getElementById('noteFormMessage');
    
    if (noteUsername) noteUsername.value = '';
    if (noteContent) noteContent.value = '';
    if (noteFormMessage) noteFormMessage.innerHTML = '';
}

function editNote(noteId) {
    console.log('Edit note function called for:', noteId);
    
    var editForm = document.getElementById('editForm_' + noteId);
    var noteMainLine = document.querySelector('[data-note-id="' + noteId + '"] .note-main-line');
    
    if (editForm && noteMainLine) {
        noteMainLine.style.display = 'none';
        editForm.classList.add('show');
        
        var contentTextarea = document.getElementById('editContent_' + noteId);
        if (contentTextarea) {
            contentTextarea.focus();
        }
    } else {
        alert('Unable to edit note. Please refresh the page and try again.');
    }
}

function saveEditedNote(noteId) {
    var usernameInput = document.getElementById('editUsername_' + noteId);
    var contentInput = document.getElementById('editContent_' + noteId);
    
    if (!usernameInput || !contentInput) {
        alert('Edit form not found. Please refresh and try again.');
        return;
    }
    
    var username = usernameInput.value.trim();
    var content = contentInput.value.trim();
    
    if (!username || !content) {
        alert('Please fill in both username and note content.');
        return;
    }
    
    var formData = new FormData();
    formData.append('edit_internal_note', '1');
    formData.append('reference_code', currentOrderReference);
    formData.append('note_id', noteId);
    formData.append('username', username);
    formData.append('note_content', content);
    
    fetch('admin-orders.php', {
        method: 'POST',
        body: formData
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(data) {
        if (data.success) {
            location.reload();
        } else {
            alert('Failed to update note: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(function(error) {
        console.error('Error updating note:', error);
        alert('Failed to update note. Please try again.');
    });
}

function cancelEditNote(noteId) {
    var editForm = document.getElementById('editForm_' + noteId);
    var noteMainLine = document.querySelector('[data-note-id="' + noteId + '"] .note-main-line');
    
    if (editForm && noteMainLine) {
        editForm.classList.remove('show');
        noteMainLine.style.display = 'flex';
    }
}

function removeNote(noteId) {
    if (!confirm('Are you sure you want to remove this note? This action cannot be undone.')) {
        return;
    }
    
    var formData = new FormData();
    formData.append('remove_internal_note', '1');
    formData.append('reference_code', currentOrderReference);
    formData.append('note_id', noteId);
    
    fetch('admin-orders.php', {
        method: 'POST',
        body: formData
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(data) {
        if (data.success) {
            location.reload();
        } else {
            alert('Failed to remove note: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(function(error) {
        console.error('Error removing note:', error);
        alert('Failed to remove note. Please try again.');
    });
}

// Barcode System
function setupBarcodeActions() {
    // These are called directly from HTML onclick attributes
    // No setup needed, functions are defined below
}

function generateOrderBarcode() {
    console.log('Attempting to generate barcode for:', currentTrackingNumber);
    
    var barcodeElement = document.getElementById('orderBarcode');
    if (!barcodeElement) {
        console.log('Barcode element not found');
        return;
    }
    
    if (!currentTrackingNumber) {
        console.log('No tracking number available');
        barcodeElement.innerHTML = '<div style="padding: 20px; text-align: center; color: #666;">No tracking number available</div>';
        return;
    }
    
    if (typeof JsBarcode === 'undefined') {
        console.log('JsBarcode not loaded, showing fallback');
        barcodeElement.innerHTML = '<div style="padding: 20px; text-align: center; border: 1px solid #ccc; border-radius: 4px;"><strong>Tracking: ' + currentTrackingNumber + '</strong><br><small>Barcode library not loaded</small></div>';
        return;
    }
    
    try {
        console.log('Generating barcode with JsBarcode...');
        barcodeElement.innerHTML = '';
        
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        barcodeElement.appendChild(svg);
        
        JsBarcode(svg, currentTrackingNumber, {
            format: "CODE128",
            width: 2,
            height: 50,
            displayValue: false,
            margin: 10,
            background: "white",
            lineColor: "black"
        });
        
        console.log('Barcode generated successfully');
    } catch (error) {
        console.error('Barcode generation error:', error);
        barcodeElement.innerHTML = '<div style="padding: 20px; text-align: center; border: 1px solid #ccc; border-radius: 4px;"><strong>Tracking: ' + currentTrackingNumber + '</strong><br><small>Barcode generation failed</small></div>';
    }
}

function downloadBarcodeImage() {
    var svg = document.querySelector('#orderBarcode svg');
    
    if (!svg) {
        alert('Barcode not available for download');
        return;
    }
    
    if (!currentTrackingNumber) {
        alert('No tracking number available');
        return;
    }
    
    try {
        var canvas = document.createElement('canvas');
        var ctx = canvas.getContext('2d');
        var data = new XMLSerializer().serializeToString(svg);
        var img = new Image();
        
        img.onload = function() {
            canvas.width = img.width;
            canvas.height = img.height;
            ctx.drawImage(img, 0, 0);
            
            var link = document.createElement('a');
            link.download = 'barcode-' + currentTrackingNumber + '.png';
            link.href = canvas.toDataURL();
            link.click();
        };
        
        img.src = 'data:image/svg+xml;base64,' + btoa(data);
    } catch (error) {
        console.error('Download error:', error);
        alert('Download failed. Please try again.');
    }
}

function copyTrackingNumber() {
    if (!currentTrackingNumber) {
        alert('No tracking number available');
        return;
    }
    
    if (navigator.clipboard) {
        navigator.clipboard.writeText(currentTrackingNumber).then(function() {
            alert('Tracking number copied: ' + currentTrackingNumber);
        }).catch(function() {
            fallbackCopy();
        });
    } else {
        fallbackCopy();
    }
    
    function fallbackCopy() {
        var textArea = document.createElement('textarea');
        textArea.value = currentTrackingNumber;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        alert('Tracking number copied: ' + currentTrackingNumber);
    }
}

function printBarcodeOnly() {
    var mainBarcode = document.querySelector('#orderBarcode svg');
    
    if (!mainBarcode) {
        alert('Barcode not available for printing');
        return;
    }
    
    if (!currentTrackingNumber) {
        alert('No tracking number available');
        return;
    }
    
    try {
        var printWindow = window.open('', '_blank', 'width=600,height=400');
        if (!printWindow) {
            alert('Popup blocked. Please allow popups and try again.');
            return;
        }
        
        var htmlContent = '<!DOCTYPE html><html><head><title>Barcode - ' + currentTrackingNumber + '</title>';
        htmlContent += '<style>body { font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }';
        htmlContent += '.barcode-container { border: 2px solid #7c3aed; border-radius: 8px; padding: 30px; text-align: center; }</style>';
        htmlContent += '</head><body><div class="barcode-container"><h3>Tracking Code</h3>';
        htmlContent += mainBarcode.outerHTML;
        htmlContent += '<p>' + currentTrackingNumber + '</p></div>';
        htmlContent += '<script>window.onload = function() { window.print(); setTimeout(function() { window.close(); }, 1000); };</script>';
        htmlContent += '</body></html>';
        
        printWindow.document.write(htmlContent);
        printWindow.document.close();
    } catch (error) {
        console.error('Print error:', error);
        alert('Print failed. Please try again.');
    }
}

// Pricing System
function setupPricingListeners() {
    var basePriceInput = document.getElementById('base_price');
    var deliveryFeeInput = document.getElementById('delivery_fee');
    
    if (basePriceInput && typeof updatePricing === 'function') {
        basePriceInput.addEventListener('input', updatePricing);
        basePriceInput.addEventListener('change', updatePricing);
    }
    
    if (deliveryFeeInput && typeof updatePricing === 'function') {
        deliveryFeeInput.addEventListener('input', updatePricing);
        deliveryFeeInput.addEventListener('change', updatePricing);
    }
}

// Helper function
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    var k = 1024;
    var sizes = ['Bytes', 'KB', 'MB', 'GB'];
    var i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

console.log('Order detail JavaScript file loaded');