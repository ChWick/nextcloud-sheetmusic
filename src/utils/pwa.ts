import { generateUrl } from '@nextcloud/router'

export const STANDALONE_MEDIA_QUERY =
	'(display-mode: standalone), (display-mode: fullscreen), (display-mode: minimal-ui), (display-mode: window-controls-overlay)'

export const PWA_STANDALONE_CLASS = 'sheetmusic-pwa-standalone'

type StandaloneNavigator = Navigator & {
	standalone?: boolean
}

/**
 * Returns true when the app is running as an installed web app (PWA)
 * rather than inside a standard browser tab.
 */
export function isStandaloneDisplayMode(): boolean {
	if (typeof window === 'undefined') {
		return false
	}

	const nav = window.navigator as StandaloneNavigator
	if (nav.standalone === true) {
		return true
	}

	if (typeof window.matchMedia === 'function') {
		return window.matchMedia(STANDALONE_MEDIA_QUERY).matches
	}

	return false
}

/**
 * Applies or removes the standalone PWA body/html class and listens for
 * display-mode changes. Returns a cleanup function.
 */
export function initPwaStandaloneMode(): () => void {
	if (typeof window === 'undefined' || typeof document === 'undefined') {
		return () => {}
	}

	const updateClass = () => {
		const active = isStandaloneDisplayMode()
		document.documentElement?.classList.toggle(PWA_STANDALONE_CLASS, active)
		document.body?.classList.toggle(PWA_STANDALONE_CLASS, active)
	}

	updateClass()

	if (typeof window.matchMedia !== 'function') {
		return () => {}
	}

	const mql = window.matchMedia(STANDALONE_MEDIA_QUERY)
	const onChange = () => updateClass()
	if (typeof mql.addEventListener === 'function') {
		mql.addEventListener('change', onChange)
		return () => mql.removeEventListener('change', onChange)
	}

	return () => {}
}

/**
 * Registers the Sheet Music PWA service worker so Web Share Target POST
 * requests can be intercepted and handed off to the app.
 */
export async function registerPwaServiceWorker(): Promise<ServiceWorkerRegistration | null> {
	if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
		return null
	}

	try {
		const swUrl = generateUrl('/apps/sheetmusic/sw.js')
		const reg = await navigator.serviceWorker.register(swUrl, { scope: '/' })
		if (typeof reg.update === 'function') {
			void reg.update().catch(() => {})
		}
		return reg
	} catch (error) {
		console.warn('Sheet Music: failed to register PWA service worker', error)
		return null
	}
}

/**
 * Points the document's manifest link to Sheet Music's controller-served
 * manifest endpoint so it works even when static .json files in custom_apps/
 * are blocked by web-server rules.
 */
export function initPwaManifestLink(): void {
	if (typeof document === 'undefined' || !document.head) {
		return
	}

	const manifestUrl = generateUrl('/apps/sheetmusic/pwa-manifest')
	let link = document.querySelector<HTMLLinkElement>('link[rel="manifest"]')
	if (!link) {
		link = document.createElement('link')
		link.rel = 'manifest'
		link.crossOrigin = 'use-credentials'
		document.head.appendChild(link)
	}
	link.href = manifestUrl
}
