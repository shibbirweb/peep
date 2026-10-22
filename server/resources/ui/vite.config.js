import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';

// The build goes to server/public, which the PHP viewer serves (and the PHAR and desktop app ship).
// `bun run dev` serves the UI with hot reload and forwards /_peep/* to a viewer on the default port.
export default defineConfig({
  plugins: [svelte()],
  build: {
    outDir: '../../public',
    emptyOutDir: true,
    target: 'es2022',
  },
  server: {
    proxy: {
      '/_peep': {
        target: 'http://127.0.0.1:23517',
        changeOrigin: true,
      },
    },
  },
});
