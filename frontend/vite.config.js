import { defineConfig, loadEnv } from 'vite';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const frontendRoot = path.dirname(fileURLToPath(import.meta.url));
  const modules = path.join(frontendRoot, 'node_modules');

  return {
    plugins: [react(), tailwindcss()],
    resolve: {
      alias: [
        { find: /^react$/, replacement: path.join(modules, 'react') },
        { find: /^react-dom$/, replacement: path.join(modules, 'react-dom') },
        { find: /^react-router-dom$/, replacement: path.join(modules, 'react-router-dom') },
      ],
    },
    build: {
      outDir: '../backend/public/build',
      emptyOutDir: true,
      manifest: 'manifest.json',
    },
    server: {
      fs: {
        allow: [path.resolve(frontendRoot, '..')],
      },
      proxy: {
        '/api': {
          target: env.VITE_BACKEND_URL || 'http://127.0.0.1:8000',
          changeOrigin: true,
        },
      },
    },
  };
});
