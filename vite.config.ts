import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'path'
import { cpSync } from 'fs'

export default createAppConfig(
	{
		main: resolve(join('src', 'main.js')),
		pwa: resolve(join('src', 'pwa-init.ts')),
	},
	{
		minify: true,
		createEmptyCSSEntryPoints: true,
		extractLicenseInformation: false,
		thirdPartyLicense: false,
		config: {
			build: {
				reportCompressedSize: false,
				sourcemap: false,
				rollupOptions: {
					output: {
						manualChunks(id) {
							if (id.includes('node_modules/pdfjs-dist')) {
								return 'pdfjs'
							}
							if (id.includes('node_modules/pdf-lib') || id.includes('node_modules/@pdf-lib')) {
								return 'pdflib'
							}
							if (id.includes('node_modules/date-fns')) {
								return 'date-fns'
							}
						},
					},
				},
			},
			plugins: [
				{
					name: 'copy-pdfjs-assets',
					closeBundle() {
						cpSync('node_modules/pdfjs-dist/wasm', 'js/pdfjs/wasm', { recursive: true })
						cpSync('node_modules/pdfjs-dist/cmaps', 'js/pdfjs/cmaps', { recursive: true })
						cpSync('node_modules/pdfjs-dist/standard_fonts', 'js/pdfjs/standard_fonts', { recursive: true })
					},
				},
			],
		},
	},
)

