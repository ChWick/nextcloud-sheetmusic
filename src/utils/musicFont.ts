/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { fetchFileMusicFont, saveFileMusicFont } from '../api/annotations'
import { isEffectivelyOffline } from '../composables/useOnlineStatus'
import { MUSIC_FONT_FAMILY } from './musicSymbols'

export type MusicFontId = 'bravura' | 'leland' | 'petaluma'

export interface MusicFontOption {
	id: MusicFontId
	label: string
	cssFamily: string
}

export const MUSIC_FONT_OPTIONS: MusicFontOption[] = [
	{ id: 'bravura', label: 'Standard (Bravura)', cssFamily: "'Bravura', serif" },
	{ id: 'leland', label: 'Classical (Leland)', cssFamily: "'Leland', 'Bravura', serif" },
	{ id: 'petaluma', label: 'Jazz (Petaluma)', cssFamily: "'Petaluma', 'Bravura', serif" },
]

const STORAGE_KEY = 'sheetmusic:file-music-fonts:v1'
const VALID_FONTS = new Set<MusicFontId>(['bravura', 'leland', 'petaluma'])

export function isValidMusicFontId(val: unknown): val is MusicFontId {
	return typeof val === 'string' && VALID_FONTS.has(val as MusicFontId)
}

export function getMusicFontFamily(fontId?: MusicFontId | null): string {
	const opt = MUSIC_FONT_OPTIONS.find((f) => f.id === fontId)
	return opt?.cssFamily ?? MUSIC_FONT_FAMILY
}

function readCache(): Record<string, MusicFontId> {
	if (typeof localStorage === 'undefined') return {}
	try {
		const raw = localStorage.getItem(STORAGE_KEY)
		if (!raw) return {}
		const parsed = JSON.parse(raw)
		return parsed && typeof parsed === 'object' ? (parsed as Record<string, MusicFontId>) : {}
	} catch {
		return {}
	}
}

function writeCache(map: Record<string, MusicFontId>): void {
	if (typeof localStorage === 'undefined') return
	try {
		localStorage.setItem(STORAGE_KEY, JSON.stringify(map))
	} catch {
		// Ignore quota or private-mode errors
	}
}

export function getCachedFileMusicFont(fileId?: number | null): MusicFontId {
	if (!fileId || fileId <= 0) return 'bravura'
	const stored = readCache()[String(fileId)]
	return isValidMusicFontId(stored) ? stored : 'bravura'
}

export function setCachedFileMusicFont(fileId: number, font: MusicFontId): void {
	if (fileId <= 0) return
	const cache = readCache()
	cache[String(fileId)] = font
	writeCache(cache)
}

export async function loadFileMusicFont(fileId?: number | null): Promise<MusicFontId> {
	if (!fileId || fileId <= 0) return 'bravura'
	const cached = getCachedFileMusicFont(fileId)
	if (isEffectivelyOffline()) return cached
	try {
		const remote = await fetchFileMusicFont(fileId)
		const resolved = isValidMusicFontId(remote) ? remote : 'bravura'
		setCachedFileMusicFont(fileId, resolved)
		return resolved
	} catch {
		return cached
	}
}

export async function persistFileMusicFont(fileId: number, font: MusicFontId): Promise<void> {
	if (fileId <= 0) return
	setCachedFileMusicFont(fileId, font)
	if (isEffectivelyOffline()) return
	try {
		await saveFileMusicFont(fileId, font)
	} catch {
		// Offline or unauthenticated: keep local cache
	}
}
