import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import { heroui } from '@heroui/react';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{ts,tsx}',
        // HeroUI theme: npm lo instala anidado bajo @heroui/react (no hoisted),
        // y sus clases (rounded-large, shadow-medium, popovers, labels…) viven en
        // archivos .js/.mjs. Escanear ambas rutas evita que se pierdan esos estilos.
        './node_modules/@heroui/theme/dist/**/*.{js,mjs}',
        './node_modules/@heroui/react/node_modules/@heroui/theme/dist/**/*.{js,mjs}',
    ],

    darkMode: 'class',

    theme: {
        extend: {
            fontFamily: {
                sans: [
                    '-apple-system',
                    'BlinkMacSystemFont',
                    'Inter',
                    'Segoe UI',
                    'Roboto',
                    ...defaultTheme.fontFamily.sans,
                ],
            },
            boxShadow: {
                soft: '0 1px 2px rgba(16,24,40,0.04), 0 1px 3px rgba(16,24,40,0.06)',
                card: '0 4px 20px -4px rgba(16,24,40,0.08)',
                float: '0 8px 30px -6px rgba(16,24,40,0.14)',
            },
            keyframes: {
                'pop-in': {
                    '0%': { transform: 'scale(0.9)', opacity: '0' },
                    '100%': { transform: 'scale(1)', opacity: '1' },
                },
            },
            animation: {
                'pop-in': 'pop-in 0.25s ease-out',
            },
        },
    },

    plugins: [
        // Estrategia 'class': evita que el reset de forms pise los estilos de HeroUI
        // (si no, los inputs/botones se ven cuadrados y con bordes duros).
        forms({ strategy: 'class' }),
        heroui({
            layout: {
                radius: { small: '10px', medium: '14px', large: '20px' },
                borderWidth: { small: '1px', medium: '1px', large: '2px' },
            },
            themes: {
                light: {
                    colors: {
                        background: '#F5F5F7',
                        foreground: '#1C1C1E',
                        content1: '#FFFFFF',
                        content2: '#FAFAFB',
                        divider: 'rgba(17,17,17,0.08)',
                        default: {
                            50: '#fafafa', 100: '#f4f4f5', 200: '#e4e4e7', 300: '#d4d4d8',
                            400: '#a1a1aa', 500: '#71717a', 600: '#52525b', 700: '#3f3f46',
                            800: '#27272a', 900: '#18181b',
                            DEFAULT: '#e4e4e7', foreground: '#1C1C1E',
                        },
                        primary: {
                            50: '#f5f3ff', 100: '#ede9fe', 200: '#ddd6fe', 300: '#c4b5fd',
                            400: '#a78bfa', 500: '#8b5cf6', 600: '#7c3aed', 700: '#6d28d9',
                            800: '#5b21b6', 900: '#4c1d95',
                            DEFAULT: '#7c3aed', foreground: '#ffffff',
                        },
                        success: { DEFAULT: '#16a34a', foreground: '#ffffff' },
                        warning: { DEFAULT: '#f59e0b', foreground: '#ffffff' },
                        danger: { DEFAULT: '#e11d48', foreground: '#ffffff' },
                    },
                },
                dark: {
                    colors: {
                        background: '#0B0B0F',
                        foreground: '#F5F5F7',
                        content1: '#17171C',
                        content2: '#1F1F26',
                        divider: 'rgba(255,255,255,0.10)',
                        default: {
                            50: '#18181b', 100: '#27272a', 200: '#3f3f46', 300: '#52525b',
                            400: '#71717a', 500: '#a1a1aa', 600: '#d4d4d8', 700: '#e4e4e7',
                            800: '#f4f4f5', 900: '#fafafa',
                            DEFAULT: '#27272a', foreground: '#F5F5F7',
                        },
                        primary: {
                            50: '#4c1d95', 100: '#5b21b6', 200: '#6d28d9', 300: '#7c3aed',
                            400: '#8b5cf6', 500: '#a78bfa', 600: '#c4b5fd', 700: '#ddd6fe',
                            800: '#ede9fe', 900: '#f5f3ff',
                            DEFAULT: '#8b5cf6', foreground: '#ffffff',
                        },
                        success: { DEFAULT: '#22c55e', foreground: '#04140a' },
                        warning: { DEFAULT: '#f59e0b', foreground: '#1a1204' },
                        danger: { DEFAULT: '#fb7185', foreground: '#1a0509' },
                    },
                },
            },
        }),
    ],
};
