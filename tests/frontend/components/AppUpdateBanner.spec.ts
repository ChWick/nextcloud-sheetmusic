import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import AppUpdateBanner from '../../../src/components/AppUpdateBanner.vue'
import { resetServiceWorkerWatcherForTest } from '../../../src/utils/pwaVersion'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path: string) => `/index.php${path}`,
}))

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string) => text,
}))

vi.mock('@nextcloud/vue/components/NcProgressBar', () => ({
	default: {
		name: 'NcProgressBar',
		props: ['value', 'size', 'showValue'],
		template: '<progress :value="value" max="100" />',
	},
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		emits: ['click'],
		template: '<button type="button" @click="$emit(\'click\')"><slot /></button>',
	},
}))

describe('AppUpdateBanner.vue', () => {
	let messageCb: ((event: MessageEvent) => void) | undefined

	beforeEach(() => {
		document.body.innerHTML = '<div id="sheetmusic" data-asset-version="ver1"></div>'
		resetServiceWorkerWatcherForTest()
		messageCb = undefined
		Object.defineProperty(window.navigator, 'serviceWorker', {
			configurable: true,
			value: {
				controller: { scriptURL: '/apps/sheetmusic/sw.js' },
				addEventListener: (event: string, cb: (ev: MessageEvent) => void) => {
					if (event === 'message') {
						messageCb = cb
					}
				},
			},
		})
	})

	afterEach(() => {
		document.body.innerHTML = ''
		resetServiceWorkerWatcherForTest()
	})

	it('remains hidden when idle and displays download progress when SW broadcasts an update', async () => {
		const wrapper = mount(AppUpdateBanner)
		expect(wrapper.find('[data-test="app-update-banner"]').exists()).toBe(false)

		messageCb?.({
			data: {
				type: 'SW_UPDATE_PROGRESS',
				version: 'ver2',
				stage: 'downloading',
				percent: 62,
				completedFiles: 5,
				totalFiles: 8,
				loadedBytes: 2 * 1024 * 1024,
				totalBytes: 4 * 1024 * 1024,
			},
		} as MessageEvent)
		await nextTick()

		const banner = wrapper.find('[data-test="app-update-banner"]')
		expect(banner.exists()).toBe(true)
		expect(banner.text()).toContain('Downloading Sheet Music update…')
		expect(banner.text()).toContain('62%')
		expect(banner.text()).toContain('2.0 MB / 4.0 MB · 5/8')
		expect(banner.find('progress').attributes('value')).toBe('62')

		messageCb?.({
			data: {
				type: 'SW_UPDATE_PROGRESS',
				version: 'ver2',
				stage: 'waiting',
				percent: 100,
			},
		} as MessageEvent)
		await nextTick()

		expect(banner.text()).toContain('Sheet Music update ready')
		expect(banner.find('[data-test="update-reload-btn"]').exists()).toBe(true)
		expect(banner.find('[data-test="update-later-btn"]').exists()).toBe(true)

		// Dismissing hides the banner without reloading
		await banner.find('[data-test="update-later-btn"]').trigger('click')
		await nextTick()
		expect(wrapper.find('[data-test="app-update-banner"]').exists()).toBe(false)
	})
})
