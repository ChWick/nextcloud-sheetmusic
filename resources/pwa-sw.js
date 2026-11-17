/* global self, indexedDB, caches, fetch, Response, URL, File */
const SHARE_DB_NAME = 'sheetmusic-pwa-share'
const SHARE_DB_VERSION = 1
const SHARE_STORE_NAME = 'pendingShares'
const SHARE_KEY = 'latest'

const APP_SHELL_CACHE = 'sheetmusic-app-shell-v1'
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
		|| url.pathname.startsWith('/ocs/v2.php/apps/sheetmusic/')
		|| url.pathname.includes('/core/')
		|| url.pathname.includes('/dist/')
	)
}

function isVersionedBinaryUrl(url) {
	return (url.pathname.includes('/remote.php/dav/') || url.pathname.includes('/files/') && url.pathname.endsWith('/content'))
		&& url.searchParams.has('v')
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
		const networkResponse = await fetch(request)
		if (networkResponse && networkResponse.ok) {
			if (isCacheableAppShellRequest(request, url)) {
				const shellCache = await caches.open(APP_SHELL_CACHE)
				shellCache.put(request, networkResponse.clone()).catch(() => {})
			}
		}
		return networkResponse
	} catch {
		const offlineCache = await caches.open(OFFLINE_SETLIST_CACHE)
		const offlineMatch = await offlineCache.match(request, { ignoreVary: true })
		if (offlineMatch) {
			return offlineMatch
		}

		const shellCache = await caches.open(APP_SHELL_CACHE)
		const shellMatch = await shellCache.match(request, {
			ignoreVary: true,
			ignoreSearch: request.mode === 'navigate',
		})
		if (shellMatch) {
			return shellMatch
		}

		return new Response('Offline', { status: 503, statusText: 'Service Unavailable' })
	}
}

self.addEventListener('install', () => {
	self.skipWaiting()
})

self.addEventListener('activate', (event) => {
	event.waitUntil(self.clients.claim())
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
