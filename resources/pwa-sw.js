/* global self, indexedDB, caches, fetch, Response, Blob, Headers, URL, File */
const SHARE_DB_NAME = 'sheetmusic-pwa-share'
const SHARE_DB_VERSION = 1
const SHARE_STORE_NAME = 'pendingShares'
const SHARE_KEY = 'latest'

const APP_VERSION = '__APP_VERSION__'
const APP_SHELL_CACHE_PREFIX = 'sheetmusic-app-shell-'
const APP_SHELL_CACHE = APP_SHELL_CACHE_PREFIX + APP_VERSION
const OFFLINE_SETLIST_CACHE = 'sheetmusic-offline-setlists-v1'
const PROGRESS_BROADCAST_INTERVAL_MS = 60
const FRESH_ACTIVATE_CACHE_WINDOW_MS = 15 * 1000

let activatedAt = 0

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

async function broadcastUpdateProgress(payload) {
	try {
		const clients = await self.clients.matchAll({
			includeUncontrolled: true,
			type: 'window',
		})
		const message = {
			type: 'SW_UPDATE_PROGRESS',
			version: APP_VERSION,
			...payload,
		}
		for (const client of clients) {
			client.postMessage(message)
		}
	} catch {
		// Best-effort progress reporting
	}
}

function isCacheableAppShellRequest(request, url) {
	if (url.origin !== self.location.origin) {
		return false
	}
	if (
		url.pathname.endsWith('/sw.js')
		|| url.pathname.endsWith('/pwa-manifest')
		|| url.pathname.endsWith('/app-version')
		|| url.pathname.includes('/share-target/')
	) {
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
		|| url.pathname.includes('/ocs/v2.php/apps/sheetmusic/')
		|| url.pathname.includes('/apps/theming/')
		|| url.pathname.includes('/core/')
		|| url.pathname.includes('/dist/')
	)
}

function isVersionedBinaryUrl(url) {
	return (url.pathname.includes('/remote.php/dav/') || (url.pathname.includes('/files/') && url.pathname.endsWith('/content')))
		&& url.searchParams.has('v')
}

const ENTRY_ASSET_SUFFIXES = [
	'/js/sheetmusic-main.mjs',
	'/js/sheetmusic-pwa.mjs',
	'/css/sheetmusic-main.css',
	'/css/sheetmusic-pwa.css',
]

function shouldRevalidateEntryRequest(request, url) {
	if (request.mode === 'navigate') {
		return true
	}
	if (request.destination === 'style' && !url.pathname.includes('.chunk.css')) {
		return true
	}
	return ENTRY_ASSET_SUFFIXES.some((suffix) => url.pathname.endsWith(suffix))
}

function isImmutableHashedAsset(url) {
	return (
		url.pathname.includes('.chunk.mjs')
		|| url.pathname.includes('.chunk.css')
		|| /\/dist\/pdf\.worker\.min-[^/]+\.mjs$/.test(url.pathname)
		|| url.pathname.includes('/css/fonts/')
	)
}

function isSheetmusicEntryJs(url) {
	return (
		url.pathname.endsWith('/js/sheetmusic-main.mjs')
		|| url.pathname.endsWith('/js/sheetmusic-pwa.mjs')
		|| /\/js\/pdfjs-[^/]+\.chunk\.mjs$/.test(url.pathname)
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

function collectSameOriginUrls(text, regex, baseUrl, targetSet, transformPath = (p) => p, filter = null) {
	let match
	while ((match = regex.exec(text)) !== null) {
		try {
			const resolved = new URL(transformPath(match[1]), baseUrl)
			if (resolved.origin === self.location.origin && (!filter || filter(resolved))) {
				targetSet.add(resolved.toString())
			}
		} catch {
			// Ignore malformed URLs
		}
	}
}

function extractAppShellAssetUrls(html, baseUrl) {
	const urls = new Set()
	collectSameOriginUrls(
		html,
		/<(?:script|link)\b[^>]*(?:src|href)=["']([^"']+)["'][^>]*>/gi,
		baseUrl,
		urls,
		(p) => p,
		(resolved) => isCacheableAppShellRequest({ mode: 'no-cors' }, resolved),
	)
	return Array.from(urls)
}

function extractCssImportUrls(cssText, baseUrl) {
	const urls = new Set()
	collectSameOriginUrls(cssText, /@import\s+(?:url\(\s*)?["']([^"']+)["']\s*\)?/gi, baseUrl, urls)
	return Array.from(urls)
}

function extractJsImportUrls(jsText, baseUrl) {
	const urls = new Set()
	// Static ES module imports from relative chunk files (e.g. from "./pdfjs-xxx.chunk.mjs")
	collectSameOriginUrls(jsText, /\b(?:from|import)\s*["'](\.\/[^"']+\.m?js)["']/g, baseUrl, urls)
	// Vite __vite__mapDeps chunk references & PDF.js worker bundle
	collectSameOriginUrls(
		jsText,
		/["']((?:js|css)\/[^"']+\.chunk\.(?:m?js|css)|dist\/pdf\.worker[^"']+\.m?js)["']/g,
		baseUrl,
		urls,
		(p) => '../' + p,
	)
	return Array.from(urls)
}

async function fetchResponseWithProgress(url, onBytes) {
	const res = await fetch(url, { credentials: 'include', cache: 'reload' })
	if (!res || !res.ok) {
		return { response: null, blob: null }
	}

	// When HTTP compression (gzip/br) is active, Content-Length is the compressed wire size
	// while res.body.getReader() yields decompressed bytes. Only trust Content-Length as the
	// total byte size when the response is not content-encoded.
	const isContentEncoded = Boolean(res.headers.get('content-encoding'))
	const contentLengthHeader = res.headers.get('content-length')
	const contentLength = contentLengthHeader ? parseInt(contentLengthHeader, 10) : 0
	const knownLength = !isContentEncoded && Number.isFinite(contentLength) && contentLength > 0 ? contentLength : 0
	if (knownLength > 0) {
		onBytes(0, knownLength)
	}

	const cleanHeaders = new Headers(res.headers)
	cleanHeaders.delete('content-encoding')
	cleanHeaders.delete('content-length')

	let blob
	if (!res.body || typeof res.body.getReader !== 'function') {
		blob = await res.blob()
		onBytes(blob.size, 0)
	} else {
		const reader = res.body.getReader()
		const chunks = []
		while (true) {
			const { done, value } = await reader.read()
			if (done) {
				break
			}
			if (value && value.byteLength > 0) {
				chunks.push(value)
				onBytes(value.byteLength, 0)
			}
		}
		blob = new Blob(chunks, { type: cleanHeaders.get('content-type') || '' })
	}

	const response = new Response(blob, {
		status: res.status,
		statusText: res.statusText,
		headers: cleanHeaders,
	})
	return { response, blob }
}

async function precacheAppShellWithProgress() {
	const state = {
		completedFiles: 0,
		totalFiles: 1,
		loadedBytes: 0,
		totalBytes: 0,
		lastPercent: 0,
		lastBroadcastAt: 0,
	}

	const emitProgress = async (stage, force = false) => {
		const now = Date.now()
		if (!force && now - state.lastBroadcastAt < PROGRESS_BROADCAST_INTERVAL_MS) {
			return
		}
		state.lastBroadcastAt = now

		let rawPercent = 0
		if (stage === 'installing' || stage === 'waiting' || stage === 'activated') {
			rawPercent = 100
		} else if (state.totalFiles > 0) {
			const fileRatio = state.completedFiles / state.totalFiles
			const hasExactTotals = state.totalBytes > 0 && state.loadedBytes <= state.totalBytes
			const byteRatio = hasExactTotals ? Math.min(1, state.loadedBytes / state.totalBytes) : fileRatio
			// Blend byte progress (only when exact uncompressed Content-Length totals exist) with file progress
			const blended = hasExactTotals ? (byteRatio * 0.7 + fileRatio * 0.3) : fileRatio
			rawPercent = Math.min(99, Math.round(blended * 100))
		}

		const percent = Math.max(state.lastPercent, rawPercent)
		state.lastPercent = percent
		const safeTotalBytes = state.totalBytes >= state.loadedBytes ? state.totalBytes : 0

		await broadcastUpdateProgress({
			stage,
			percent,
			completedFiles: state.completedFiles,
			totalFiles: state.totalFiles,
			loadedBytes: state.loadedBytes,
			totalBytes: safeTotalBytes,
		})
	}

	await emitProgress('downloading', true)

	const shellUrl = new URL('./', self.location.href).toString()
	const res = await fetch(shellUrl, { credentials: 'include', cache: 'reload' })
	if (!res || !res.ok || res.url.includes('/login')) {
		return
	}

	const cache = await caches.open(APP_SHELL_CACHE)
	const htmlClone = res.clone()
	await cache.put(shellUrl, toCleanNavigationResponse(res))
	const html = await htmlClone.text()

	const seenUrls = new Set([shellUrl])
	const queue = extractAppShellAssetUrls(html, shellUrl).filter((u) => !seenUrls.has(u))
	for (const u of queue) {
		seenUrls.add(u)
	}

	state.completedFiles = 1
	state.totalFiles = 1 + queue.length
	await emitProgress('downloading', true)

	const enqueue = (urls) => {
		let added = 0
		for (const u of urls) {
			if (!seenUrls.has(u)) {
				seenUrls.add(u)
				queue.push(u)
				added++
			}
		}
		if (added > 0) {
			state.totalFiles += added
		}
	}

	const processAsset = async (assetUrl) => {
		try {
			const { response, blob } = await fetchResponseWithProgress(assetUrl, (deltaLoaded, deltaTotal) => {
				state.loadedBytes += deltaLoaded
				state.totalBytes += deltaTotal
				void emitProgress('downloading', false)
			})
			if (!response) {
				return
			}

			const parsedUrl = new URL(assetUrl)
			const contentType = response.headers.get('content-type') || ''
			const isCss = contentType.includes('text/css') || parsedUrl.pathname.endsWith('.css')
			const isEntryJs = isSheetmusicEntryJs(parsedUrl)

			await cache.put(assetUrl, response)

			if (blob && isCss) {
				const cssText = await blob.text()
				enqueue(extractCssImportUrls(cssText, assetUrl))
			} else if (blob && isEntryJs) {
				const jsText = await blob.text()
				enqueue(extractJsImportUrls(jsText, assetUrl))
			}
		} catch {
			// Best-effort individual asset caching
		} finally {
			state.completedFiles++
			await emitProgress('downloading', true)
		}
	}

	const concurrency = 4
	const workers = []
	const runWorker = async () => {
		while (queue.length > 0) {
			const nextUrl = queue.shift()
			if (nextUrl) {
				await processAsset(nextUrl)
			}
		}
	}
	for (let i = 0; i < concurrency; i++) {
		workers.push(runWorker())
	}
	await Promise.all(workers)

	const hasActiveWorker = Boolean(self.registration && self.registration.active)
	await emitProgress(hasActiveWorker ? 'waiting' : 'installing', true)
}

async function fetchRevalidatedRequest(request) {
	if (request.mode === 'navigate') {
		return fetch(request.url, {
			credentials: 'include',
			cache: 'no-cache',
			headers: {
				Accept: request.headers.get('Accept') || 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
			},
		})
	}
	return fetch(request.url, {
		credentials: 'include',
		cache: 'no-cache',
	})
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

	// Content-hashed chunks & fonts (and freshly precached non-navigation assets right after
	// activating an update) can be served immediately from APP_SHELL_CACHE without re-downloading.
	if (
		request.mode !== 'navigate'
		&& isCacheableAppShellRequest(request, url)
		&& (isImmutableHashedAsset(url) || (activatedAt > 0 && Date.now() - activatedAt < FRESH_ACTIVATE_CACHE_WINDOW_MS))
	) {
		const shellCache = await caches.open(APP_SHELL_CACHE)
		const cached = await shellCache.match(request, { ignoreVary: true })
		if (cached) {
			return cached
		}
	}

	try {
		const networkResponse = shouldRevalidateEntryRequest(request, url)
			? await fetchRevalidatedRequest(request)
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

self.addEventListener('message', (event) => {
	const data = event.data
	if (!data || typeof data !== 'object') {
		return
	}
	if (data.type === 'SKIP_WAITING') {
		void self.skipWaiting()
	} else if (data.type === 'GET_SW_VERSION' && event.ports && event.ports[0]) {
		event.ports[0].postMessage({ type: 'SW_VERSION', version: APP_VERSION })
	}
})

self.addEventListener('install', (event) => {
	event.waitUntil((async () => {
		try {
			await precacheAppShellWithProgress()
		} catch {
			// Best-effort pre-cache on install
		}
		// Activate immediately only on initial install; on updates, wait for user
		// confirmation (SKIP_WAITING) so we never interrupt performance mode or unsaved edits.
		if (!self.registration || !self.registration.active) {
			await self.skipWaiting()
		}
	})())
})

self.addEventListener('activate', (event) => {
	event.waitUntil((async () => {
		activatedAt = Date.now()
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
		await broadcastUpdateProgress({
			stage: 'activated',
			percent: 100,
		})
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
