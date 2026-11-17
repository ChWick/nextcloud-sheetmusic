/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, ref, watch } from 'vue'
import { fetchSetlistFileAnnotations } from '../api/annotations'
import { getSetlistFileContentUrl } from '../api/setlists'
import type { SheetAnnotation } from '../types/annotation'
import type { SetlistFile } from '../types/song'
import { isPdfFile } from '../utils/file'

export function useSetlistFileAnnotations(
	getTarget: () => {
		libraryId: number
		setlistId: number
		file: SetlistFile | null
		enabled?: boolean
	},
) {
	const annotations = ref<SheetAnnotation[]>([])

	const isPdf = computed(() => {
		const { file } = getTarget()
		return file ? isPdfFile(file.filename, file.mimeType) : false
	})

	const fileUrl = computed(() => {
		const { libraryId, setlistId, file } = getTarget()
		if (!file || libraryId <= 0 || setlistId <= 0) {
			return ''
		}
		return getSetlistFileContentUrl(libraryId, setlistId, file.id, file.updatedAt)
	})

	watch(
		() => {
			const target = getTarget()
			return [
				target.enabled ?? true,
				target.libraryId,
				target.setlistId,
				target.file?.id ?? 0,
				isPdf.value,
			] as const
		},
		async ([enabled, libraryId, setlistId, fileId, pdf]) => {
			if (!enabled || !pdf || libraryId <= 0 || setlistId <= 0 || fileId <= 0) {
				annotations.value = []
				return
			}
			try {
				annotations.value = await fetchSetlistFileAnnotations(libraryId, setlistId, fileId)
			} catch {
				annotations.value = []
			}
		},
		{ immediate: true },
	)

	return {
		annotations,
		isPdf,
		fileUrl,
	}
}
