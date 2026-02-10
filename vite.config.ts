import { defineConfig } from "vite";
import tailwindcss from "@tailwindcss/vite";
import ViteRestart from "vite-plugin-restart";
import viteCompression from 'vite-plugin-compression';
import checker from "vite-plugin-checker";
import copy from 'rollup-plugin-copy';

export default defineConfig(({ command }) => ({
    base: command === 'serve' ? '/' : '/dist/',
    plugins: [
        tailwindcss(), // Tailwind v4 engine
        checker({
            stylelint: { lintCommand: 'stylelint "src/**/*.css"' },
            eslint: {
                lintCommand: 'eslint "src/**/*.js"',
                useFlatConfig: true
            },
            enableBuild: false,
        }),
        ViteRestart({ restart: ['./templates/**/*'] }),
        viteCompression({ filter: /\.(js|mjs|json|css|map)$/i }),
        copy({
            targets: [{ src: 'src/public/*', dest: 'web/dist' }],
            hook: command === 'build' ? 'writeBundle' : 'buildStart',
            copyOnce: true,
        }),
    ],
    build: {
        assetsInlineLimit: 0,
        manifest: true,
        outDir: 'web/dist',
        rollupOptions: {
            input: {
                app: './src/js/app.js',
            }
        },
        emptyOutDir: true
    },
    publicDir: 'src/public',
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        // Use the DDEV URL without the trailing slash
        origin: 'https://fox-plan.ddev.site:5173',
        cors: true,
        allowedHosts: ['.ddev.site'], // Explicitly allow DDEV domains
        hmr: {
            host: 'fox-plan.ddev.site',
            protocol: 'wss',
        },
    }
}));