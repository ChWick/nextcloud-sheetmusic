export const APP_SHELL_CACHE_PREFIX = 'sheetmusic-app-shell-'
export const APP_VERSION_STORAGE_KEY = 'sheetmusic_app_version'
export const APP_RELOAD_GUARD_KEY = 'sheetmusic_reloaded_version'
export const SW_UPDATE_THROTTLE_MS = 5 * 60 * 1000

/**
 * Reads the server-injected asset version hash from the mount element.
 */
export function getAppAssetVersion(): string {
	if (typeof document === 'undefined') {
		return 'v1'
	}
	const version = document.getElementById('sheetmusic')?.dataset.assetVersion?.trim()
	return version || 'v1'
}

export function getAppShellCacheName(version = getAppAssetVersion()): string {
	return `${APP_SHELL_CACHE_PREFIX}${version}`
}

/**
 * Deletes older app-shell caches while keeping user offline setlist caches intact.
 */
export async function purgeStaleAppShellCaches(currentVersion = getAppAssetVersion()): Promise<void> {
	if (typeof caches === 'undefined') {
		return
	}
	const currentCacheName = getAppShellCacheName(currentVersion)
	try {
		const keys = await caches.keys()
		await Promise.all(
			keys
				.filter((key) => key.startsWith(APP_SHELL_CACHE_PREFIX) && key !== currentCacheName)
				.map((key) => caches.delete(key)),
		)
	} catch {
		// Best-effort cache cleanup
	}
}

function triggerOneShotReload(version: string, reloadFn?: () => void): boolean {
	try {
		if (typeof sessionStorage !== 'undefined') {
			if (sessionStorage.getItem(APP_RELOAD_GUARD_KEY) === version) {
				return false
			}
			sessionStorage.setItem(APP_RELOAD_GUARD_KEY, version)
		}
	} catch {
		// Ignore storage access errors
	}

	if (reloadFn) {
		reloadFn()
		return true
	}
	if (typeof window !== 'undefined' && typeof window.location?.reload === 'function') {
		window.location.reload()
		return true
	}
	return false
}

/**
 * Detects when the server's asset version has changed since the last visit,
 * purges stale app-shell caches, and triggers a guarded one-shot reload so
 * the client does not stay on outdated cached JS/HTML.
 */
export async function syncAppVersionAndReloadIfNeeded(
	currentVersion = getAppAssetVersion(),
	reloadFn?: () => void,
): Promise<boolean> {
	await purgeStaleAppShellCaches(currentVersion)

	if (typeof localStorage === 'undefined') {
		return false
	}

	try {
		const previousVersion = localStorage.getItem(APP_VERSION_STORAGE_KEY)
		localStorage.setItem(APP_VERSION_STORAGE_KEY, currentVersion)
		if (previousVersion && previousVersion !== currentVersion) {
			return triggerOneShotReload(currentVersion, reloadFn)
		}
	} catch {
		// Ignore storage access errors
	}
	return false
}

let swControllerListenerAttached = false

/**
 * Listens for a newly activated Service Worker taking control over an
 * already-controlled page and triggers a guarded one-shot reload.
 */
export function watchServiceWorkerUpdate(
	currentVersion = getAppAssetVersion(),
	reloadFn?: () => void,
): void {
	if (swControllerListenerAttached || typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
		return
	}
	const sw = navigator.serviceWorker
	if (typeof sw.addEventListener !== 'function') {
		return
	}

	const hadController = Boolean(sw.controller)
	swControllerListenerAttached = true
	sw.addEventListener('controllerchange', () => {
		if (hadController) {
			triggerOneShotReload(`sw:${currentVersion}`, reloadFn)
		}
	})
}

export function resetServiceWorkerWatcherForTest(): void {
	swControllerListenerAttached = false
}

/**
 * Periodically re-checks for a newer Service Worker when a long-lived tab or
 * installed PWA returns to the foreground (throttled) or comes back online.
 */
export function attachServiceWorkerUpdateChecks(
	reg: Pick<ServiceWorkerRegistration, 'update'>,
	throttleMs = SW_UPDATE_THROTTLE_MS,
): () => void {
	if (typeof reg.update !== 'function' || typeof window === 'undefined' || typeof document === 'undefined') {
		return () => {}
	}

	let lastCheckedAt = Date.now()
	const checkForUpdate = (force = false) => {
		if (typeof navigator !== 'undefined' && navigator.onLine === false) {
			return
		}
		const now = Date.now()
		if (!force && now - lastCheckedAt < throttleMs) {
			return
		}
		lastCheckedAt = now
		void reg.update().catch(() => {})
	}

	const onVisibilityChange = () => {
		if (document.visibilityState === 'visible') {
			checkForUpdate(false)
		}
	}
	const onOnline = () => checkForUpdate(true)

	document.addEventListener('visibilitychange', onVisibilityChange)
	window.addEventListener('online', onOnline)

	return () => {
		document.removeEventListener('visibilitychange', onVisibilityChange)
		window.removeEventListener('online', onOnline)
	}
}
