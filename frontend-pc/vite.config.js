import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  build: {
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (!id.includes('node_modules')) {
            return undefined
          }

          if (id.includes('xlsx')) {
            return 'xlsx'
          }

          if (id.includes('element-plus') || id.includes('@element-plus')) {
            return 'element-plus'
          }

          if (id.includes('vue-router')) {
            return 'vue-router'
          }

          if (id.includes('/vue/') || id.includes('@vue')) {
            return 'vue-core'
          }

          if (id.includes('axios')) {
            return 'http'
          }

          return 'vendor'
        },
      },
    },
  },
  server: {
    proxy: {
      '/api': {
        target: process.env.VITE_API_ORIGIN || 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
})
