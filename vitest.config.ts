import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
	plugins: [vue()],
	test: {
		environment: 'happy-dom',
		globals: true,
		setupFiles: ['./tests/frontend/setup.ts'],
		exclude: ['tests/e2e/**', 'node_modules/**'],
		server: {
			deps: {
				inline: [/@nextcloud\/vue/, /@nextcloud\/dialogs/],
			},
		},
	},
})

