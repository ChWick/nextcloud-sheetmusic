import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'path'
import { cpSync, existsSync, readdirSync, rmSync } from 'fs'

function cleanMatchingFiles(dir: string, pattern: RegExp): void {
	if (!existsSync(dir)) return
	for (const entry of readdirSync(dir)) {
		if (pattern.test(entry)) {
			rmSync(join(dir, entry), { force: true })
		}
	}
}

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
					name: 'clean-old-chunks',
					buildStart() {
						cleanMatchingFiles('js', /\.chunk\.mjs$/)
						cleanMatchingFiles('css', /\.chunk\.css$/)
						cleanMatchingFiles('dist', /^pdf\.worker\.min-.*\.mjs$/)
					},
				},
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

