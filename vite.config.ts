import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath } from 'node:url';
import { defaultAllowedOrigins, defineConfig, lazyPlugins } from 'vite-plus';

const devServerOrigin = process.env.VITE_DEV_SERVER_ORIGIN || undefined;

const isSvelteCheck = process.argv.some((argument) =>
    argument.includes('svelte-check'),
);

if (isSvelteCheck) {
    process.env.LARAVEL_BYPASS_ENV_CHECK ??= '1';
}

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        inertia(),
        tailwindcss(),
        svelte(),
        wayfinder({
            formVariants: true,
        }),
    ]),
    resolve: {
        alias: [
            // Точные алиасы wayfinder-генерации — до общего `@`, иначе их
            // перехватит `@/*` (порядок проверки find слева направо).
            {
                find: '@/routes',
                replacement: fileURLToPath(
                    new URL('./resources/js/routes', import.meta.url),
                ),
            },
            {
                find: '@/actions',
                replacement: fileURLToPath(
                    new URL('./resources/js/actions', import.meta.url),
                ),
            },
            {
                // FSD-слои живут в resources/js/src; перекрывает дефолтный
                // alias `@` → resources/js из laravel-vite-plugin v3
                // (он дописывает свои дефолты ПОСЛЕ пользовательских).
                find: '@',
                replacement: fileURLToPath(
                    new URL('./resources/js/src', import.meta.url),
                ),
            },
        ],
    },
    server: {
        // В контейнерном dev (Docker) адрес, записываемый в public/hot,
        // должен быть reachable с хоста: VITE_DEV_SERVER_ORIGIN=http://localhost:5173
        origin: devServerOrigin,
        // Явный origin встраивается laravel-плагином в CORS как единственный
        // разрешённый — расширяем список обратно (страница живёт на :80).
        cors: devServerOrigin
            ? {
                  origin: [
                      defaultAllowedOrigins,
                      devServerOrigin,
                      /^https?:\/\/localhost(:\d+)?$/,
                      /^https?:\/\/.*\.test(:\d+)?$/,
                  ],
              }
            : undefined,
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            '.playwright-cli/**',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
            'resources/js/src/shared/ui/*/*',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.agents/**',
            '.ai/**',
            '.github/**',
            '.playwright-cli/**',
            'composer.json',
            'docs/**',
            'scripts/**',
            'AGENTS.md',
            'PROGRESS.md',
            'README.md',
            'compose.yaml',
            'compose.override.yaml',
            'compose.prod.yaml',
            'skills-lock.json',
            'resources/fonts/**',
            'resources/js/actions/**',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
            'resources/js/src/shared/ui/*/*',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
