/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { isEffectivelyOffline } from '../composables/useOnlineStatus'
import { toUint8Array } from './file'
import { defaultFileBinaryCache, type FileBinaryCache } from './file-cache'
import { readOfflineCachedBinary } from './offlineSetlists'

/**
 * Fetch a binary file payload with in-memory LRU caching and offline Cache API fallback.
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
		const hasVersionParam = cacheKey.includes('v=') || cacheKey.includes('t=')
		try {
			const response = await axios.get(cacheKey, {
				responseType: 'arraybuffer',
				...(hasVersionParam ? {} : {
					headers: {
						'Cache-Control': 'no-cache',
						'Pragma': 'no-cache',
					},
				}),
			})

			if (isStale()) return null
			data = toUint8Array(response.data)
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
