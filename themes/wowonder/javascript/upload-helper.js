/**
 * Enhanced Upload Helper for Bitchat
 * Provides: File validation, progress tracking, previews, and error handling
 */

// File validation configuration
const UPLOAD_CONFIG = {
    maxFileSize: 1024 * 1024 * 1024, // 1GB default (will be overridden by server config)
    allowedImageTypes: ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'],
    allowedVideoTypes: ['video/mp4', 'video/webm', 'video/ogg', 'video/mov', 'video/avi', 'video/mpeg', 'video/flv', 'video/mkv'],
    allowedAudioTypes: ['audio/mp3', 'audio/wav', 'audio/ogg', 'audio/mpeg'],
    allowedDocTypes: ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                      'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],

    getMaxFileSize: function(userPlanType) {
        // Plan types: 1=Star(weekly), 2=Hot, 3=Ultima, 4=VIP
        const planLimits = {
            1: 10 * 1024 * 1024,    // 10MB for Star
            2: 50 * 1024 * 1024,    // 50MB for Hot
            3: 100 * 1024 * 1024,   // 100MB for Ultima
            4: 500 * 1024 * 1024    // 500MB for VIP
        };
        return planLimits[userPlanType] || this.maxFileSize;
    },

    getAllowedTypes: function(uploadType) {
        switch(uploadType) {
            case 'image':
                return this.allowedImageTypes;
            case 'video':
                return this.allowedVideoTypes;
            case 'audio':
                return this.allowedAudioTypes;
            case 'document':
                return this.allowedDocTypes;
            default:
                return [...this.allowedImageTypes, ...this.allowedVideoTypes, ...this.allowedAudioTypes, ...this.allowedDocTypes];
        }
    }
};

/**
 * Validate file before upload
 * @param {File} file - File object from input
 * @param {string} uploadType - Type: 'image', 'video', 'audio', 'document', 'any'
 * @param {number} userPlanType - User's plan type (1-4)
 * @returns {Object} {valid: boolean, error: string}
 */
function Wo_ValidateFile(file, uploadType, userPlanType) {
    if (!file) {
        return {valid: false, error: 'No file selected'};
    }

    // Check file size
    const maxSize = UPLOAD_CONFIG.getMaxFileSize(userPlanType || 0);
    if (file.size > maxSize) {
        const maxSizeMB = (maxSize / (1024 * 1024)).toFixed(0);
        return {
            valid: false,
            error: `File is too large. Maximum size: ${maxSizeMB}MB. Please upgrade your plan for larger uploads.`
        };
    }

    // Check file type
    const allowedTypes = UPLOAD_CONFIG.getAllowedTypes(uploadType);
    if (allowedTypes.length > 0 && !allowedTypes.includes(file.type)) {
        const typeList = uploadType === 'image' ? 'JPEG, PNG, GIF, WebP' :
                        uploadType === 'video' ? 'MP4, WebM, MOV, AVI' :
                        uploadType === 'audio' ? 'MP3, WAV, OGG' :
                        uploadType === 'document' ? 'PDF, DOC, DOCX, XLS, XLSX' :
                        'supported file types';
        return {
            valid: false,
            error: `Invalid file type. Allowed: ${typeList}`
        };
    }

    return {valid: true, error: null};
}

/**
 * Generate file preview
 * @param {File} file - File object
 * @param {jQuery} $container - Container element to show preview
 */
function Wo_ShowFilePreview(file, $container) {
    if (!file || !$container) return;

    const fileType = file.type.split('/')[0];

    if (fileType === 'image') {
        // Image preview
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = `
                <div class="upload-preview">
                    <img src="${e.target.result}" alt="Preview" style="max-width: 100%; max-height: 200px; border-radius: 8px;">
                    <div class="file-info">${file.name} (${Wo_FormatFileSize(file.size)})</div>
                </div>`;
            $container.html(preview).removeClass('hidden');
        };
        reader.readAsDataURL(file);
    } else if (fileType === 'video') {
        // Video preview
        const videoPreview = `
            <div class="upload-preview">
                <video controls style="max-width: 100%; max-height: 200px; border-radius: 8px;">
                    <source src="${URL.createObjectURL(file)}" type="${file.type}">
                </video>
                <div class="file-info">${file.name} (${Wo_FormatFileSize(file.size)})</div>
            </div>`;
        $container.html(videoPreview).removeClass('hidden');
    } else {
        // Generic file preview
        const icon = fileType === 'audio' ? '🎵' : '📄';
        const filePreview = `
            <div class="upload-preview">
                <div class="file-icon" style="font-size: 48px;">${icon}</div>
                <div class="file-info">${file.name} (${Wo_FormatFileSize(file.size)})</div>
            </div>`;
        $container.html(filePreview).removeClass('hidden');
    }
}

/**
 * Format file size for display
 * @param {number} bytes - File size in bytes
 * @returns {string} Formatted size (KB, MB, GB)
 */
function Wo_FormatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
}

/**
 * Enhanced upload with progress tracking
 * @param {Object} options - Upload configuration
 *   - file: File object
 *   - endpoint: Upload endpoint (e.g., '?f=upload_image')
 *   - uploadType: 'image', 'video', 'audio', 'document'
 *   - userPlanType: User's plan type (1-4)
 *   - $progressBar: jQuery element for progress bar
 *   - $statusText: jQuery element for status text
 *   - onSuccess: Success callback
 *   - onError: Error callback
 *   - onProgress: Progress callback
 */
function Wo_UploadFileWithProgress(options) {
    const {
        file,
        endpoint,
        uploadType = 'any',
        userPlanType = 0,
        $progressBar,
        $statusText,
        onSuccess,
        onError,
        onProgress
    } = options;

    // Validate file before upload
    const validation = Wo_ValidateFile(file, uploadType, userPlanType);
    if (!validation.valid) {
        if ($statusText) {
            $statusText.html(`<span style="color: #e74c3c;">❌ ${validation.error}</span>`);
        }
        if (onError) {
            onError({status: 400, error: validation.error});
        }
        return;
    }

    // Prepare form data
    const formData = new FormData();
    formData.append('image', file);

    // Show initial status
    if ($statusText) {
        $statusText.html('Preparing upload...').show();
    }

    // Create XHR with progress tracking
    $.ajax({
        type: 'POST',
        url: Wo_Ajax_Requests_File() + endpoint,
        data: formData,
        processData: false,
        contentType: false,
        xhr: function() {
            const xhr = new window.XMLHttpRequest();

            // Upload progress
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    const percentComplete = Math.round((e.loaded / e.total) * 100);

                    // Update progress bar
                    if ($progressBar) {
                        $progressBar.css('width', percentComplete + '%')
                                   .attr('aria-valuenow', percentComplete)
                                   .show();
                    }

                    // Update status text
                    if ($statusText) {
                        const uploadedMB = (e.loaded / (1024 * 1024)).toFixed(2);
                        const totalMB = (e.total / (1024 * 1024)).toFixed(2);
                        $statusText.html(`Uploading... ${percentComplete}% (${uploadedMB}MB / ${totalMB}MB)`);
                    }

                    // Call custom progress callback
                    if (onProgress) {
                        onProgress(percentComplete, e.loaded, e.total);
                    }
                }
            }, false);

            return xhr;
        },
        success: function(data) {
            // Hide progress bar
            if ($progressBar) {
                $progressBar.css('width', '100%');
                setTimeout(() => $progressBar.hide(), 500);
            }

            // Show success message
            if ($statusText) {
                $statusText.html('<span style="color: #27ae60;">✓ Upload complete!</span>');
                setTimeout(() => $statusText.hide(), 2000);
            }

            // Call success callback
            if (onSuccess) {
                onSuccess(data);
            }
        },
        error: function(xhr, status, error) {
            // Hide progress bar
            if ($progressBar) {
                $progressBar.hide();
            }

            // Parse error response
            let errorMessage = 'Upload failed. Please try again.';
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.error) {
                    errorMessage = response.error;
                } else if (response.message) {
                    errorMessage = response.message;
                }
            } catch (e) {
                // Use default error message
            }

            // Show error message
            if ($statusText) {
                $statusText.html(`<span style="color: #e74c3c;">❌ ${errorMessage}</span>`);
            }

            // Call error callback
            if (onError) {
                onError({status: xhr.status, error: errorMessage, xhr: xhr});
            }
        }
    });
}

/**
 * Initialize drag and drop for file upload
 * @param {jQuery} $dropZone - Drop zone element
 * @param {jQuery} $fileInput - File input element
 * @param {Function} onFileDrop - Callback when file is dropped
 */
function Wo_InitDragAndDrop($dropZone, $fileInput, onFileDrop) {
    if (!$dropZone || $dropZone.length === 0) return;

    // Prevent default drag behaviors
    $dropZone.on('drag dragstart dragend dragover dragenter dragleave drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
    });

    // Add visual feedback
    $dropZone.on('dragover dragenter', function() {
        $(this).addClass('drag-over');
    });

    $dropZone.on('dragleave dragend drop', function() {
        $(this).removeClass('drag-over');
    });

    // Handle file drop
    $dropZone.on('drop', function(e) {
        const files = e.originalEvent.dataTransfer.files;
        if (files.length > 0) {
            // Update file input
            if ($fileInput && $fileInput.length > 0) {
                $fileInput[0].files = files;
                $fileInput.trigger('change');
            }

            // Call callback
            if (onFileDrop) {
                onFileDrop(files);
            }
        }
    });

    // Allow clicking to select file
    $dropZone.on('click', function() {
        if ($fileInput && $fileInput.length > 0) {
            $fileInput.trigger('click');
        }
    });
}
