import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	APP_RELOAD_GUARD_KEY,
	APP_VERSION_STORAGE_KEY,
	attachServiceWorkerUpdateChecks,
	getAppAssetVersion,
	getAppShellCacheName,
	purgeStaleAppShellCaches,
	resetServiceWorkerWatcherForTest,
	syncAppVersionAndReloadIfNeeded,
	watchServiceWorkerUpdate,
} from '../../../src/utils/pwaVersion'

describe('pwaVersion utils', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
		localStorage.clear()
		sessionStorage.clear()
		resetServiceWorkerWatcherForTest()
	})

	afterEach(() => {
		document.body.innerHTML = ''
		localStorage.clear()
		sessionStorage.clear()
	})

	it('reads asset version from #sheetmusic data attribute or falls back to v1', () => {
		expect(getAppAssetVersion()).toBe('v1')
		expect(getAppShellCacheName()).toBe('sheetmusic-app-shell-v1')

		document.body.innerHTML = '<div id="sheetmusic" data-asset-version="abc123def456"></div>'
		expect(getAppAssetVersion()).toBe('abc123def456')
		expect(getAppShellCacheName()).toBe('sheetmusic-app-shell-abc123def456')
	})

	it('purges only stale app-shell caches and preserves offline setlist caches', async () => {
		const deleted: string[] = []
		Object.defineProperty(globalThis, 'caches', {
			configurable: true,
			value: {
				keys: vi.fn().mockResolvedValue([
					'sheetmusic-app-shell-v1',
					'sheetmusic-app-shell-oldhash',
					'sheetmusic-app-shell-newhash',
					'sheetmusic-offline-setlists-v1',
				]),
				delete: vi.fn(async (key: string) => {
					deleted.push(key)
					return true
				}),
			},
		})

		await purgeStaleAppShellCaches('newhash')

		expect(deleted).toEqual(['sheetmusic-app-shell-v1', 'sheetmusic-app-shell-oldhash'])
	})

	it('does not reload on first visit, reloads once on version change, and prevents reload loops', async () => {
		Object.defineProperty(globalThis, 'caches', {
			configurable: true,
			value: {
				keys: vi.fn().mockResolvedValue([]),
				delete: vi.fn().mockResolvedValue(true),
			},
		})
		const reload = vi.fn()

		// First visit stores version without reloading
		expect(await syncAppVersionAndReloadIfNeeded('ver1', reload)).toBe(false)
		expect(localStorage.getItem(APP_VERSION_STORAGE_KEY)).toBe('ver1')
		expect(reload).not.toHaveBeenCalled()

		// Same version does not reload
		expect(await syncAppVersionAndReloadIfNeeded('ver1', reload)).toBe(false)
		expect(reload).not.toHaveBeenCalled()

		// New version triggers a one-shot reload
		expect(await syncAppVersionAndReloadIfNeeded('ver2', reload)).toBe(true)
		expect(localStorage.getItem(APP_VERSION_STORAGE_KEY)).toBe('ver2')
		expect(sessionStorage.getItem(APP_RELOAD_GUARD_KEY)).toBe('ver2')
		expect(reload).toHaveBeenCalledTimes(1)

		// Even if localStorage reverted, reload guard prevents infinite loop for ver2
		localStorage.setItem(APP_VERSION_STORAGE_KEY, 'ver1')
		expect(await syncAppVersionAndReloadIfNeeded('ver2', reload)).toBe(false)
		expect(reload).toHaveBeenCalledTimes(1)
	})

	it('reloads once when a new service worker takes over an already-controlled page', () => {
		let controllerChangeCb: (() => void) | undefined
		Object.defineProperty(window.navigator, 'serviceWorker', {
			configurable: true,
			value: {
				controller: { scriptURL: '/apps/sheetmusic/sw.js' },
				addEventListener: (event: string, cb: () => void) => {
					if (event === 'controllerchange') {
						controllerChangeCb = cb
					}
				},
			},
		})

		const reload = vi.fn()
		watchServiceWorkerUpdate('ver2', reload)
		controllerChangeCb?.()
		controllerChangeCb?.()

		expect(reload).toHaveBeenCalledTimes(1)
	})

	it('triggers throttled SW update checks on visibilitychange and immediate checks on online', () => {
		vi.useFakeTimers()
		const update = vi.fn().mockResolvedValue(undefined)
		const cleanup = attachServiceWorkerUpdateChecks({ update }, 60_000)

		Object.defineProperty(document, 'visibilityState', {
			configurable: true,
			value: 'visible',
		})

		// Within throttle window: skipped
		document.dispatchEvent(new Event('visibilitychange'))
		expect(update).not.toHaveBeenCalled()

		// After throttle window: triggers update
		vi.advanceTimersByTime(60_000)
		document.dispatchEvent(new Event('visibilitychange'))
		expect(update).toHaveBeenCalledTimes(1)

		// Online event triggers immediately regardless of throttle
		window.dispatchEvent(new Event('online'))
		expect(update).toHaveBeenCalledTimes(2)

		// Cleanup removes listeners
		cleanup()
		vi.advanceTimersByTime(60_000)
		document.dispatchEvent(new Event('visibilitychange'))
		window.dispatchEvent(new Event('online'))
		expect(update).toHaveBeenCalledTimes(2)

		vi.useRealTimers()
	})
})
