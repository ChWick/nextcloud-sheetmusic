/* global self, indexedDB, Response, URL, File */
const SHARE_DB_NAME = 'sheetmusic-pwa-share'
const SHARE_DB_VERSION = 1
const SHARE_STORE_NAME = 'pendingShares'
const SHARE_KEY = 'latest'

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

self.addEventListener('install', () => {
	self.skipWaiting()
})

self.addEventListener('activate', (event) => {
	event.waitUntil(self.clients.claim())
})

self.addEventListener('fetch', (event) => {
	const request = event.request
	if (request.method !== 'POST') {
		return
	}

	const url = new URL(request.url)
	if (url.searchParams.get('share-target') !== '1') {
		return
	}

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
})
