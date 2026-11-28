/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { fetchCachedBinaryFile, preloadCachedBinaryFile } from '../../../src/utils/file-binary'
import { MemoryFileBinaryCache } from '../../../src/utils/file-cache'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
	},
}))

const PDF_BYTES = new Uint8Array([0x25, 0x50, 0x44, 0x46, 0x2d, 0x31, 0x2e, 0x34])

describe('file-binary', () => {
	it('deduplicates concurrent preload and foreground fetch requests for the same URL', async () => {
		const cache = new MemoryFileBinaryCache()
		let resolveRequest!: (val: { data: ArrayBuffer }) => void
		const pending = new Promise<{ data: ArrayBuffer }>((resolve) => {
			resolveRequest = resolve
		})
		const getMock = vi.mocked(axios.get)
		getMock.mockReset()
		getMock.mockReturnValue(pending as any)

		const p1 = preloadCachedBinaryFile('/scores/shared.pdf?v=1', { cache, requirePdfHeader: true })
		const p2 = fetchCachedBinaryFile('/scores/shared.pdf?v=1', { cache })

		expect(getMock).toHaveBeenCalledTimes(1)
		resolveRequest({ data: PDF_BYTES.buffer })

		const [r1, r2] = await Promise.all([p1, p2])
		expect(r1).toEqual(PDF_BYTES)
		expect(r2).toEqual(PDF_BYTES)
		expect(cache.has('/scores/shared.pdf?v=1')).toBe(true)
	})

	it('skips caching non-PDF payloads when requirePdfHeader is true', async () => {
		const cache = new MemoryFileBinaryCache()
		const getMock = vi.mocked(axios.get)
		getMock.mockReset()
		getMock.mockResolvedValue({ data: new Uint8Array([0x3c, 0x68, 0x74, 0x6d, 0x6c]).buffer } as any)

		const result = await preloadCachedBinaryFile('/scores/error-page.pdf?v=2', {
			cache,
			requirePdfHeader: true,
		})
		expect(result).toBeNull()
		expect(cache.has('/scores/error-page.pdf?v=2')).toBe(false)
	})
})
