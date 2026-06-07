const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const srcDir = path.join(root, 'node_modules', 'quill', 'dist');
const targets = [
  path.join(root, 'public', 'admin', 'vendor', 'quill'),
  path.join(root, 'public', 'js', 'vendor', 'quill'),
];

const files = [
  { from: 'quill.js', to: 'quill.js' },
  { from: 'quill.min.js', to: 'quill.js', optional: true },
  { from: 'quill.snow.css', to: 'quill.snow.css' },
];

if (!fs.existsSync(srcDir)) {
  console.warn('copy-quill: node_modules/quill not found; run npm install first.');
  process.exit(0);
}

for (const targetDir of targets) {
  fs.mkdirSync(targetDir, { recursive: true });
  for (const { from, to, optional } of files) {
    const src = path.join(srcDir, from);
    if (!fs.existsSync(src)) {
      if (optional) continue;
      console.warn(`copy-quill: missing ${from}`);
      continue;
    }
    const dest = path.join(targetDir, to);
    if (fs.existsSync(dest) && to === 'quill.js' && from === 'quill.min.js') {
      continue;
    }
    fs.copyFileSync(src, dest);
    console.log(`copy-quill: ${from} -> ${path.relative(root, dest)}`);
  }
}
