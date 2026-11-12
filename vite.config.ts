import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        inertia(),
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ]),
    server: {
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
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            // Registry items (app-ui), formatted upstream; the drift check keeps them in sync.
            'resources/js/components/realtime/*',
            'resources/js/components/admin/*',
            'resources/js/components/layout/*',
            'resources/js/components/confirm-destructive-dialog.tsx',
            'resources/js/components/settings-form-actions.tsx',
            'resources/js/components/nav-user.tsx',
            'resources/js/components/user-menu-content.tsx',
            'resources/js/components/user-info.tsx',
            'resources/js/components/app-shell.tsx',
            'resources/js/components/app-content.tsx',
            'resources/js/components/app-sidebar-header.tsx',
            'resources/js/components/breadcrumbs.tsx',
            'resources/js/hooks/use-mobile.tsx',
            'resources/js/hooks/use-scroll-region.ts',
            'resources/js/lib/avatar-tone.ts',
            'resources/js/lib/list-per-page.ts',
            'resources/js/lib/live-announcer.ts',
            'resources/js/lib/scroll-cue.ts',
            'resources/js/lib/toast.ts',
            'resources/js/lib/utils.ts',
            'resources/css/qiick-theme.css',
            'resources/css/shadcn-tailwind.css',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
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
            '.github/**',
            'composer.json',
            'resources/js/components/ui/*',
            // Registry items (app-ui), formatted upstream; the drift check keeps them in sync.
            'resources/js/components/realtime/*',
            'resources/js/components/admin/*',
            'resources/js/components/layout/*',
            'resources/js/components/confirm-destructive-dialog.tsx',
            'resources/js/components/settings-form-actions.tsx',
            'resources/js/components/nav-user.tsx',
            'resources/js/components/user-menu-content.tsx',
            'resources/js/components/user-info.tsx',
            'resources/js/components/app-shell.tsx',
            'resources/js/components/app-content.tsx',
            'resources/js/components/app-sidebar-header.tsx',
            'resources/js/components/breadcrumbs.tsx',
            'resources/js/hooks/use-mobile.tsx',
            'resources/js/hooks/use-scroll-region.ts',
            'resources/js/lib/avatar-tone.ts',
            'resources/js/lib/list-per-page.ts',
            'resources/js/lib/live-announcer.ts',
            'resources/js/lib/scroll-cue.ts',
            'resources/js/lib/toast.ts',
            'resources/js/lib/utils.ts',
            'resources/css/qiick-theme.css',
            'resources/css/shadcn-tailwind.css',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            stylesheet: 'resources/css/app.css',
        },
    },
});
