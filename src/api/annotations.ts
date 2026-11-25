/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import type { SheetAnnotation } from '../types/annotation'
import type { LearnedHandwritingSample } from '../utils/handwritingModel'
import type { MusicFontId } from '../utils/musicFont'
import { isEffectivelyOffline } from '../composables/useOnlineStatus'
import { readOfflineCachedJson } from '../utils/offlineSetlists'
import { ocsDelete, ocsGet, ocsPost, ocsPut, ocsUrl } from './ocs'

interface BatchAnnotationsPayload {
	upsert?: Array<Partial<SheetAnnotation>>
	deleteIds?: number[]
}

interface BatchAnnotationsResult {
	saved: SheetAnnotation[]
	deletedIds: number[]
}

const EMPTY_BATCH_RESULT: BatchAnnotationsResult = { saved: [], deletedIds: [] }

function setlistFileAnnotationsPath(libraryId: number, setlistId: number, fileId: number): string {
	return `/api/libraries/${libraryId}/setlists/${setlistId}/files/${fileId}/annotations`
}

async function fetchAnnotationsWithOfflineCache(
	requestUrl: string,
	cacheUrl: string,
	params?: Record<string, unknown>,
): Promise<SheetAnnotation[]> {
	if (isEffectivelyOffline()) {
		const cached = await readOfflineCachedJson<{ ocs?: { data?: SheetAnnotation[] } }>(cacheUrl)
		if (cached?.ocs?.data) {
			return cached.ocs.data
		}
		throw new Error('Annotations are not available offline')
	}
	try {
		const response = await axios.get(requestUrl, params ? { params } : undefined)
		return response.data?.ocs?.data ?? []
	} catch (error) {
		const cached = await readOfflineCachedJson<{ ocs?: { data?: SheetAnnotation[] } }>(cacheUrl)
		if (cached?.ocs?.data) {
			return cached.ocs.data
		}
		throw error
	}
}

export async function fetchSongAnnotations(songId: number, fileId?: number | null): Promise<SheetAnnotation[]> {
	const baseUrl = ocsUrl(`/api/songs/${songId}/annotations`)
	const cacheUrl = fileId ? `${baseUrl}?fileId=${fileId}` : baseUrl
	return fetchAnnotationsWithOfflineCache(baseUrl, cacheUrl, fileId ? { fileId } : undefined)
}

export async function saveSongAnnotation(songId: number, annotation: Partial<SheetAnnotation>): Promise<SheetAnnotation> {
	return (await ocsPost<SheetAnnotation>(`/api/songs/${songId}/annotations`, annotation)) as SheetAnnotation
}

export async function batchSongAnnotations(
	songId: number,
	payload: BatchAnnotationsPayload,
): Promise<BatchAnnotationsResult> {
	return (await ocsPost<BatchAnnotationsResult>(`/api/songs/${songId}/annotations/batch`, payload)) ?? { ...EMPTY_BATCH_RESULT }
}

export async function deleteSongAnnotation(annotationId: number): Promise<void> {
	await ocsDelete(`/api/annotations/${annotationId}`)
}

export async function fetchSetlistFileAnnotations(
	libraryId: number,
	setlistId: number,
	fileId: number,
): Promise<SheetAnnotation[]> {
	const url = ocsUrl(setlistFileAnnotationsPath(libraryId, setlistId, fileId))
	return fetchAnnotationsWithOfflineCache(url, url)
}

export async function saveSetlistFileAnnotation(
	libraryId: number,
	setlistId: number,
	fileId: number,
	annotation: Partial<SheetAnnotation>,
): Promise<SheetAnnotation> {
	return (await ocsPost<SheetAnnotation>(setlistFileAnnotationsPath(libraryId, setlistId, fileId), annotation)) as SheetAnnotation
}

export async function batchSetlistFileAnnotations(
	libraryId: number,
	setlistId: number,
	fileId: number,
	payload: BatchAnnotationsPayload,
): Promise<BatchAnnotationsResult> {
	return (await ocsPost<BatchAnnotationsResult>(`${setlistFileAnnotationsPath(libraryId, setlistId, fileId)}/batch`, payload)) ?? { ...EMPTY_BATCH_RESULT }
}

export async function fetchHandwritingModel(): Promise<LearnedHandwritingSample[]> {
	const res = await ocsGet<{ samples?: LearnedHandwritingSample[] }>('/api/user/handwriting-model')
	return Array.isArray(res?.samples) ? res.samples : []
}

export async function saveHandwritingModel(samples: LearnedHandwritingSample[]): Promise<void> {
	await ocsPut('/api/user/handwriting-model', { samples })
}

export async function deleteHandwritingModel(): Promise<void> {
	await ocsDelete('/api/user/handwriting-model')
}

export async function fetchFileMusicFont(fileId: number): Promise<MusicFontId> {
	const res = await ocsGet<{ font?: MusicFontId }>(`/api/files/${fileId}/music-font`)
	return res?.font ?? 'bravura'
}

export async function saveFileMusicFont(fileId: number, font: MusicFontId): Promise<void> {
	await ocsPut(`/api/files/${fileId}/music-font`, { font })
}
