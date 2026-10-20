import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';

export default defineConfig({
    base: './',
    plugins: [vue()],
    publicDir: false,
    build: {
        manifest: true,
        emptyOutDir: true,
        outDir: 'public/build',
        rollupOptions: {
            input: 'frontend/src/main.ts',
        },
    },
});
