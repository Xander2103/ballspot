const fs = require('fs');
const path = require('path');

function resolveHermesCli() {
  const projectRoot = path.resolve(__dirname, '..');
  const candidatePaths = [
    path.join(projectRoot, 'node_modules', 'hermes-compiler', 'hermesc', 'osx-bin', 'hermesc'),
  ];

  for (const candidate of candidatePaths) {
    if (fs.existsSync(candidate) && fs.statSync(candidate).isFile()) {
      return candidate;
    }
  }

  try {
    return require.resolve('hermes-compiler/hermesc/osx-bin/hermesc', { paths: [projectRoot] });
  } catch (error) {
    return null;
  }
}

function walk(dir, files) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const fullPath = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (['node_modules', '.git', 'Pods', 'build'].includes(entry.name)) {
        continue;
      }
      walk(fullPath, files);
      continue;
    }

    if (fullPath.endsWith('.xcconfig')) {
      files.push(fullPath);
    }
  }
}

function patchHermesPath() {
  const hermescPath = resolveHermesCli();
  if (!hermescPath) {
    console.warn('[eas-build-post-install] Hermes compiler not found; skipping HERMES_CLI_PATH patch.');
    return;
  }

  const iosDir = path.join(__dirname, '..', 'ios');
  const files = [];
  if (fs.existsSync(iosDir)) {
    walk(iosDir, files);
  }

  if (files.length === 0) {
    console.warn('[eas-build-post-install] No generated .xcconfig files found yet; no patch applied.');
    return;
  }

  let patched = 0;
  for (const filePath of files) {
    const original = fs.readFileSync(filePath, 'utf8');
    const updated = original.replace(/HERMES_CLI_PATH\s*=\s*.*$/gm, `HERMES_CLI_PATH = ${hermescPath}`);

    if (updated !== original) {
      fs.writeFileSync(filePath, updated, 'utf8');
      patched += 1;
    }
  }

  console.log(`[eas-build-post-install] Patched ${patched} xcconfig file(s) to use ${hermescPath}`);
}

patchHermesPath();
