import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'

const resolveElementPlusChunk = (id) => {
  if (id.includes('@element-plus/icons-vue')) {
    return 'element-plus-icons'
  }

  const match = id.match(/element-plus\/es\/components\/([^/]+)/)
  const component = match?.[1]
  if (!component) {
    return 'element-plus-base'
  }

  if ([
    'form',
    'form-item',
    'input',
    'input-number',
    'input-tag',
    'autocomplete',
    'select',
    'option',
    'option-group',
    'checkbox',
    'checkbox-button',
    'checkbox-group',
    'radio',
    'radio-button',
    'radio-group',
    'date-picker',
    'date-picker-panel',
    'time-picker',
    'time-select',
    'switch',
  ].includes(component)) {
    return 'element-plus-form'
  }

  if ([
    'table',
    'table-v2',
    'pagination',
    'tag',
    'progress',
    'empty',
    'descriptions',
    'descriptions-item',
    'card',
    'divider',
    'image',
    'statistic',
  ].includes(component)) {
    return 'element-plus-data'
  }

  if ([
    'dialog',
    'drawer',
    'message',
    'message-box',
    'notification',
    'loading',
    'popover',
    'popconfirm',
    'tooltip',
    'overlay',
  ].includes(component)) {
    return 'element-plus-feedback'
  }

  if ([
    'menu',
    'menu-item',
    'menu-item-group',
    'sub-menu',
    'tabs',
    'tab-pane',
    'dropdown',
    'dropdown-item',
    'dropdown-menu',
    'breadcrumb',
    'breadcrumb-item',
    'steps',
    'step',
  ].includes(component)) {
    return 'element-plus-nav'
  }

  return 'element-plus-base'
}

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    AutoImport({
      resolvers: [
        ElementPlusResolver({
          importStyle: 'css',
          directives: true,
        }),
      ],
    }),
    Components({
      resolvers: [
        ElementPlusResolver({
          importStyle: 'css',
          directives: true,
        }),
      ],
    }),
  ],
  test: {
    environment: 'jsdom',
    server: {
      deps: {
        inline: ['element-plus'],
      },
    },
  },
  build: {
    // `exceljs` 已经按需拆成独立异步块，适度放宽告警阈值，避免误报掩盖真正的首屏大包问题。
    chunkSizeWarningLimit: 1000,
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (!id.includes('node_modules')) {
            return undefined
          }

          if (id.includes('xlsx')) {
            return 'xlsx'
          }

          if (id.includes('exceljs')) {
            return 'exceljs'
          }

          if (id.includes('papaparse')) {
            return 'papaparse'
          }

          if (id.includes('element-plus') || id.includes('@element-plus')) {
            return resolveElementPlusChunk(id)
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

          return undefined
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
