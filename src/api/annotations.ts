/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import type { SheetAnnotation } from '../types/annotation'

export async function fetchSongAnnotations(songId: number, fileId?: number | null): Promise<SheetAnnotation[]> {
	const url = generateOcsUrl('/apps/sheetmusic/api/songs/' + songId + '/annotations')
	const response = await axios.get(url, { params: fileId ? { fileId } : undefined })
	return response.data?.ocs?.data ?? []
}

export async function saveSongAnnotation(songId: number, annotation: Partial<SheetAnnotation>): Promise<SheetAnnotation> {
	const url = generateOcsUrl('/apps/sheetmusic/api/songs/' + songId + '/annotations')
	const response = await axios.post(url, annotation)
	return response.data?.ocs?.data
}

export async function deleteSongAnnotation(annotationId: number): Promise<void> {
	const url = generateOcsUrl('/apps/sheetmusic/api/annotations/' + annotationId)
	await axios.delete(url)
}
