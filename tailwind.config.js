/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{js,jsx,ts,tsx}',
    ],
    theme: {
        extend: {
            colors: {
                brand: {
                    50: '#f4f8f4',
                    100: '#e3ede3',
                    500: '#2f7a3d',
                    600: '#256330',
                    700: '#1d4d26',
                },
            },
        },
    },
    plugins: [],
};
