import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export const SHARE_DB_NAME = 'sheetmusic-pwa-share'
export const SHARE_DB_VERSION = 1
export const SHARE_STORE_NAME = 'pendingShares'
export const SHARE_KEY = 'latest'

export interface StoredSharedFile {
	name: string
	type?: string
	lastModified?: number
	blob: Blob
}

export interface PendingShareRecord {
	files?: Array<File | StoredSharedFile>
	title?: string
	text?: string
	url?: string
	createdAt?: number
}

export interface PendingSharePayload {
	files: File[]
	title: string
	text: string
	url: string
	createdAt: number
}

const ACCEPTED_MIME_TYPES = new Set([
	'application/pdf',
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
])

export function isSupportedSharedFile(file: File): boolean {
	const name = file.name.toLowerCase()
	if (name.endsWith('.pdf') || name.endsWith('.docx')) {
		return true
	}
	return ACCEPTED_MIME_TYPES.has(file.type)
}

export function defaultTitleFromFilename(filename: string): string {
	return filename.trim().replace(/\.(pdf|docx)$/i, '').trim()
}

export function hasShareTargetParam(search?: string): boolean {
	if (typeof window === 'undefined' && search === undefined) {
		return false
	}
	const raw = search ?? window.location.search
	return new URLSearchParams(raw).get('share-target') === '1'
}

export function clearShareTargetParam(): void {
	if (typeof window === 'undefined') {
		return
	}
	const url = new URL(window.location.href)
	if (!url.searchParams.has('share-target') && !url.searchParams.has('share-token')) {
		return
	}
	url.searchParams.delete('share-target')
	url.searchParams.delete('share-token')
	window.history.replaceState(window.history.state, '', url.toString())
}

export function getShareTokenParam(search?: string): string | null {
	if (typeof window === 'undefined' && search === undefined) {
		return null
	}
	const raw = search ?? window.location.search
	const token = new URLSearchParams(raw).get('share-token')
	return token && /^[a-f0-9]{32}$/.test(token) ? token : null
}

function base64ToUint8Array(base64: string): Uint8Array {
	const binary = atob(base64)
	const bytes = new Uint8Array(binary.length)
	for (let i = 0; i < binary.length; i++) {
		bytes[i] = binary.charCodeAt(i)
	}
	return bytes
}

function toFile(entry: File | StoredSharedFile): File {
	if (entry instanceof File) {
		return entry
	}
	return new File([entry.blob], entry.name, {
		type: entry.type ?? entry.blob.type ?? '',
		lastModified: entry.lastModified ?? Date.now(),
	})
}

function openShareDb(): Promise<IDBDatabase> {
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

/**
 * Reads and deletes the latest pending Web Share Target payload from either
 * the server-staged token endpoint (fallback when SW wasn't active) or IndexedDB.
 */
export async function consumePendingShare(shareToken?: string | null): Promise<PendingSharePayload | null> {
	const token = shareToken === undefined ? getShareTokenParam() : shareToken
	if (token) {
		try {
			const res = await axios.get(generateUrl(`/apps/sheetmusic/share-target/${token}`))
			const data = res.data as {
				title?: string
				text?: string
				url?: string
				files?: Array<{ name: string; type?: string; dataBase64: string }>
			}
			const files = (data.files ?? [])
				.map((entry) => new File([base64ToUint8Array(entry.dataBase64) as BlobPart], entry.name, {
					type: entry.type || '',
				}))
				.filter(isSupportedSharedFile)
			if (files.length === 0) {
				return null
			}
			return {
				files,
				title: data.title ?? '',
				text: data.text ?? '',
				url: data.url ?? '',
				createdAt: Date.now(),
			}
		} catch {
			return null
		}
	}

	if (typeof indexedDB === 'undefined') {
		return null
	}

	const db = await openShareDb()
	try {
		const record = await new Promise<PendingShareRecord | undefined>((resolve, reject) => {
			const tx = db.transaction(SHARE_STORE_NAME, 'readwrite')
			const store = tx.objectStore(SHARE_STORE_NAME)
			const getReq = store.get(SHARE_KEY)
			getReq.onsuccess = () => {
				const value = getReq.result as PendingShareRecord | undefined
				store.delete(SHARE_KEY)
				resolve(value)
			}
			getReq.onerror = () => reject(getReq.error)
		})

		if (!record) {
			return null
		}

		const files = (record.files ?? [])
			.map(toFile)
			.filter(isSupportedSharedFile)

		if (files.length === 0) {
			return null
		}

		return {
			files,
			title: record.title ?? '',
			text: record.text ?? '',
			url: record.url ?? '',
			createdAt: record.createdAt ?? Date.now(),
		}
	} finally {
		db.close()
	}
}
