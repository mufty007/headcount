# Tailwind CSS Setup

This project uses Tailwind CSS compiled for production instead of the CDN.

## Installation

1. Install Node.js and npm if you haven't already.

2. Install dependencies:
```bash
npm install
```

## Building CSS

### Development (with watch mode):
```bash
npm run watch-css
```
This will watch for changes and automatically rebuild the CSS.

### Production (one-time build):
```bash
npm run build-css
```
This will create a minified `public/css/tailwind-output.css` file.

## How It Works

- **Source file**: `public/css/tailwind-input.css` - Contains Tailwind directives and custom CSS
- **Output file**: `public/css/tailwind-output.css` - Compiled, minified CSS (generated)
- **Config**: `tailwind.config.js` - Tailwind configuration

The header automatically uses the compiled CSS if it exists, otherwise falls back to `modern-design.css`.

## Adding New Styles

1. Add Tailwind classes directly in your HTML/PHP files
2. For custom CSS, add it to `public/css/tailwind-input.css`
3. Run `npm run build-css` to compile

## Production Deployment

Make sure to run `npm run build-css` before deploying to production to ensure the compiled CSS is up to date.
