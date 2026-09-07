import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            /*
             * Downloaded at build time and served from /build - nothing is
             * fetched from a font CDN at runtime, which is what keeps the
             * claim on /biskvitki ("we embed no external fonts") true.
             *
             * SUBSETS ARE THE WHOLE POINT HERE. The default is ['latin'], and
             * the previous font (Instrument Sans) had no Cyrillic subset at
             * all - so on a site written entirely in Bulgarian the webfont
             * matched nothing and every character silently fell back to the
             * system UI font. Both families below carry cyrillic, and both
             * must ask for it explicitly.
             */
            fonts: [
                bunny('Manrope', {
                    weights: [400, 500, 600, 700, 800],
                    subsets: ['latin', 'latin-ext', 'cyrillic'],
                }),
                bunny('JetBrains Mono', {
                    weights: [400, 500, 700],
                    subsets: ['latin', 'latin-ext', 'cyrillic'],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
