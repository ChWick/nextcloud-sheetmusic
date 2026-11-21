/* global self, indexedDB, caches, fetch, Response, URL, File */
const SHARE_DB_NAME = 'sheetmusic-pwa-share'
const SHARE_DB_VERSION = 1
const SHARE_STORE_NAME = 'pendingShares'
const SHARE_KEY = 'latest'

const APP_VERSION = '__APP_VERSION__'
const APP_SHELL_CACHE_PREFIX = 'sheetmusic-app-shell-'
const APP_SHELL_CACHE = APP_SHELL_CACHE_PREFIX + APP_VERSION
const OFFLINE_SETLIST_CACHE = 'sheetmusic-offline-setlists-v1'

function openShareDb() {
	return new Promise((resolve, reject) => {
		const request = indexedDB.open(SHARE_DB_NAME, SHARE_DB_VERSION)
		request.onupgradeneeded = () => {
			const db = request.result
			if (!db.objectStoreNames.contains(SHARE_STORE_NAME)) {
				db.createObjectStore(SHARE_STORE_NAME)
			}
		}
		request.onsuccess = () => resolve(request.result)
		request.onerror = () => reject(request.error)
	})
}

async function storePendingShare(payload) {
	const db = await openShareDb()
	return new Promise((resolve, reject) => {
		const tx = db.transaction(SHARE_STORE_NAME, 'readwrite')
		const store = tx.objectStore(SHARE_STORE_NAME)
		store.put(payload, SHARE_KEY)
		tx.oncomplete = () => {
			db.close()
			resolve()
		}
		tx.onerror = () => {
			db.close()
			reject(tx.error)
		}
	})
}

function isCacheableAppShellRequest(request, url) {
	if (url.origin !== self.location.origin) {
		return false
	}
	if (url.pathname.endsWith('/sw.js') || url.pathname.endsWith('/pwa-manifest') || url.pathname.includes('/share-target/')) {
		return false
	}
	if (request.mode === 'navigate' && url.pathname.includes('/apps/sheetmusic')) {
		return true
	}
	return (
		url.pathname.includes('/apps/sheetmusic/')
		|| url.pathname.includes('/apps-extra/sheetmusic/')
		|| url.pathname.includes('/custom_apps/sheetmusic/')
		|| url.pathname.includes('/css/sheetmusic/')
		|| url.pathname.startsWith('/ocs/v2.php/apps/sheetmusic/')
		|| url.pathname.includes('/apps/theming/')
		|| url.pathname.includes('/core/')
		|| url.pathname.includes('/dist/')
	)
}

function isVersionedBinaryUrl(url) {
	return (url.pathname.includes('/remote.php/dav/') || (url.pathname.includes('/files/') && url.pathname.endsWith('/content')))
		&& url.searchParams.has('v')
}

function shouldRevalidateEntryRequest(request, url) {
	if (request.mode === 'navigate') {
		return true
	}
	if (request.destination === 'style' && !url.pathname.includes('.chunk.css')) {
		return true
	}
	return (
		url.pathname.endsWith('/js/sheetmusic-main.mjs')
		|| url.pathname.endsWith('/js/sheetmusic-pwa.mjs')
		|| url.pathname.endsWith('/css/sheetmusic-main.css')
		|| url.pathname.endsWith('/css/sheetmusic-pwa.css')
	)
}

function toCleanNavigationResponse(response) {
	if (!response || !response.redirected) {
		return response
	}
	return new Response(response.body, {
		status: response.status,
		statusText: response.statusText,
		headers: response.headers,
	})
}

async function findCachedNavigationFallback(cache) {
	const keys = await cache.keys()
	for (const req of keys) {
		const reqUrl = new URL(req.url)
		if (
			reqUrl.pathname.endsWith('/apps/sheetmusic')
			|| reqUrl.pathname.endsWith('/apps/sheetmusic/')
		) {
			const match = await cache.match(req, { ignoreVary: true })
			if (match) {
				return match
			}
		}
	}
	return null
}

async function serveCachedOfflineResponse(request) {
	const matchOpts = {
		ignoreVary: true,
		ignoreSearch: request.mode === 'navigate',
	}

	const [offlineCache, shellCache] = await Promise.all([
		caches.open(OFFLINE_SETLIST_CACHE),
		caches.open(APP_SHELL_CACHE),
	])
	for (const cache of [offlineCache, shellCache]) {
		const match = await cache.match(request, matchOpts)
		if (match) {
			return request.mode === 'navigate' ? toCleanNavigationResponse(match) : match
		}
	}

	if (request.mode === 'navigate') {
		const fallback = await findCachedNavigationFallback(shellCache)
		if (fallback) {
			return toCleanNavigationResponse(fallback)
		}
	}

	return null
}

function extractAppShellAssetUrls(html, baseUrl) {
	const urls = new Set()
	const attrRegex = /<(?:script|link)\b[^>]*(?:src|href)=["']([^"']+)["'][^>]*>/gi
	let match
	while ((match = attrRegex.exec(html)) !== null) {
		try {
			const resolved = new URL(match[1], baseUrl)
			if (resolved.origin === self.location.origin && isCacheableAppShellRequest({ mode: 'no-cors' }, resolved)) {
				urls.add(resolved.toString())
			}
		} catch {
			// Ignore malformed URLs
		}
	}
	return Array.from(urls)
}

function extractCssImportUrls(cssText, baseUrl) {
	const urls = new Set()
	const importRegex = /@import\s+(?:url\(\s*)?["']([^"']+)["']\s*\)?/gi
	let match
	while ((match = importRegex.exec(cssText)) !== null) {
		try {
			const resolved = new URL(match[1], baseUrl)
			if (resolved.origin === self.location.origin) {
				urls.add(resolved.toString())
			}
		} catch {
			// Ignore malformed URLs
		}
	}
	return Array.from(urls)
}

async function precacheAssetWithCssImports(cache, assetUrl) {
	const assetRes = await fetch(assetUrl, { credentials: 'include', cache: 'reload' })
	if (!assetRes || !assetRes.ok) {
		return
	}
	const contentType = assetRes.headers.get('content-type') || ''
	const isCss = contentType.includes('text/css') || new URL(assetUrl).pathname.endsWith('.css')
	const cssClone = isCss ? assetRes.clone() : null
	await cache.put(assetUrl, assetRes)

	if (cssClone) {
		const importedUrls = extractCssImportUrls(await cssClone.text(), assetUrl)
		await Promise.allSettled(
			importedUrls.map(async (importUrl) => {
				const importRes = await fetch(importUrl, { credentials: 'include', cache: 'reload' })
				if (importRes && importRes.ok) {
					await cache.put(importUrl, importRes)
				}
			}),
		)
	}
}

async function handleOfflineAwareGet(request, url) {
	// Versioned PDFs/files pinned in offline setlists can be served cache-first immediately
	if (isVersionedBinaryUrl(url)) {
		const offlineCache = await caches.open(OFFLINE_SETLIST_CACHE)
		const cached = await offlineCache.match(request, { ignoreVary: true })
		if (cached) {
			return cached
		}
	}

	try {
		const networkResponse = shouldRevalidateEntryRequest(request, url)
			? await fetch(request, { cache: 'no-cache' })
			: await fetch(request)
		if (networkResponse && networkResponse.ok && !networkResponse.url.includes('/login')) {
			if (isCacheableAppShellRequest(request, url)) {
				const shellCache = await caches.open(APP_SHELL_CACHE)
				const toCache = request.mode === 'navigate'
					? toCleanNavigationResponse(networkResponse.clone())
					: networkResponse.clone()
				shellCache.put(request, toCache).catch(() => {})
			}
		} else if (networkResponse && networkResponse.status >= 500 && request.mode === 'navigate') {
			const cachedFallback = await serveCachedOfflineResponse(request)
			if (cachedFallback) {
				return cachedFallback
			}
		}
		return networkResponse
	} catch {
		const cached = await serveCachedOfflineResponse(request)
		if (cached) {
			return cached
		}

		return new Response('Offline', { status: 503, statusText: 'Service Unavailable' })
	}
}

self.addEventListener('install', (event) => {
	event.waitUntil((async () => {
		try {
			const shellUrl = new URL('./', self.location.href).toString()
			const res = await fetch(shellUrl, { credentials: 'include', cache: 'reload' })
			if (res && res.ok && !res.url.includes('/login')) {
				const cache = await caches.open(APP_SHELL_CACHE)
				const htmlClone = res.clone()
				await cache.put(shellUrl, toCleanNavigationResponse(res))
				const html = await htmlClone.text()
				const assetUrls = extractAppShellAssetUrls(html, shellUrl)
				await Promise.allSettled(
					assetUrls.map((assetUrl) => precacheAssetWithCssImports(cache, assetUrl)),
				)
			}
		} catch {
			// Best-effort pre-cache on install
		}
		await self.skipWaiting()
	})())
})

self.addEventListener('activate', (event) => {
	event.waitUntil((async () => {
		try {
			const keys = await caches.keys()
			await Promise.all(
				keys
					.filter((key) => key.startsWith(APP_SHELL_CACHE_PREFIX) && key !== APP_SHELL_CACHE)
					.map((key) => caches.delete(key)),
			)
		} catch {
			// Best-effort cleanup of stale app-shell caches
		}
		await self.clients.claim()
	})())
})

self.addEventListener('fetch', (event) => {
	const request = event.request
	const url = new URL(request.url)

	if (request.method === 'POST' && url.searchParams.get('share-target') === '1') {
		event.respondWith((async () => {
			try {
				const formData = await request.formData()
				const rawFiles = formData.getAll('files').filter((entry) => entry instanceof File)
				const files = rawFiles.map((file) => ({
					name: file.name,
					type: file.type,
					lastModified: file.lastModified,
					blob: file,
				}))

				await storePendingShare({
					files,
					title: String(formData.get('title') || ''),
					text: String(formData.get('text') || ''),
					url: String(formData.get('url') || ''),
					createdAt: Date.now(),
				})
			} catch (err) {
				console.error('Sheet Music SW: failed to process share target payload', err)
			}

			const redirectUrl = new URL(request.url)
			redirectUrl.searchParams.set('share-target', '1')
			return Response.redirect(redirectUrl.toString(), 303)
		})())
		return
	}

	if (request.method === 'GET' && url.origin === self.location.origin) {
		if (isCacheableAppShellRequest(request, url) || url.pathname.includes('/remote.php/dav/')) {
			event.respondWith(handleOfflineAwareGet(request, url))
		}
	}
})
