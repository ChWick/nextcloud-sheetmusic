/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { MemoryFileBinaryCache } from '../../../src/utils/file-cache'
import { fetchCachedBinaryFile } from '../../../src/utils/file-binary'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
	},
}))

vi.mock('../../../src/composables/useOnlineStatus', () => ({
	isEffectivelyOffline: vi.fn(() => false),
}))

vi.mock('../../../src/utils/offlineSetlists', () => ({
	readOfflineCachedBinary: vi.fn(async () => null),
}))

import { isEffectivelyOffline } from '../../../src/composables/useOnlineStatus'
import { readOfflineCachedBinary } from '../../../src/utils/offlineSetlists'

describe('fetchCachedBinaryFile', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		vi.mocked(isEffectivelyOffline).mockReturnValue(false)
		vi.mocked(readOfflineCachedBinary).mockResolvedValue(null)
	})

	it('returns cached payload without making a network request', async () => {
		const cache = new MemoryFileBinaryCache()
		const payload = new Uint8Array([1, 2, 3])
		cache.set('/dav/score.pdf?v=1', payload)

		const result = await fetchCachedBinaryFile('/dav/score.pdf?v=1', { cache })
		expect(result).toBe(payload)
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('fetches from network with no-cache headers for unversioned URLs and caches result', async () => {
		const cache = new MemoryFileBinaryCache()
		const buf = new Uint8Array([9, 8, 7]).buffer
		vi.mocked(axios.get).mockResolvedValue({ data: buf } as any)

		const result = await fetchCachedBinaryFile('/dav/score.pdf', { cache })
		expect(result).toEqual(new Uint8Array([9, 8, 7]))
		expect(axios.get).toHaveBeenCalledWith('/dav/score.pdf', {
			responseType: 'arraybuffer',
			headers: { 'Cache-Control': 'no-cache', Pragma: 'no-cache' },
		})
		expect(cache.get('/dav/score.pdf')).toEqual(new Uint8Array([9, 8, 7]))
	})

	it('falls back to offline cache when offline or network fails', async () => {
		const cache = new MemoryFileBinaryCache()
		const offlineBytes = new Uint8Array([4, 5, 6])
		vi.mocked(readOfflineCachedBinary).mockResolvedValue(offlineBytes)
		vi.mocked(axios.get).mockRejectedValue(new Error('network down'))

		const result = await fetchCachedBinaryFile('/dav/offline.pdf?v=2', { cache })
		expect(result).toEqual(offlineBytes)
		expect(cache.get('/dav/offline.pdf?v=2')).toEqual(offlineBytes)

		vi.mocked(isEffectivelyOffline).mockReturnValue(true)
		vi.mocked(readOfflineCachedBinary).mockResolvedValue(null)
		await expect(fetchCachedBinaryFile('/dav/missing.pdf', { cache })).rejects.toThrow('File is not available offline')
	})

	it('returns null without caching when request becomes stale', async () => {
		const cache = new MemoryFileBinaryCache()
		vi.mocked(axios.get).mockResolvedValue({ data: new Uint8Array([1]).buffer } as any)

		const result = await fetchCachedBinaryFile('/dav/stale.pdf?v=1', {
			cache,
			isStale: () => true,
		})
		expect(result).toBeNull()
		expect(cache.has('/dav/stale.pdf?v=1')).toBe(false)
	})
})
