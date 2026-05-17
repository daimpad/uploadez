import { defineConfig } from 'vite'

export default defineConfig({
  server: {
    port: 5173,
    open: true,
    hmr: true
  },
  build: {
    outDir: 'dist',
    sourcemap: true,
    minify: 'terser'
  },
  publicDir: 'public'
})
