/* public/js/modern-ui.js */
document.addEventListener('DOMContentLoaded', () => {
    console.log('Modern UI Initialized');

    // Global Search Shortcut (Ctrl+K or Cmd+K)
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            const searchInput = document.querySelector('header input[type="text"]');
            if (searchInput) searchInput.focus();
        }
    });

    // Custom Scrollbar styling for Main Content
    const mainContent = document.querySelector('main');
    if (mainContent) {
        // We could add custom scrollbar logic here if needed beyond CSS
    }
});
