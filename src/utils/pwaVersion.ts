import { generateUrl } from '@nextcloud/router'

export const APP_SHELL_CACHE_PREFIX = 'sheetmusic-app-shell-'
export const APP_VERSION_STORAGE_KEY = 'sheetmusic_app_version'
export const APP_RELOAD_GUARD_KEY = 'sheetmusic_reloaded_version'
export const SW_UPDATE_THROTTLE_MS = 60 * 1000

export interface AppVersionMetadata {
	appVersion: string
	assetVersion: string
	releaseDate: string | null
	buildDate: string | null
}

export type AppUpdateStage = 'idle' | 'checking' | 'downloading' | 'installing' | 'waiting' | 'reloading'

export interface AppUpdateProgress {
	active: boolean
	dismissed: boolean
	stage: AppUpdateStage
	version: string | null
	percent: number
	completedFiles: number
	totalFiles: number
	loadedBytes: number
	totalBytes: number
}

export type UpdateCheckResult = 'up-to-date' | 'updating' | 'ready' | 'offline' | 'error'

type ProgressListener = (progress: AppUpdateProgress) => void

const progressListeners = new Set<ProgressListener>()

const INITIAL_PROGRESS: AppUpdateProgress = {
	active: false,
	dismissed: false,
	stage: 'idle',
	version: null,
	percent: 0,
	completedFiles: 0,
	totalFiles: 0,
	loadedBytes: 0,
	totalBytes: 0,
}

let currentProgress: AppUpdateProgress = { ...INITIAL_PROGRESS }

let activeRegistration: ServiceWorkerRegistration | null = null
let swMessageListenerAttached = false
let preloadErrorListenerAttached = false

function setUpdateProgress(next: Partial<AppUpdateProgress>): void {
	currentProgress = { ...currentProgress, ...next }
	for (const listener of progressListeners) {
		listener(currentProgress)
	}
}

function markUpdateReady(version?: string | null): void {
	setUpdateProgress({
		active: true,
		dismissed: false,
		stage: 'waiting',
		version: version ?? currentProgress.version,
		percent: 100,
	})
}

function markReloading(version?: string | null): void {
	setUpdateProgress({
		active: true,
		dismissed: false,
		stage: 'reloading',
		version: version ?? currentProgress.version,
		percent: 100,
	})
}

function getServiceWorkerContainer(): ServiceWorkerContainer | null {
	if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
		return null
	}
	return navigator.serviceWorker
}

function persistAppVersion(version: string | null): void {
	if (!version || typeof localStorage === 'undefined') {
		return
	}
	try {
		localStorage.setItem(APP_VERSION_STORAGE_KEY, version)
	} catch {
		// Ignore storage access errors
	}
}

async function resolveServiceWorkerRegistration(): Promise<ServiceWorkerRegistration | null> {
	if (activeRegistration) {
		return activeRegistration
	}
	const sw = getServiceWorkerContainer()
	if (sw && typeof sw.getRegistration === 'function') {
		return (await sw.getRegistration('/')) ?? null
	}
	return null
}

export function getAppUpdateProgress(): AppUpdateProgress {
	return { ...currentProgress }
}

export function subscribeAppUpdateProgress(listener: ProgressListener): () => void {
	progressListeners.add(listener)
	listener(getAppUpdateProgress())
	return () => {
		progressListeners.delete(listener)
	}
}

export function setActiveServiceWorkerRegistration(reg: ServiceWorkerRegistration | null): void {
	activeRegistration = reg
}

/**
 * Reads the server-injected asset version hash from the mount element.
 */
export function getAppAssetVersion(): string {
	return getAppVersionMetadata().assetVersion
}

/**
 * Reads app version, release date, build date, and asset hash from the mount element.
 */
export function getAppVersionMetadata(): AppVersionMetadata {
	if (typeof document === 'undefined') {
		return {
			appVersion: 'dev',
			assetVersion: 'v1',
			releaseDate: null,
			buildDate: null,
		}
	}
	const el = document.getElementById('sheetmusic')
	const appVersion = el?.dataset.appVersion?.trim() || 'dev'
	const assetVersion = el?.dataset.assetVersion?.trim() || 'v1'
	const releaseDate = el?.dataset.releaseDate?.trim() || null
	const buildDate = el?.dataset.buildDate?.trim() || null
	return {
		appVersion,
		assetVersion,
		releaseDate,
		buildDate,
	}
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

	markReloading()

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
	const sw = getServiceWorkerContainer()
	if (swControllerListenerAttached || !sw || typeof sw.addEventListener !== 'function') {
		return
	}

	let hadController = Boolean(sw.controller)
	swControllerListenerAttached = true
	sw.addEventListener('controllerchange', () => {
		if (!hadController) {
			hadController = true
			return
		}
		const targetVersion = currentProgress.version || currentVersion
		persistAppVersion(currentProgress.version)
		triggerOneShotReload(`sw:${targetVersion}`, reloadFn)
	})
}

/**
 * Subscribes to SW_UPDATE_PROGRESS messages sent by an installing/activating Service Worker.
 */
export function watchServiceWorkerProgress(): void {
	const sw = getServiceWorkerContainer()
	if (swMessageListenerAttached || !sw || typeof sw.addEventListener !== 'function') {
		return
	}

	swMessageListenerAttached = true
	sw.addEventListener('message', (event: MessageEvent) => {
		const data = event.data
		if (!data || typeof data !== 'object' || data.type !== 'SW_UPDATE_PROGRESS') {
			return
		}
		// Only show update progress when installing a different version than the running page
		// (ignores initial first-time SW install/claim for the same version).
		const currentVersion = getAppAssetVersion()
		const incomingVersion = typeof data.version === 'string' ? data.version : null
		if (!incomingVersion || incomingVersion === currentVersion) {
			return
		}

		if (data.stage === 'activated') {
			markReloading(incomingVersion)
			return
		}

		const stage: AppUpdateStage = data.stage === 'waiting'
			? 'waiting'
			: data.stage === 'installing'
				? 'installing'
				: 'downloading'
		setUpdateProgress({
			active: true,
			dismissed: stage === 'waiting' ? false : currentProgress.dismissed,
			stage,
			version: incomingVersion,
			percent: typeof data.percent === 'number' ? Math.max(0, Math.min(100, Math.round(data.percent))) : currentProgress.percent,
			completedFiles: typeof data.completedFiles === 'number' ? data.completedFiles : currentProgress.completedFiles,
			totalFiles: typeof data.totalFiles === 'number' ? data.totalFiles : currentProgress.totalFiles,
			loadedBytes: typeof data.loadedBytes === 'number' ? data.loadedBytes : currentProgress.loadedBytes,
			totalBytes: typeof data.totalBytes === 'number' ? data.totalBytes : currentProgress.totalBytes,
		})
	})
}

/**
 * Watches a ServiceWorkerRegistration's `updatefound` and `waiting` lifecycle so
 * the UI shows download progress and a reload prompt when an update is ready.
 */
export function watchRegistrationForUpdates(reg: ServiceWorkerRegistration): void {
	if (reg.waiting && navigator.serviceWorker?.controller) {
		markUpdateReady()
	}

	if (typeof reg.addEventListener !== 'function') {
		return
	}

	const trackInstallingWorker = (worker: ServiceWorker | null) => {
		if (!worker || !navigator.serviceWorker?.controller) {
			return
		}
		setUpdateProgress({
			active: true,
			dismissed: false,
			stage: 'downloading',
			percent: Math.max(currentProgress.percent, 0),
		})
		if (typeof worker.addEventListener === 'function') {
			worker.addEventListener('statechange', () => {
				if (worker.state === 'installed' && navigator.serviceWorker?.controller) {
					markUpdateReady()
				} else if (worker.state === 'redundant' && currentProgress.stage !== 'reloading') {
					setUpdateProgress({ ...INITIAL_PROGRESS })
				}
			})
		}
	}

	if (reg.installing) {
		trackInstallingWorker(reg.installing)
	}
	reg.addEventListener('updatefound', () => {
		trackInstallingWorker(reg.installing)
	})
}

/**
 * Activates a waiting Service Worker (or triggers a guarded reload when no SW
 * is waiting) so the user controls when the page reloads.
 */
export async function applyPendingAppUpdate(reloadFn?: () => void): Promise<boolean> {
	const reg = await resolveServiceWorkerRegistration()

	if (reg?.waiting) {
		markReloading()
		persistAppVersion(currentProgress.version)
		reg.waiting.postMessage({ type: 'SKIP_WAITING' })
		// Fallback reload in case controllerchange does not fire promptly
		if (typeof window !== 'undefined' && typeof window.setTimeout === 'function') {
			window.setTimeout(() => {
				triggerOneShotReload(`sw:${currentProgress.version || getAppAssetVersion()}`, reloadFn)
			}, 2500)
		}
		return true
	}

	const targetVersion = currentProgress.version || getAppAssetVersion()
	await purgeStaleAppShellCaches(targetVersion)
	return triggerOneShotReload(targetVersion, reloadFn)
}

/**
 * Hides the floating update banner until the next update or manual check in Settings.
 */
export function dismissAppUpdateBanner(): void {
	if (currentProgress.stage === 'waiting') {
		setUpdateProgress({ dismissed: true })
	}
}

/**
 * Recovers gracefully when a dynamic Vite chunk fails to load mid-session after
 * a server deployment removes older hashed chunks.
 */
export function attachPreloadErrorRecovery(reloadFn?: () => void): () => void {
	if (preloadErrorListenerAttached || typeof window === 'undefined') {
		return () => {}
	}
	preloadErrorListenerAttached = true
	const onPreloadError = (event: Event) => {
		event.preventDefault()
		void (async () => {
			const serverMeta = await fetchServerAppVersion().catch(() => null)
			const targetVersion = serverMeta?.assetVersion || `preload:${getAppAssetVersion()}`
			await purgeStaleAppShellCaches(targetVersion)
			triggerOneShotReload(targetVersion, reloadFn)
		})()
	}
	window.addEventListener('vite:preloadError', onPreloadError)
	return () => {
		preloadErrorListenerAttached = false
		window.removeEventListener('vite:preloadError', onPreloadError)
	}
}

/**
 * Fetches the current version metadata from the server (bypassing all caches).
 */
export async function fetchServerAppVersion(): Promise<AppVersionMetadata | null> {
	if (typeof fetch !== 'function') {
		return null
	}
	const url = generateUrl('/apps/sheetmusic/app-version')
	const res = await fetch(url, {
		credentials: 'include',
		cache: 'no-store',
		headers: {
			Accept: 'application/json',
		},
	})
	if (!res.ok) {
		return null
	}
	const data = await res.json()
	if (!data || typeof data !== 'object' || typeof data.assetVersion !== 'string') {
		return null
	}
	return {
		appVersion: typeof data.appVersion === 'string' ? data.appVersion : 'dev',
		assetVersion: data.assetVersion,
		releaseDate: typeof data.releaseDate === 'string' && data.releaseDate ? data.releaseDate : null,
		buildDate: typeof data.buildDate === 'string' && data.buildDate ? data.buildDate : null,
	}
}

/**
 * Actively checks for a newer frontend version via both Service Worker update
 * and the lightweight `/app-version` endpoint.
 */
export async function checkForAppUpdate(): Promise<UpdateCheckResult> {
	if (typeof navigator !== 'undefined' && navigator.onLine === false) {
		return 'offline'
	}

	try {
		const reg = await resolveServiceWorkerRegistration()

		const [serverMeta] = await Promise.all([
			fetchServerAppVersion().catch(() => null),
			reg && typeof reg.update === 'function' ? reg.update().catch(() => {}) : Promise.resolve(),
		])

		const currentAssetVersion = getAppAssetVersion()
		const hasNewVersion = Boolean(serverMeta && serverMeta.assetVersion && serverMeta.assetVersion !== currentAssetVersion)

		if (reg?.waiting || currentProgress.stage === 'waiting') {
			markUpdateReady(serverMeta?.assetVersion)
			return 'ready'
		}

		if (reg?.installing || currentProgress.active) {
			if (!currentProgress.active) {
				setUpdateProgress({
					active: true,
					dismissed: false,
					stage: 'downloading',
					version: serverMeta?.assetVersion ?? null,
					percent: 0,
				})
			}
			return 'updating'
		}

		if (hasNewVersion && serverMeta) {
			// Server has a newer version without an active SW update (e.g. SW disabled/blocked).
			// Surface the "Update ready — Reload" prompt rather than interrupting the user.
			markUpdateReady(serverMeta.assetVersion)
			return 'ready'
		}

		return 'up-to-date'
	} catch {
		return 'error'
	}
}

export function resetServiceWorkerWatcherForTest(): void {
	swControllerListenerAttached = false
	swMessageListenerAttached = false
	preloadErrorListenerAttached = false
	activeRegistration = null
	progressListeners.clear()
	currentProgress = { ...INITIAL_PROGRESS }
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
