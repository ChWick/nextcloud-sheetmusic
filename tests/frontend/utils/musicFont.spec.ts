/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../../src/api/annotations', () => ({
	fetchFileMusicFont: vi.fn(),
	saveFileMusicFont: vi.fn(),
}))

vi.mock('../../../src/composables/useOnlineStatus', () => ({
	isEffectivelyOffline: vi.fn(() => false),
}))

import { fetchFileMusicFont, saveFileMusicFont } from '../../../src/api/annotations'
import { isEffectivelyOffline } from '../../../src/composables/useOnlineStatus'
import {
	getCachedFileMusicFont,
	getMusicFontFamily,
	isValidMusicFontId,
	loadFileMusicFont,
	persistFileMusicFont,
	setCachedFileMusicFont,
} from '../../../src/utils/musicFont'

describe('musicFont utils', () => {
	beforeEach(() => {
		localStorage.clear()
		vi.clearAllMocks()
		vi.mocked(isEffectivelyOffline).mockReturnValue(false)
	})

	it('validates font IDs and resolves CSS font stacks', () => {
		expect(isValidMusicFontId('bravura')).toBe(true)
		expect(isValidMusicFontId('leland')).toBe(true)
		expect(isValidMusicFontId('petaluma')).toBe(true)
		expect(isValidMusicFontId('comic-sans')).toBe(false)
		expect(isValidMusicFontId(null)).toBe(false)

		expect(getMusicFontFamily('bravura')).toContain('Bravura')
		expect(getMusicFontFamily('leland')).toContain('Leland')
		expect(getMusicFontFamily('petaluma')).toContain('Petaluma')
		expect(getMusicFontFamily(undefined)).toContain('Bravura')
	})

	it('reads and writes per-file font selections in localStorage', () => {
		expect(getCachedFileMusicFont(0)).toBe('bravura')
		expect(getCachedFileMusicFont(42)).toBe('bravura')

		setCachedFileMusicFont(42, 'petaluma')
		setCachedFileMusicFont(99, 'leland')

		expect(getCachedFileMusicFont(42)).toBe('petaluma')
		expect(getCachedFileMusicFont(99)).toBe('leland')
	})

	it('loads font from server when online and caches it locally', async () => {
		vi.mocked(fetchFileMusicFont).mockResolvedValueOnce('leland')

		const font = await loadFileMusicFont(101)
		expect(font).toBe('leland')
		expect(fetchFileMusicFont).toHaveBeenCalledWith(101)
		expect(getCachedFileMusicFont(101)).toBe('leland')
	})

	it('falls back to cached font when offline or when server fetch fails', async () => {
		setCachedFileMusicFont(55, 'petaluma')

		vi.mocked(isEffectivelyOffline).mockReturnValue(true)
		expect(await loadFileMusicFont(55)).toBe('petaluma')
		expect(fetchFileMusicFont).not.toHaveBeenCalled()

		vi.mocked(isEffectivelyOffline).mockReturnValue(false)
		vi.mocked(fetchFileMusicFont).mockRejectedValueOnce(new Error('network'))
		expect(await loadFileMusicFont(55)).toBe('petaluma')
	})

	it('persists font locally and syncs to server when online', async () => {
		vi.mocked(saveFileMusicFont).mockResolvedValueOnce('petaluma')

		await persistFileMusicFont(77, 'petaluma')
		expect(getCachedFileMusicFont(77)).toBe('petaluma')
		expect(saveFileMusicFont).toHaveBeenCalledWith(77, 'petaluma')

		vi.mocked(isEffectivelyOffline).mockReturnValue(true)
		await persistFileMusicFont(78, 'leland')
		expect(getCachedFileMusicFont(78)).toBe('leland')
		expect(saveFileMusicFont).toHaveBeenCalledTimes(1)
	})
})
