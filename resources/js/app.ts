import { createInertiaApp, type ResolvedComponent } from '@inertiajs/svelte';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import AppLayout from '@/app/layouts/AppLayout.svelte';
import AuthLayout from '@/app/layouts/AuthLayout.svelte';
import SettingsLayout from '@/app/layouts/settings/Layout.svelte';
import { initializeFlashToast } from '@/shared/lib/flash-toast';
import { initializeTheme } from '@/app/lib/theme.svelte';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./src/pages/${name}.svelte`,
            import.meta.glob<ResolvedComponent>('./src/pages/**/*.svelte'),
        ),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('errors/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
