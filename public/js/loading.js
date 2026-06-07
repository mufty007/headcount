// loading.js
class LoadingIndicator {
  static show(element) {
    if (element) {
      element.classList.add('loading');
      element.disabled = true;
    }
  }
  
  static hide(element) {
    if (element) {
      element.classList.remove('loading');
      element.disabled = false;
    }
  }
  
  static showSpinner(container) {
    if (!container) return null;
    
    const spinner = document.createElement('div');
    spinner.className = 'spinner';
    spinner.innerHTML = '<div class="spinner-circle"></div>';
    container.appendChild(spinner);
    return spinner;
  }
  
  static hideSpinner(container) {
    if (!container) return;
    
    const spinner = container.querySelector('.spinner');
    if (spinner) {
      spinner.remove();
    }
  }
}
