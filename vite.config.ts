import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'path'
import { cpSync } from 'fs'

export default createAppConfig(
	{
		main: resolve(join('src', 'main.js')),
	},
	{
		createEmptyCSSEntryPoints: true,
		extractLicenseInformation: true,
		thirdPartyLicense: false,
		config: {
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

