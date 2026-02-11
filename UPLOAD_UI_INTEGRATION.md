# Upload UI Enhancements - Integration Guide

This guide explains how to use the new upload enhancements (progress bars, file previews, drag-drop, and validation) in Bitchat.

## Files Created

1. `/themes/wowonder/javascript/upload-helper.js` - Core upload functionality
2. `/themes/wowonder/stylesheet/upload-enhancements.css` - Upload UI styles
3. Backend improvements in `xhr/upload_image.php` and `xhr/upload-blog-image.php`

## How to Include

Add these to your page template (e.g., in `header.phtml` or specific page):

```html
<!-- CSS -->
<link rel="stylesheet" href="<?php echo $wo['config']['theme_url']; ?>/stylesheet/upload-enhancements.css">

<!-- JavaScript (after jQuery and before your custom scripts) -->
<script src="<?php echo $wo['config']['theme_url']; ?>/javascript/upload-helper.js"></script>
```

## Usage Examples

### Example 1: Simple Image Upload with Progress

```html
<!-- HTML -->
<div class="upload-section">
    <input type="file" id="my-file-input" accept="image/*" style="display: none;">
    <button onclick="$('#my-file-input').click()">Choose Image</button>

    <div id="preview-container"></div>

    <div class="upload-progress-container" id="progress-container" style="display: none;">
        <div class="upload-progress-bar">
            <div class="upload-progress-bar-fill" id="progress-bar" style="width: 0%;"></div>
        </div>
        <div class="upload-status-text" id="status-text"></div>
    </div>
</div>

<script>
$('#my-file-input').on('change', function() {
    const file = this.files[0];
    if (!file) return;

    // Show preview
    Wo_ShowFilePreview(file, $('#preview-container'));

    // Upload with progress
    Wo_UploadFileWithProgress({
        file: file,
        endpoint: '?f=upload_image',
        uploadType: 'image',
        userPlanType: <?php echo $wo['user']['pro_type'] ?? 0; ?>,
        $progressBar: $('#progress-bar'),
        $statusText: $('#status-text'),
        onSuccess: function(data) {
            if (data.status == 200) {
                console.log('Upload complete:', data.image);
                // Do something with the uploaded image
            }
        },
        onError: function(error) {
            alert(error.error || 'Upload failed');
        },
        onProgress: function(percent, loaded, total) {
            console.log(`Upload progress: ${percent}%`);
        }
    });

    // Show progress container
    $('#progress-container').show();
});
</script>
```

### Example 2: Drag and Drop Upload

```html
<!-- HTML -->
<div class="drag-drop-zone" id="drop-zone">
    <div class="drop-icon">📁</div>
    <div class="drop-text">Drag & drop your file here</div>
    <div class="drop-hint">or click to browse</div>
</div>

<input type="file" id="hidden-input" style="display: none;">

<div id="file-preview"></div>
<div class="upload-progress-container" id="upload-progress" style="display: none;">
    <div class="upload-progress-bar">
        <div class="upload-progress-bar-fill" id="progress-fill"></div>
    </div>
    <div class="upload-status-text" id="upload-status"></div>
</div>

<script>
// Initialize drag and drop
Wo_InitDragAndDrop(
    $('#drop-zone'),
    $('#hidden-input'),
    function(files) {
        const file = files[0];

        // Validate before upload
        const validation = Wo_ValidateFile(file, 'any', <?php echo $wo['user']['pro_type'] ?? 0; ?>);
        if (!validation.valid) {
            alert(validation.error);
            return;
        }

        // Show preview
        Wo_ShowFilePreview(file, $('#file-preview'));

        // Upload
        Wo_UploadFileWithProgress({
            file: file,
            endpoint: '?f=upload_image',
            uploadType: 'any',
            userPlanType: <?php echo $wo['user']['pro_type'] ?? 0; ?>,
            $progressBar: $('#progress-fill'),
            $statusText: $('#upload-status'),
            onSuccess: function(data) {
                $('#drop-zone').addClass('upload-complete');
                setTimeout(() => $('#drop-zone').removeClass('upload-complete'), 500);
            },
            onError: function(error) {
                alert(error.error);
            }
        });

        $('#upload-progress').show();
    }
);

// Also handle manual file selection
$('#hidden-input').on('change', function() {
    if (this.files.length > 0) {
        Wo_InitDragAndDrop.onFileDrop(this.files);
    }
});
</script>
```

### Example 3: File Validation Before Upload

```javascript
// Validate a file before uploading
const fileInput = document.getElementById('my-file-input');
const file = fileInput.files[0];

const validation = Wo_ValidateFile(file, 'image', <?php echo $wo['user']['pro_type'] ?? 0; ?>);

if (!validation.valid) {
    // Show error message
    alert(validation.error);
    return;
}

// Proceed with upload...
```

### Example 4: Updating Existing Upload Functions

You can easily upgrade existing upload functions to use progress tracking:

```javascript
// OLD WAY (no progress):
function Wo_UploadCommentImage(id) {
    var data = new FormData();
    data.append('image', $('#comment_image_' + id).prop('files')[0]);
    $.ajax({
        type: "POST",
        url: Wo_Ajax_Requests_File() + '?f=upload_image&id=' + id,
        data: data,
        processData: false,
        contentType: false,
        success: function(data) {
            // Handle success
        }
    });
}

// NEW WAY (with progress, validation, and error handling):
function Wo_UploadCommentImage(id) {
    const file = $('#comment_image_' + id).prop('files')[0];
    const $container = $('#post-' + id);

    // Add progress bar HTML to container (do this once in template):
    // <div class="comment-upload-progress"><div class="progress-fill"></div></div>

    Wo_UploadFileWithProgress({
        file: file,
        endpoint: '?f=upload_image&id=' + id,
        uploadType: 'image',
        userPlanType: userProType, // Pass from PHP
        $progressBar: $container.find('.progress-fill'),
        $statusText: $container.find('.upload-status'),
        onSuccess: function(data) {
            if (data.status == 200) {
                // Show uploaded image
                $container.find('#comment-image').html(
                    '<img src="' + data.image + '">' +
                    '<div class="remove-icon" onclick="Wo_EmptyCommentImage(' + id + ')">×</div>'
                );
            } else if (data.error) {
                alert(data.error);
            }
        },
        onError: function(error) {
            alert(error.error || 'Upload failed');
        }
    });
}
```

## API Reference

### `Wo_ValidateFile(file, uploadType, userPlanType)`

Validates a file before upload.

**Parameters:**
- `file` (File): File object from input
- `uploadType` (string): 'image', 'video', 'audio', 'document', or 'any'
- `userPlanType` (number): User's plan (1=Star, 2=Hot, 3=Ultima, 4=VIP, 0=Free)

**Returns:** `{valid: boolean, error: string|null}`

### `Wo_ShowFilePreview(file, $container)`

Displays a preview of the selected file.

**Parameters:**
- `file` (File): File object
- `$container` (jQuery): Container element to show preview

### `Wo_UploadFileWithProgress(options)`

Uploads a file with progress tracking.

**Options:**
- `file` (File): File to upload
- `endpoint` (string): Upload endpoint (e.g., '?f=upload_image')
- `uploadType` (string): File type for validation
- `userPlanType` (number): User's plan type
- `$progressBar` (jQuery): Progress bar fill element
- `$statusText` (jQuery): Status text element
- `onSuccess` (function): Success callback
- `onError` (function): Error callback
- `onProgress` (function): Progress callback (percent, loaded, total)

### `Wo_InitDragAndDrop($dropZone, $fileInput, onFileDrop)`

Initializes drag-and-drop functionality.

**Parameters:**
- `$dropZone` (jQuery): Drop zone element
- `$fileInput` (jQuery): Hidden file input
- `onFileDrop` (function): Callback when files are dropped

### `Wo_FormatFileSize(bytes)`

Formats file size for display.

**Parameters:**
- `bytes` (number): File size in bytes

**Returns:** String (e.g., "2.5 MB")

## CSS Classes

### Progress Bar
- `.upload-progress-container` - Container for progress bar
- `.upload-progress-bar` - Progress bar background
- `.upload-progress-bar-fill` - Progress bar fill (animate width 0-100%)
- `.upload-status-text` - Status text below progress bar

### Drag and Drop
- `.drag-drop-zone` - Drop zone container
- `.drag-over` - Applied when file is dragged over zone

### File Preview
- `.upload-preview` - Preview container
- `.file-info` - File name and size display

### Messages
- `.upload-error` - Error message
- `.upload-success` - Success message
- `.file-size-indicator` - File size badge
  - `.size-ok` - Size within limits
  - `.size-warning` - Size close to limit
  - `.size-error` - Size exceeds limit

## Backend Changes

### Enhanced Error Messages

Both `upload_image.php` and `upload-blog-image.php` now return detailed JSON error messages:

```json
{
    "status": 500,
    "error": "File is too large (max: 50MB)."
}
```

```json
{
    "status": 400,
    "error": "File type '.exe' is not allowed. Allowed: jpg,png,jpeg,gif"
}
```

This provides better feedback to users about why their upload failed.

## Testing Checklist

- [ ] File validation works for size limits
- [ ] File validation works for type restrictions
- [ ] Progress bar updates during upload
- [ ] Preview displays correctly for images
- [ ] Preview displays correctly for videos
- [ ] Drag and drop works
- [ ] Error messages display properly
- [ ] Success feedback appears
- [ ] Works on mobile devices
- [ ] Works with existing CSRF protection
- [ ] Plan-based size limits are enforced

## Browser Compatibility

- Chrome 60+
- Firefox 55+
- Safari 11+
- Edge 79+
- Mobile browsers (iOS Safari 11+, Chrome Mobile)

Uses standard APIs:
- FileReader API
- FormData API
- XMLHttpRequest with progress events
- URL.createObjectURL()

## Future Enhancements

Potential future improvements:
- Chunked uploads for very large files
- Resume interrupted uploads
- Image compression before upload
- Multiple simultaneous uploads
- Upload queue management UI
- Webcam capture for profile photos
- Cropping tool integration
