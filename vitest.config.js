import { configDefaults, defineConfig } from 'vitest/config'
import path from 'path'

export default defineConfig({
  test: {
    environment: 'jsdom',
    globals: true,
    exclude: [...configDefaults.exclude, 'perf/**', '.perf/**'],
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './js'),
    },
  },
})