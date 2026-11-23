import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [
    vue(),
    tailwindcss()
  ],
  server: {
    open: true,
    watch: {
      ignored: [
        '**/logs/**',
        '**/presets_data/**',
        '**/api/logs/**'
      ]
    },
    proxy: {
      '/api': {
        target: 'https://shop.full-example.local/',
        changeOrigin: true,
        secure: false
      }
    }
  }
})