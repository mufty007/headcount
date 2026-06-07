// modal.js
class Modal {
  constructor(id) {
    this.modal = document.getElementById(id);
    if (!this.modal) return;
    
    this.closeBtn = this.modal.querySelector('.modal-close');
    this.overlay = this.modal.querySelector('.modal-overlay');
    
    this.init();
  }
  
  init() {
    if (this.closeBtn) {
      this.closeBtn.addEventListener('click', () => this.close());
    }
    
    if (this.overlay) {
      this.overlay.addEventListener('click', () => this.close());
    }
    
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && this.modal.classList.contains('active')) {
        this.close();
      }
    });
  }
  
  open() {
    this.modal.classList.add('active');
    document.body.style.overflow = 'hidden';
  }
  
  close() {
    this.modal.classList.remove('active');
    document.body.style.overflow = '';
  }
}

// Helper function to create and show a modal
function showModal(modalId) {
  const modal = new Modal(modalId);
  modal.open();
  return modal;
}

function closeModal(modalId) {
  const modalElement = document.getElementById(modalId);
  if (modalElement) {
    modalElement.classList.remove('active');
    document.body.style.overflow = '';
  }
}
