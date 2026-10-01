import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            // CSS is the hand-maintained public/assets/css/notery.css loaded by
            // the layout; the previous empty resources/css/app.css entry only
            // produced a useless manifest row.
            input: ['resources/js/app.js'],
            refresh: true,
        }),
    ],
});
