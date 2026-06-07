# Frontend Assets

This directory contains all frontend assets for the Headcount platform.

## Directory Structure

```
public/
├── css/
│   ├── main.css          # Main stylesheet with base styles
│   ├── checkin.css       # Check-in interface specific styles
│   └── modal.css         # Modal component styles
├── js/
│   ├── api.js            # API client for backend communication
│   ├── validation.js     # Form validation utilities
│   ├── toast.js          # Toast notification system
│   ├── modal.js          # Modal component system
│   ├── loading.js        # Loading indicator utilities
│   └── checkin.js        # Check-in interface functionality
└── README.md             # This file
```

## CSS Files

### main.css
Base stylesheet containing:
- CSS variables for theming
- Button styles (primary, secondary)
- Form styles
- Card components
- Dashboard layout
- Stats grid
- Event cards
- Members table
- Responsive breakpoints

### checkin.css
Styles specific to the check-in interface:
- Check-in layout
- Search box styling
- Member card styles
- Mobile optimizations

### modal.css
Modal component styles:
- Overlay
- Modal content
- Close button
- Responsive design

## JavaScript Files

### api.js
`APIClient` class for making API requests:
```javascript
const api = new APIClient();
await api.get('/members');
await api.post('/attendance/checkin', { event_id: 1, user_id: 2 });
```

### validation.js
`FormValidator` class for form validation:
```javascript
const errors = FormValidator.validateForm('my-form');
FormValidator.showErrors('my-form', errors);
```

### toast.js
Toast notification system:
```javascript
Toast.success('Member checked in!');
Toast.error('Something went wrong');
Toast.info('Processing...');
```

### modal.js
Modal component system:
```javascript
const modal = new Modal('my-modal');
modal.open();
modal.close();
```

### loading.js
Loading indicator utilities:
```javascript
LoadingIndicator.show(button);
LoadingIndicator.showSpinner(container);
```

### checkin.js
Check-in interface functionality:
- Debounced search (300ms)
- Member search and display
- Check-in/undo check-in
- Real-time count updates
- Keyboard shortcuts (Enter to check in)

## Usage in Templates

Include CSS files in the `<head>`:
```html
<link rel="stylesheet" href="/public/css/main.css">
<link rel="stylesheet" href="/public/css/checkin.css">
```

Include JavaScript files before closing `</body>`:
```html
<script src="/public/js/api.js"></script>
<script src="/public/js/toast.js"></script>
<script src="/public/js/checkin.js"></script>
```

## Design System

### Colors
- Primary: `#3B82F6` (Blue)
- Secondary: `#10B981` (Green)
- Danger: `#EF4444` (Red)
- Warning: `#F59E0B` (Orange)
- Success: `#10B981` (Green)
- Background: `#F9FAFB` (Light Gray)
- Text: `#111827` (Dark Gray)

### Typography
- Font Family: Inter, system-ui, -apple-system, sans-serif
- H1: 2.25rem (36px), bold
- H2: 1.875rem (30px), semibold
- H3: 1.5rem (24px), semibold
- Body: 1rem (16px), normal

### Breakpoints
- Mobile: < 640px
- Tablet: 640px - 1024px
- Desktop: > 1024px

## Accessibility

All components follow WCAG 2.1 AA guidelines:
- Proper ARIA labels
- Keyboard navigation support
- Focus indicators
- Color contrast ratios
- Semantic HTML

## Browser Support

- Chrome (latest)
- Firefox (latest)
- Safari (latest)
- Edge (latest)

## Notes

- No build process required - files are served directly
- Tailwind CSS can be added via CDN if needed for additional utilities
- All JavaScript uses ES6+ features
- CSS uses CSS variables for theming
