import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { initPwaStandaloneMode, isStandaloneDisplayMode, PWA_STANDALONE_CLASS } from '../../../src/utils/pwa'

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
})
