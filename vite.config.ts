import { defineConfig } from 'vite';
import { resolve } from 'path';

// Compila dos bundles independientes (visor y admin) hacia public/assets/
// con nombres fijos (sin hash) para poder referenciarlos desde PHP.
export default defineConfig({
  // Base relativa: las URLs de assets (worker de PDF.js, chunks) se resuelven
  // respecto al propio bundle en tiempo de ejecución, así la app funciona
  // igual en la raíz del dominio o en un subdirectorio (p. ej. /book/).
  base: './',
  publicDir: false, // public/ es la raíz del sitio PHP, no estáticos de Vite
  build: {
    outDir: 'public/assets',
    emptyOutDir: true,
    sourcemap: true,
    rollupOptions: {
      input: {
        viewer: resolve(__dirname, 'src/viewer/main.ts'),
        admin: resolve(__dirname, 'src/admin/main.ts'),
      },
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: 'chunks/[name]-[hash].js',
        // El worker de PDF.js se emite como .js: muchos servidores (Apache sin
        // configurar, php -S antiguos) no envían MIME de JavaScript para .mjs
        // y el navegador rechaza el módulo.
        assetFileNames: (info) =>
          info.name?.endsWith('.mjs') ? '[name].js' : '[name][extname]',
      },
    },
  },
});
