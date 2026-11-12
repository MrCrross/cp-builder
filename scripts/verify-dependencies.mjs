#!/usr/bin/env node
// Сверка прямых зависимостей с реестром docs/dependencies.md (план §5.6).
// Новая прямая зависимость без записи в реестре = ненулевой exit code.

import { readFileSync } from 'node:fs';

const registry = readFileSync('docs/dependencies.md', 'utf8');

const composer = JSON.parse(readFileSync('composer.json', 'utf8'));
const pkg = JSON.parse(readFileSync('package.json', 'utf8'));

// Платформенные optional-пины (binaries под linux/win), не требуют отдельной записи.
const platformPin = /^@rollup\/|^lightningcss-|^@tailwindcss\/oxide-/;

const missing = [];

for (const name of Object.keys(composer.require ?? {})) {
    if (name === 'php' || name.startsWith('ext-')) {
        continue;
    }
    if (!registry.includes(`| ${name} `) && !registry.includes(`| ${name} +`) && !registry.includes(name)) {
        missing.push(`composer: ${name}`);
    }
}
for (const name of Object.keys(composer['require-dev'] ?? {})) {
    if (!registry.includes(name)) {
        missing.push(`composer (dev): ${name}`);
    }
}
for (const name of Object.keys(pkg.dependencies ?? {})) {
    if (!registry.includes(name)) {
        missing.push(`npm: ${name}`);
    }
}
for (const name of Object.keys(pkg.devDependencies ?? {})) {
    if (!registry.includes(name)) {
        missing.push(`npm (dev): ${name}`);
    }
}
for (const name of Object.keys(pkg.optionalDependencies ?? {})) {
    if (platformPin.test(name)) {
        continue;
    }
    if (!registry.includes(name)) {
        missing.push(`npm (optional): ${name}`);
    }
}

if (missing.length > 0) {
    console.error('Зависимости без записи в docs/dependencies.md:');
    for (const entry of missing) {
        console.error(`  - ${entry}`);
    }
    process.exit(1);
}

console.log('Реестр зависимостей: все прямые зависимости задокументированы.');
