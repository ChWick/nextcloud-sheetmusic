import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	initPwaManifestLink,
	initPwaStandaloneMode,
	isStandaloneDisplayMode,
	PWA_STANDALONE_CLASS,
	registerPwaServiceWorker,
} from '../../../src/utils/pwa'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path: string) => `/index.php${path}`,
}))

describe('pwa utils', () => {
	const originalMatchMedia = window.matchMedia
	const originalStandalone = (window.navigator as Navigator & { standalone?: boolean }).standalone

	beforeEach(() => {
		document.documentElement.classList.remove(PWA_STANDALONE_CLASS)
		document.body.classList.remove(PWA_STANDALONE_CLASS)
	})

	afterEach(() => {
		window.matchMedia = originalMatchMedia
		Object.defineProperty(window.navigator, 'standalone', {
			configurable: true,
			value: originalStandalone,
		})
		document.documentElement.classList.remove(PWA_STANDALONE_CLASS)
		document.body.classList.remove(PWA_STANDALONE_CLASS)
	})

	it('returns false in regular browser mode', () => {
		window.matchMedia = vi.fn().mockReturnValue({
			matches: false,
			addEventListener: vi.fn(),
			removeEventListener: vi.fn(),
		})
		Object.defineProperty(window.navigator, 'standalone', {
			configurable: true,
			value: false,
		})

		expect(isStandaloneDisplayMode()).toBe(false)
	})

	it('returns true when display-mode matches standalone', () => {
		window.matchMedia = vi.fn().mockReturnValue({
			matches: true,
			addEventListener: vi.fn(),
			removeEventListener: vi.fn(),
		})

		expect(isStandaloneDisplayMode()).toBe(true)
	})

	it('returns true when navigator.standalone is true', () => {
		window.matchMedia = vi.fn().mockReturnValue({
			matches: false,
			addEventListener: vi.fn(),
			removeEventListener: vi.fn(),
		})
		Object.defineProperty(window.navigator, 'standalone', {
			configurable: true,
			value: true,
		})

		expect(isStandaloneDisplayMode()).toBe(true)
	})

	it('toggles standalone class on init and display-mode changes, and cleans up', () => {
		let matches = true
		let listener: (() => void) | undefined
		const removeEventListener = vi.fn()

		window.matchMedia = vi.fn().mockImplementation(() => ({
			get matches() {
				return matches
			},
			addEventListener: (_event: string, cb: () => void) => {
				listener = cb
			},
			removeEventListener,
		}))

		const cleanup = initPwaStandaloneMode()
		expect(document.documentElement.classList.contains(PWA_STANDALONE_CLASS)).toBe(true)
		expect(document.body.classList.contains(PWA_STANDALONE_CLASS)).toBe(true)

		matches = false
		listener?.()
		expect(document.documentElement.classList.contains(PWA_STANDALONE_CLASS)).toBe(false)
		expect(document.body.classList.contains(PWA_STANDALONE_CLASS)).toBe(false)

		cleanup()
		expect(removeEventListener).toHaveBeenCalledTimes(1)
	})

	it('registers service worker with root scope', async () => {
		const fakeReg = { scope: '/' }
		const register = vi.fn().mockResolvedValue(fakeReg)
		Object.defineProperty(window.navigator, 'serviceWorker', {
			configurable: true,
			value: { register },
		})

		const result = await registerPwaServiceWorker()
		expect(register).toHaveBeenCalledWith('/index.php/apps/sheetmusic/sw.js', {
			scope: '/',
			updateViaCache: 'none',
		})
		expect(result).toBe(fakeReg)
	})

	it('updates existing manifest link to controller endpoint', () => {
		document.head.innerHTML = '<link rel="manifest" href="/custom_apps/sheetmusic/img/manifest.json">'
		initPwaManifestLink()
		const link = document.head.querySelector<HTMLLinkElement>('link[rel="manifest"]')
		expect(link?.getAttribute('href')).toBe('/index.php/apps/sheetmusic/pwa-manifest')
	})
})
