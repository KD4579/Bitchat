/**
 * Wallet Transaction Form Handler
 * Handles form validation, AJAX submission, and UI interactions for wallet transactions
 */

class WalletTransactionHandler {
    constructor() {
        this.form = null;
        this.submitBtn = null;
        this.originalBtnText = '';
        this.init();
    }

    init() {
        this.form = document.getElementById('replenish-user-account');
        if (this.form) {
            this.setupEventListeners();
            this.setupImagePreview();
        }
    }

    setupEventListeners() {
        // Form submission
        this.form.addEventListener('submit', (e) => this.handleFormSubmit(e));
        
        // Transaction hash copy button
        const copyBtn = document.querySelector('[onclick="copyTransactionHash()"]');
        if (copyBtn) {
            copyBtn.addEventListener('click', (e) => this.copyTransactionHash(e));
        }
    }

    setupImagePreview() {
        const imageInput = document.getElementById('transaction_image');
        const preview = document.getElementById('image_preview');
        
        if (imageInput && preview) {
            imageInput.addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        preview.src = e.target.result;
                        preview.style.display = 'block';
                    };
                    reader.readAsDataURL(file);
                } else {
                    preview.style.display = 'none';
                }
            });
        }
    }

    copyTransactionHash(event) {
        const hashInput = document.getElementById('transaction_hash');
        if (!hashInput || !hashInput.value) {
            this.showToast('No transaction hash to copy', 'error');
            return;
        }

        hashInput.select();
        hashInput.setSelectionRange(0, 99999); // For mobile devices
        
        navigator.clipboard.writeText(hashInput.value).then(() => {
            const copyBtn = event.target.closest('button');
            const originalText = copyBtn.innerHTML;
            copyBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M13.854 3.646a.5.5 0 0 1 0 .708l-7 7a.5.5 0 0 1-.708 0l-3.5-3.5a.5.5 0 1 1 .708-.708L6.5 10.293l6.646-6.647a.5.5 0 0 1 .708 0z"/></svg>';
            setTimeout(() => {
                copyBtn.innerHTML = originalText;
            }, 2000);
            this.showToast('Transaction hash copied to clipboard', 'success');
        }).catch((err) => {
            console.error('Could not copy text: ', err);
            this.showToast('Failed to copy transaction hash', 'error');
        });
    }

    handleFormSubmit(event) {
        event.preventDefault();
        
        // Reset previous errors
        this.clearErrors();
        
        // Validate form
        const validationResult = this.validateForm();
        if (!validationResult.isValid) {
            this.showErrors(validationResult.errors);
            return false;
        }
        
        // Submit form
        this.submitForm();
    }

    validateForm() {
        const errors = [];
        const formData = new FormData(this.form);
        
        // Get form values
        const userEmail = formData.get('user_email');
        const walletType = formData.get('wallet_type');
        const amount = formData.get('amount');
        const transactionHash = formData.get('transaction_hash');
        const transactionImage = formData.get('transaction_image');
        
        // Email validation
        if (!userEmail) {
            errors.push('Please enter your email address');
            this.addErrorClass('user_email');
        } else if (!this.isValidEmail(userEmail)) {
            errors.push('Please enter a valid email address');
            this.addErrorClass('user_email');
        } else {
            this.removeErrorClass('user_email');
        }
        
        // Wallet type validation
        if (!walletType) {
            errors.push('Please select a wallet type');
            this.addErrorClass('wallet_type');
        } else {
            this.removeErrorClass('wallet_type');
        }
        
        // Amount validation
        if (!amount || amount <= 0) {
            errors.push('Please enter a valid amount');
            this.addErrorClass('amount');
        } else {
            this.removeErrorClass('amount');
        }
        
        // Transaction hash validation
        if (!transactionHash || transactionHash.trim() === '') {
            errors.push('Please enter the transaction hash');
            this.addErrorClass('transaction_hash');
        } else {
            this.removeErrorClass('transaction_hash');
        }
        
        // Image validation
        if (!transactionImage || transactionImage.size === 0) {
            errors.push('Please upload a transaction screenshot');
            this.addErrorClass('transaction_image');
        } else {
            this.removeErrorClass('transaction_image');
        }
        
        return {
            isValid: errors.length === 0,
            errors: errors
        };
    }

    isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    addErrorClass(fieldId) {
        const field = document.getElementById(fieldId);
        if (field) {
            field.classList.add('error');
        }
    }

    removeErrorClass(fieldId) {
        const field = document.getElementById(fieldId);
        if (field) {
            field.classList.remove('error');
        }
    }

    clearErrors() {
        // Remove error classes
        const errorFields = this.form.querySelectorAll('.error');
        errorFields.forEach(field => field.classList.remove('error'));
        
        // Remove error messages
        const errorAlerts = this.form.querySelectorAll('.alert');
        errorAlerts.forEach(alert => alert.remove());
    }

    showErrors(errors) {
        const errorHtml = `
            <div class="alert alert-danger">
                <ul>
                    ${errors.map(error => `<li>${error}</li>`).join('')}
                </ul>
            </div>
        `;
        this.form.insertAdjacentHTML('afterbegin', errorHtml);
    }

    submitForm() {
        // Show loading state
        this.submitBtn = this.form.querySelector('button[type="submit"]');
        this.originalBtnText = this.submitBtn.innerHTML;
        this.submitBtn.innerHTML = `
            <svg class="spinner" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                <path fill="currentColor" d="M12,4V2A10,10 0 0,0 2,12H4A8,8 0 0,1 12,4Z"/>
            </svg> Processing...
        `;
        this.submitBtn.disabled = true;
        
        // Prepare form data
        const formData = new FormData(this.form);
        
        // AJAX request
        fetch(Wo_Ajax_Requests_File() + '?f=wallet_transaction&s=submit', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 200) {
                // Success
                this.showSuccess(data.message);
                this.resetForm();
                
                // Redirect after 3 seconds
                setTimeout(() => {
                    window.location.reload();
                }, 3000);
            } else {
                // Error
                this.showError(data.message || 'Something went wrong');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            this.showError('Network error. Please try again.');
        })
        .finally(() => {
            // Reset button state
            this.submitBtn.innerHTML = this.originalBtnText;
            this.submitBtn.disabled = false;
        });
    }

    showSuccess(message) {
        const successHtml = `<div class="alert alert-success">${message}</div>`;
        this.form.insertAdjacentHTML('afterbegin', successHtml);
    }

    showError(message) {
        const errorHtml = `<div class="alert alert-danger">${message}</div>`;
        this.form.insertAdjacentHTML('afterbegin', errorHtml);
    }

    showToast(message, type = 'info') {
        // You can implement a toast notification system here
        console.log(`${type.toUpperCase()}: ${message}`);
    }

    resetForm() {
        this.form.reset();
        const preview = document.getElementById('image_preview');
        if (preview) {
            preview.style.display = 'none';
        }
    }
}

// Initialize wallet handler when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    new WalletTransactionHandler();
});

// Export for use in other files if needed
if (typeof module !== 'undefined' && module.exports) {
    module.exports = WalletTransactionHandler;
}
