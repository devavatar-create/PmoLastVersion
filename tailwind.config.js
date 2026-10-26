import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * Avatar Electric PMO - theme restyle only (see AE_THEME.md).
 *
 * The app's Blade files already use Tailwind's stock `blue` (primary accent)
 * and `indigo` (Breeze scaffolding leftovers - login/profile forms) almost
 * everywhere for interactive/brand color, with `slate` for neutrals, and
 * `red`/`amber`/`emerald` for danger/warning/success (whose default Tailwind
 * 600 shades already equal the brand's Danger/Warning/Success hex values).
 * Re-pointing just `blue` and `indigo` at the Avatar Electric "Electric Blue"
 * family below re-themes every existing `bg-blue-600`, `text-blue-700`,
 * `focus:ring-indigo-500`, checkbox/radio accents (the forms plugin reads
 * `theme('colors.blue.600')` internally), etc. app-wide with zero template
 * edits and zero risk of touching structure, JS/Alpine hooks, or logic.
 */
const electric = {
    50: '#EAF4FF',
    100: '#D6E9FF',
    200: '#AED2FF',
    300: '#7EB8FF',
    400: '#4A9CF0',
    500: '#2E86DD',
    600: '#1479D7', // Electric Blue
    700: '#0F67B8', // Electric Blue Hover
    800: '#0D5494',
    900: '#0B1F3A', // Primary Dark (Sidebar background)
};

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',   // classes picked in JS (e.g. the file manager's file-type colours)
    ],

    theme: {
        extend: {
            fontFamily: {
                // Anuphan from public/fonts/anuphan (no internet needed); Thai fallbacks if it ever fails to load
                sans: ['Anuphan', 'Sarabun', 'Noto Sans Thai', 'Leelawadee UI', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                blue: electric,
                indigo: electric,
                // Named aliases for the handful of spots that reach for the
                // brand color directly (Sidebar background, page background,
                // light-blue tints) rather than through the `blue` scale.
                ae: {
                    navy: '#0B1F3A',
                    navylight: '#13294D',
                    bg: '#F5F7FA',
                    lightblue: '#EAF4FF',
                },
            },
        },
    },

    plugins: [forms],
};
