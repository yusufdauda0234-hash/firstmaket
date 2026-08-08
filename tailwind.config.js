/** @type {import('tailwindcss').Config} */
export default {
    // Class-based dark mode: `dark:` variants only apply when a `.dark` class
    // is present on the tree. FirstMaket ships a single light design across
    // the public site, admin portal, Vendor Center and customer pages, so
    // without that class every `dark:` utility stays inert — the app no longer
    // flips to dark just because the visitor's OS is in dark mode.
    darkMode: 'class',
    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{js,jsx,ts,tsx}',
    ],
    theme: {
        extend: {
            colors: {
                // FirstMaket brand palette — see docs/FirstMaket_Brand_Assets.md
                brand: {
                    50: '#eef4fc',
                    100: '#d9e6f8',
                    200: '#b0ccf0',
                    300: '#7fa9e6',
                    400: '#3f77d1',
                    500: '#0d57bd',
                    600: '#0049ad', // Brand Blue (logo background)
                    700: '#003c8f',
                    800: '#053071',
                    900: '#102a5e', // Brand Navy (dark logo variant)
                    yellow: '#ffdf58',
                    cream: '#f8f8f0',
                },
            },
            backgroundImage: {
                // Woven market-textile stripe in brand colors — the hero's
                // signature divider, not decoration for its own sake.
                weave: 'repeating-linear-gradient(135deg, #0049ad 0 14px, #003c8f 14px 28px, #ffdf58 28px 34px, #102a5e 34px 48px)',
            },
            animation: {
                marquee: 'marquee 25s linear infinite',
                countUp: 'countUp 0.4s ease-out',
                popIn: 'popIn 0.35s cubic-bezier(0.16, 1, 0.3, 1)',
                fadeIn: 'fadeIn 0.2s ease-out',
                slideUp: 'slideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1)',
                scaleIn: 'scaleIn 0.2s cubic-bezier(0.16, 1, 0.3, 1)',
                shimmer: 'shimmer 1.6s ease-in-out infinite',
                slideInRight: 'slideInRight 0.3s cubic-bezier(0.16, 1, 0.3, 1)',
                toastIn: 'toastIn 0.28s cubic-bezier(0.16, 1, 0.3, 1)',
            },
            keyframes: {
                marquee: {
                    '0%': { transform: 'translateX(0)' },
                    '100%': { transform: 'translateX(-50%)' },
                },
                // Top-centre toasts drop in from above the viewport edge.
                toastIn: {
                    '0%': { transform: 'translateY(-14px) scale(0.96)', opacity: '0' },
                    '100%': { transform: 'translateY(0) scale(1)', opacity: '1' },
                },
                countUp: {
                    '0%': { transform: 'translateY(8px)', opacity: '0' },
                    '100%': { transform: 'translateY(0)', opacity: '1' },
                },
                popIn: {
                    '0%': { transform: 'scale(0.92) translateY(12px)', opacity: '0' },
                    '100%': { transform: 'scale(1) translateY(0)', opacity: '1' },
                },
                fadeIn: {
                    '0%': { opacity: '0' },
                    '100%': { opacity: '1' },
                },
                slideUp: {
                    '0%': { transform: 'translateY(16px)', opacity: '0' },
                    '100%': { transform: 'translateY(0)', opacity: '1' },
                },
                scaleIn: {
                    '0%': { transform: 'scale(0.96)', opacity: '0' },
                    '100%': { transform: 'scale(1)', opacity: '1' },
                },
                shimmer: {
                    '0%': { backgroundPosition: '-200% 0' },
                    '100%': { backgroundPosition: '200% 0' },
                },
                slideInRight: {
                    '0%': { transform: 'translateX(-100%)' },
                    '100%': { transform: 'translateX(0)' },
                },
            },
        },
    },
    plugins: [],
};
