/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { SetlistItem } from '../types/song'
import { formatDuration, splitDurationHms } from './song-formatter'

export interface SetlistBreak {
	id: string
	/** 0-based index of the setlist item after which this break occurs. */
	afterIndex: number
	label: string
	durationMinutes: number
}

export interface SetlistSegment {
	index: number
	startItemIndex: number
	endItemIndex: number
	songCount: number
	durationSeconds: number
	breakAfter: SetlistBreak | null
}

export interface SetlistTimingSummary {
	songSeconds: number
	breakSeconds: number
	totalSeconds: number
	remainingSeconds: number
	missingDurationCount: number
	segments: SetlistSegment[]
}

export const DEFAULT_BREAK_MINUTES = 15
export const MIN_BREAK_MINUTES = 1
export const MAX_BREAK_MINUTES = 180

const STORAGE_PREFIX = 'sheetmusic:setlist-breaks:'

function getStorageKey(libraryId: number, setlistId: number): string {
	return `${STORAGE_PREFIX}${libraryId}:${setlistId}`
}

/**
 * Clamp a break duration in minutes to a safe integer range.
 *
 * @param minutes Raw duration in minutes
 */
export function clampBreakMinutes(minutes: number): number {
	if (!Number.isFinite(minutes)) return DEFAULT_BREAK_MINUTES
	return Math.max(MIN_BREAK_MINUTES, Math.min(MAX_BREAK_MINUTES, Math.round(minutes)))
}

/**
 * Normalize and deduplicate a list of setlist break markers sorted by `afterIndex`.
 *
 * @param raw Raw break entries
 * @param itemCount Optional max item count to filter out-of-range breaks
 */
export function normalizeSetlistBreaks(raw: unknown, itemCount?: number): SetlistBreak[] {
	if (!Array.isArray(raw)) return []
	const byIndex = new Map<number, SetlistBreak>()
	for (const entry of raw) {
		if (!entry || typeof entry !== 'object') continue
		const obj = entry as Record<string, unknown>
		const afterIndex = Math.floor(Number(obj.afterIndex))
		if (!Number.isFinite(afterIndex) || afterIndex < 0) continue
		if (typeof itemCount === 'number' && afterIndex >= itemCount - 1) continue
		const label = typeof obj.label === 'string' && obj.label.trim() ? obj.label.trim() : 'Intermission'
		const durationMinutes = clampBreakMinutes(Number(obj.durationMinutes))
		const id = typeof obj.id === 'string' && obj.id ? obj.id : `brk-${afterIndex}`
		byIndex.set(afterIndex, { id, afterIndex, label, durationMinutes })
	}
	return Array.from(byIndex.values()).sort((a, b) => a.afterIndex - b.afterIndex)
}

/**
 * Load persisted break markers for a setlist from localStorage.
 *
 * @param libraryId Library ID
 * @param setlistId Setlist ID
 */
export function loadSetlistBreaks(libraryId: number, setlistId: number): SetlistBreak[] {
	if (typeof window === 'undefined' || libraryId < 0 || setlistId <= 0) return []
	try {
		const raw = window.localStorage.getItem(getStorageKey(libraryId, setlistId))
		if (!raw) return []
		return normalizeSetlistBreaks(JSON.parse(raw))
	} catch {
		return []
	}
}

/**
 * Persist break markers for a setlist to localStorage.
 *
 * @param libraryId Library ID
 * @param setlistId Setlist ID
 * @param breaks Break markers to store
 */
export function saveSetlistBreaks(libraryId: number, setlistId: number, breaks: readonly SetlistBreak[]): void {
	if (typeof window === 'undefined' || libraryId < 0 || setlistId <= 0) return
	try {
		const normalized = normalizeSetlistBreaks(breaks)
		if (normalized.length === 0) {
			window.localStorage.removeItem(getStorageKey(libraryId, setlistId))
		} else {
			window.localStorage.setItem(getStorageKey(libraryId, setlistId), JSON.stringify(normalized))
		}
	} catch {
		// Ignore quota / storage errors
	}
}

/**
 * Remap break indices after removing a setlist item at `removedIndex`.
 *
 * @param breaks Existing break markers
 * @param removedIndex 0-based index of removed item
 * @param newItemCount Remaining item count after removal
 */
export function remapBreaksOnRemove(
	breaks: readonly SetlistBreak[],
	removedIndex: number,
	newItemCount: number,
): SetlistBreak[] {
	const mapped: SetlistBreak[] = []
	for (const brk of breaks) {
		if (brk.afterIndex === removedIndex) continue
		const nextIndex = brk.afterIndex > removedIndex ? brk.afterIndex - 1 : brk.afterIndex
		if (nextIndex >= 0 && nextIndex < newItemCount - 1) {
			mapped.push({ ...brk, afterIndex: nextIndex })
		}
	}
	return normalizeSetlistBreaks(mapped, newItemCount)
}

/**
 * Compute song duration, break duration, total duration, remaining duration from `currentIndex`,
 * and per-set segment subtotals.
 *
 * @param items Setlist items with optional `duration` in seconds
 * @param breaks Break markers placed after specific item indices
 * @param currentIndex Current 0-based performance item index (for remaining time)
 */
export function computeSetlistTiming(
	items: readonly Pick<SetlistItem, 'duration'>[],
	breaks: readonly SetlistBreak[],
	currentIndex = 0,
): SetlistTimingSummary {
	const validBreaks = normalizeSetlistBreaks(breaks, items.length)
	const breakByIndex = new Map<number, SetlistBreak>(validBreaks.map((b) => [b.afterIndex, b]))

	let songSeconds = 0
	let breakSeconds = 0
	let remainingSeconds = 0
	let missingDurationCount = 0

	const segments: SetlistSegment[] = []
	let segStart = 0
	let segSeconds = 0

	for (let i = 0; i < items.length; i++) {
		const dur = items[i]?.duration && items[i].duration! > 0 ? Math.round(items[i].duration!) : 0
		if (dur > 0) {
			songSeconds += dur
			segSeconds += dur
			if (i >= currentIndex) {
				remainingSeconds += dur
			}
		} else {
			missingDurationCount++
		}

		const brk = breakByIndex.get(i) ?? null
		if (brk) {
			const brkSec = brk.durationMinutes * 60
			breakSeconds += brkSec
			if (i >= currentIndex) {
				remainingSeconds += brkSec
			}
			segments.push({
				index: segments.length,
				startItemIndex: segStart,
				endItemIndex: i,
				songCount: i - segStart + 1,
				durationSeconds: segSeconds,
				breakAfter: brk,
			})
			segStart = i + 1
			segSeconds = 0
		}
	}

	if (items.length > 0 && segStart < items.length) {
		segments.push({
			index: segments.length,
			startItemIndex: segStart,
			endItemIndex: items.length - 1,
			songCount: items.length - segStart,
			durationSeconds: segSeconds,
			breakAfter: null,
		})
	}

	return {
		songSeconds,
		breakSeconds,
		totalSeconds: songSeconds + breakSeconds,
		remainingSeconds,
		missingDurationCount,
		segments,
	}
}

/**
 * Format seconds as compact `MM:SS` (or `H:MM:SS` when >= 1 hour) for countdowns and totals.
 *
 * @param seconds Non-negative duration in seconds
 */
export function formatStageDuration(seconds: number): string {
	const safe = Math.max(0, Math.round(seconds))
	if (safe < 3600) {
		return safe === 0 ? '0:00' : formatDuration(safe, '0:00')
	}
	const { hours, minutes, seconds: secs } = splitDurationHms(safe)
	return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`
}

/**
 * Format wall-clock time as `HH:MM`.
 *
 * @param date Date instance
 */
export function formatWallClock(date: Date): string {
	return `${date.getHours().toString().padStart(2, '0')}:${date.getMinutes().toString().padStart(2, '0')}`
}
