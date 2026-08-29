import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
    ],
    theme: {
        extend: {
            fontFamily: {
                // Arabic-first; Inter carries Latin glyphs.
                sans: ['Tajawal', 'Readex Pro', 'Inter', ...defaultTheme.fontFamily.sans],
                latin: ['Inter', 'Plus Jakarta Sans', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Republic of Yemen national palette.
                crimson: {
                    DEFAULT: '#CE1126',
                    50: '#FDF2F3',
                    100: '#FCE4E7',
                    200: '#F8BFC6',
                    300: '#F195A2',
                    400: '#E24C60',
                    500: '#CE1126',
                    600: '#B70F22',
                    700: '#960C1C',
                    800: '#750A16',
                    900: '#5A0811',
                },
                obsidian: {
                    DEFAULT: '#0F172A',
                    50: '#F8FAFC',
                    100: '#F1F5F9',
                    800: '#1E293B',
                    900: '#0F172A',
                    950: '#020617',
                },
                offwhite: '#F8FAFC',
                whatsapp: '#25D366',
            },
            boxShadow: {
                sheet: '0 -8px 40px -12px rgba(15, 23, 42, 0.25)',
                card: '0 4px 24px -8px rgba(15, 23, 42, 0.12)',
            },
            keyframes: {
                'sheet-up': {
                    '0%': { transform: 'translateY(100%)' },
                    '100%': { transform: 'translateY(0)' },
                },
            },
            animation: {
                'sheet-up': 'sheet-up 0.28s cubic-bezier(0.32, 0.72, 0, 1)',
            },
        },
    },
    plugins: [forms],
};
