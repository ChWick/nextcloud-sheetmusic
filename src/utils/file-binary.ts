/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { isEffectivelyOffline } from '../composables/useOnlineStatus'
import { getCacheBustedFileUrl, toUint8Array } from './file'
import { defaultFileBinaryCache, type FileBinaryCache } from './file-cache'
import { readOfflineCachedBinary } from './offlineSetlists'

/**
 * Fetch fresh, uncached binary bytes for a file (used before modifying or merging PDFs).
 */
export async function fetchUncachedBinaryFile(
	filePath: string,
	versionToken?: number | string | null,
): Promise<Uint8Array> {
	const url = getCacheBustedFileUrl(filePath, versionToken)
	const response = await axios.get(url, {
		responseType: 'arraybuffer',
		headers: {
			'Cache-Control': 'no-cache',
			'Pragma': 'no-cache',
		},
	})
	return toUint8Array(response.data)
}

const inFlightBinaryRequests = new Map<string, Promise<Uint8Array>>()

function hasPdfHeader(bytes: Uint8Array): boolean {
	return bytes.length >= 4
		&& bytes[0] === 0x25
		&& bytes[1] === 0x50
		&& bytes[2] === 0x44
		&& bytes[3] === 0x46
}

function requestBinaryOverNetwork(cacheKey: string): Promise<Uint8Array> {
	const existing = inFlightBinaryRequests.get(cacheKey)
	if (existing) {
		return existing
	}

	const hasVersionParam = cacheKey.includes('v=') || cacheKey.includes('t=')
	const promise = axios.get(cacheKey, {
		responseType: 'arraybuffer',
		...(hasVersionParam ? {} : {
			headers: {
				'Cache-Control': 'no-cache',
				'Pragma': 'no-cache',
			},
		}),
	}).then((response) => toUint8Array(response.data))
		.finally(() => {
			if (inFlightBinaryRequests.get(cacheKey) === promise) {
				inFlightBinaryRequests.delete(cacheKey)
			}
		})

	inFlightBinaryRequests.set(cacheKey, promise)
	return promise
}

/**
 * Best-effort background preload of a binary file into the in-memory cache.
 * Shares any in-flight request with `fetchCachedBinaryFile` to avoid duplicate downloads.
 */
export async function preloadCachedBinaryFile(
	cacheKey: string,
	options: {
		cache?: FileBinaryCache
		requirePdfHeader?: boolean
	} = {},
): Promise<Uint8Array | null> {
	const cache = options.cache ?? defaultFileBinaryCache
	const cached = cache.get(cacheKey)
	if (cached) {
		return cached
	}
	if (isEffectivelyOffline()) {
		try {
			const offlineData = await readOfflineCachedBinary(cacheKey)
			if (offlineData && (!options.requirePdfHeader || hasPdfHeader(offlineData))) {
				cache.set(cacheKey, offlineData)
				return offlineData
			}
		} catch {
			// Ignore background preload errors
		}
		return null
	}
	try {
		const data = await requestBinaryOverNetwork(cacheKey)
		if (data && (!options.requirePdfHeader || hasPdfHeader(data))) {
			cache.set(cacheKey, data)
			return data
		}
	} catch {
		// Ignore background preload errors
	}
	return null
}

/**
 * Fetch a binary file payload with in-memory LRU caching, in-flight request
 * deduplication, and offline Cache API fallback.
 * Returns `null` if `isStale()` becomes true while awaiting async operations.
 */
export async function fetchCachedBinaryFile(
	cacheKey: string,
	options: {
		cache?: FileBinaryCache
		isStale?: () => boolean
	} = {},
): Promise<Uint8Array | null> {
	const cache = options.cache ?? defaultFileBinaryCache
	const isStale = options.isStale ?? (() => false)

	let data: Uint8Array | null = cache.get(cacheKey)
	if (!data && isEffectivelyOffline()) {
		data = await readOfflineCachedBinary(cacheKey)
		if (!data) {
			throw new Error('File is not available offline')
		}
		if (isStale()) return null
	}

	if (!data) {
		try {
			const fetched = await requestBinaryOverNetwork(cacheKey)
			if (isStale()) return null
			data = fetched
		} catch (err) {
			const offlineData = await readOfflineCachedBinary(cacheKey)
			if (!offlineData) {
				throw err
			}
			if (isStale()) return null
			data = offlineData
		}
		if (data) {
			cache.set(cacheKey, data)
		}
	}

	return data
}
