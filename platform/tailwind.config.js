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
                // Republic of Yemen flag palette, tuned for a 60 / 30 / 10 split:
                //   60% white + ivory surfaces, 30% charcoal ink, 10% crimson accent.
                crimson: {
                    DEFAULT: '#CE1126',
                    50: '#FDF2F3',
                    100: '#FCE4E7',
                    200: '#F8BFC6',
                    300: '#F195A2',
                    400: '#E24C60',
                    500: '#CE1126',
                    600: '#A80D1E', // hover / pressed
                    700: '#8C0B19',
                    800: '#6E0914',
                    900: '#52070F',
                },
                // Charcoal instead of pure black: softer on phone screens.
                ink: {
                    DEFAULT: '#111315',
                    50: '#F8F9FA',
                    100: '#F1F3F5',
                    200: '#E3E6E9',
                    300: '#C9CED3',
                    400: '#9AA1A8',
                    500: '#6B7279',
                    600: '#4A5056',
                    700: '#2E3236',
                    800: '#1E2022',
                    900: '#111315',
                    950: '#0A0B0C',
                },
                // Legacy alias: every existing `obsidian-*` class now resolves to charcoal.
                obsidian: {
                    DEFAULT: '#111315',
                    50: '#F8F9FA',
                    100: '#F1F3F5',
                    800: '#1E2022',
                    900: '#111315',
                    950: '#0A0B0C',
                },
                // Stock palette names used by older pages, re-pointed at the brand:
                // neutral greys and blues become charcoal, red becomes Yemeni crimson.
                slate: {
                    50: '#F8F9FA', 100: '#F1F3F5', 200: '#E3E6E9', 300: '#C9CED3', 400: '#9AA1A8',
                    500: '#6B7279', 600: '#4A5056', 700: '#2E3236', 800: '#1E2022', 900: '#111315', 950: '#0A0B0C',
                },
                blue: {
                    50: '#F1F3F5', 100: '#E3E6E9', 200: '#C9CED3', 300: '#9AA1A8', 400: '#6B7279',
                    500: '#4A5056', 600: '#1E2022', 700: '#111315', 800: '#111315', 900: '#0A0B0C',
                },
                red: {
                    50: '#FDF2F3', 100: '#FCE4E7', 200: '#F8BFC6', 300: '#F195A2', 400: '#E24C60',
                    500: '#CE1126', 600: '#CE1126', 700: '#A80D1E', 800: '#8C0B19', 900: '#52070F',
                },
                ivory: '#F8F9FA',
                offwhite: '#F8F9FA',
                // Used sparingly: KYC-verified badges and ratings only.
                gold: {
                    DEFAULT: '#D4AF37',
                    50: '#FBF7E9',
                    100: '#F5ECC8',
                    600: '#B08F22',
                    700: '#8A6F18',
                },
                whatsapp: '#25D366',
            },
            boxShadow: {
                sheet: '0 -8px 40px -12px rgba(17, 19, 21, 0.25)',
                card: '0 4px 24px -8px rgba(17, 19, 21, 0.10)',
                lift: '0 18px 40px -18px rgba(17, 19, 21, 0.28)',
                glow: '0 12px 30px -10px rgba(206, 17, 38, 0.45)',
            },
            keyframes: {
                'sheet-up': {
                    '0%': { transform: 'translateY(100%)' },
                    '100%': { transform: 'translateY(0)' },
                },
                // Marquee tracks hold two copies of the content, so half the width is one loop.
                marquee: { to: { transform: 'translateX(-50%)' } },
                'marquee-rtl': { to: { transform: 'translateX(50%)' } },
                'rise-in': {
                    '0%': { opacity: '0', transform: 'translateY(18px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                drift: {
                    '0%, 100%': { transform: 'translate3d(0, 0, 0) scale(1)' },
                    '50%': { transform: 'translate3d(4%, -6%, 0) scale(1.08)' },
                },
                float: {
                    '0%, 100%': { transform: 'translateY(0)' },
                    '50%': { transform: 'translateY(-8px)' },
                },
            },
            animation: {
                'sheet-up': 'sheet-up 0.28s cubic-bezier(0.32, 0.72, 0, 1)',
                marquee: 'marquee var(--marquee-duration, 30s) linear infinite',
                'marquee-rtl': 'marquee-rtl var(--marquee-duration, 30s) linear infinite',
                'rise-in': 'rise-in 0.7s cubic-bezier(0.22, 1, 0.36, 1) both',
                drift: 'drift 14s ease-in-out infinite',
                float: 'float 6s ease-in-out infinite',
            },
        },
    },
    plugins: [forms],
};
