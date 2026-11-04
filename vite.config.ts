import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';

export default defineConfig({
    base: './',
    plugins: [vue()],
    publicDir: false,
    css: {
        postcss: './postcss.config.cjs',
    },
    server: {
        host: '127.0.0.1',
    },
    build: {
        manifest: true,
        emptyOutDir: true,
        outDir: 'public/build',
        rollupOptions: {
            input: 'frontend/src/main.ts',
        },
    },
});
