// validation.js
class FormValidator {
  static validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
  }
  
  static validatePhone(phone) {
    const cleaned = phone.replace(/\D/g, '');
    return cleaned.length >= 10 && cleaned.length <= 15;
  }
  
  static validateRequired(value) {
    return value.trim().length > 0;
  }
  
  static validateForm(formId) {
    const form = document.getElementById(formId);
    if (!form) return [];
    
    const errors = [];
    
    const requiredFields = form.querySelectorAll('[required]');
    requiredFields.forEach(field => {
      if (!this.validateRequired(field.value)) {
        const label = form.querySelector(`label[for="${field.id}"]`)?.textContent || field.name;
        errors.push({
          field: field.name || field.id,
          message: `${label} is required`
        });
        field.classList.add('error');
      } else {
        field.classList.remove('error');
      }
    });
    
    const emailFields = form.querySelectorAll('input[type="email"]');
    emailFields.forEach(field => {
      if (field.value && !this.validateEmail(field.value)) {
        errors.push({
          field: field.name || field.id,
          message: 'Invalid email format'
        });
        field.classList.add('error');
      } else if (field.value) {
        field.classList.remove('error');
      }
    });
    
    const phoneFields = form.querySelectorAll('input[type="tel"]');
    phoneFields.forEach(field => {
      if (field.value && !this.validatePhone(field.value)) {
        errors.push({
          field: field.name || field.id,
          message: 'Invalid phone number format'
        });
        field.classList.add('error');
      } else if (field.value) {
        field.classList.remove('error');
      }
    });
    
    return errors;
  }
  
  static showErrors(formId, errors) {
    const form = document.getElementById(formId);
    if (!form) return;
    
    // Clear existing error messages
    form.querySelectorAll('.form-error').forEach(el => el.remove());
    
    errors.forEach(error => {
      const field = form.querySelector(`[name="${error.field}"], #${error.field}`);
      if (field) {
        field.classList.add('error');
        const errorDiv = document.createElement('div');
        errorDiv.className = 'form-error';
        errorDiv.textContent = error.message;
        field.parentElement.appendChild(errorDiv);
      }
    });
  }
}
