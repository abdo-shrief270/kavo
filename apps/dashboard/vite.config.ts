import { fileURLToPath, URL } from 'node:url'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    port: 5173,
    // Fixed port: it is registered in SANCTUM_STATEFUL_DOMAINS, and a session
    // cookie issued for one origin will not be sent from another.
    strictPort: true,
  },
})
