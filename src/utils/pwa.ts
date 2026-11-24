import { generateUrl } from '@nextcloud/router'
import {
	attachServiceWorkerUpdateChecks,
	getAppShellCacheName,
	setActiveServiceWorkerRegistration,
	syncAppVersionAndReloadIfNeeded,
	watchRegistrationForUpdates,
	watchServiceWorkerProgress,
	watchServiceWorkerUpdate,
} from './pwaVersion'

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

function isImmutableHashedAssetUrl(targetUrl: string): boolean {
	try {
		const { pathname } = new URL(targetUrl, window.location.href)
		return pathname.includes('.chunk.mjs')
			|| pathname.includes('.chunk.css')
			|| /\/dist\/pdf\.worker\.min-[^/]+\.mjs$/.test(pathname)
			|| pathname.includes('/css/fonts/')
	} catch {
		return false
	}
}

/**
 * Best-effort fetches and caches a same-origin URL into the app-shell Cache Storage.
 */
export async function cacheIntoAppShell(targetUrl: string, forceRefresh = false): Promise<void> {
	if (typeof window === 'undefined' || typeof caches === 'undefined') {
		return
	}
	try {
		const cache = await caches.open(getAppShellCacheName())
		if (!forceRefresh && await cache.match(targetUrl, { ignoreVary: true })) {
			return
		}
		const isImmutable = isImmutableHashedAssetUrl(targetUrl)
		const res = await fetch(targetUrl, {
			credentials: 'include',
			cache: (forceRefresh || !isImmutable) ? 'reload' : 'default',
		})
		if (res.ok && !res.url.includes('/login')) {
			const headers = new Headers(res.headers)
			if (!isImmutable) {
				headers.set('cache-control', 'no-cache')
			}
			const clean = (!isImmutable || res.redirected)
				? new Response(res.body, {
					status: res.status,
					statusText: res.statusText,
					headers,
				})
				: res
			await cache.put(targetUrl, clean)
		}
	} catch {
		// Best-effort app shell caching
	}
}

/**
 * Ensures the app shell entry URL and current page scripts/stylesheets are
 * stored in Cache Storage so opening the installed PWA offline succeeds even
 * without a prior reload after service worker activation.
 */
export async function precacheAppShell(): Promise<void> {
	if (typeof window === 'undefined' || typeof caches === 'undefined') {
		return
	}
	if (typeof navigator !== 'undefined' && navigator.onLine === false) {
		return
	}

	const shellPath = generateUrl('/apps/sheetmusic/')
	const shellUrl = new URL(shellPath, window.location.origin).toString()

	const urls = new Set<string>([shellUrl])
	if (window.location.pathname.includes('/apps/sheetmusic')) {
		urls.add(window.location.href)
	}

	if (typeof document !== 'undefined') {
		const nodes = document.querySelectorAll<HTMLElement>('script[src], link[rel="stylesheet"][href], link[rel="modulepreload"][href]')
		for (const node of nodes) {
			const raw = node.getAttribute('src') || node.getAttribute('href')
			if (!raw) continue
			try {
				const resolved = new URL(raw, window.location.href)
				if (resolved.origin === window.location.origin) {
					urls.add(resolved.toString())
				}
			} catch {
				// Ignore malformed URLs
			}
		}
	}

	// APP_SHELL_CACHE is already keyed by assetVersion; only fetch entries missing from
	// the current version's cache so routine page loads do not re-download multi-MB bundles.
	await Promise.allSettled(
		Array.from(urls).map(targetUrl => cacheIntoAppShell(targetUrl, false)),
	)
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
		watchServiceWorkerUpdate()
		watchServiceWorkerProgress()
		const swUrl = generateUrl('/apps/sheetmusic/sw.js')
		const reg = await navigator.serviceWorker.register(swUrl, {
			scope: '/',
			updateViaCache: 'none',
		})
		setActiveServiceWorkerRegistration(reg)
		watchRegistrationForUpdates(reg)
		if (typeof reg.update === 'function') {
			void reg.update().catch(() => {})
			attachServiceWorkerUpdateChecks(reg)
		}
		const reloaded = await syncAppVersionAndReloadIfNeeded()
		if (!reloaded) {
			void precacheAppShell()
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
