/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineComponent, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useSetlistFileAnnotations } from '../../../src/composables/useSetlistFileAnnotations'
import type { SetlistFile } from '../../../src/types/song'
import type { SheetAnnotation } from '../../../src/types/annotation'

const mockFetchSetlistFileAnnotations = vi.fn()
const mockGetSetlistFileContentUrl = vi.fn(
	(libraryId: number, setlistId: number, fileId: number, updatedAt?: number) =>
		`/api/libraries/${libraryId}/setlists/${setlistId}/files/${fileId}/content?v=${updatedAt ?? 0}`,
)

vi.mock('../../../src/api/annotations', () => ({
	fetchSetlistFileAnnotations: (...args: unknown[]) => mockFetchSetlistFileAnnotations(...args),
}))

vi.mock('../../../src/api/setlists', () => ({
	getSetlistFileContentUrl: (libId: number, setlistId: number, fileId: number, updatedAt?: number) =>
		mockGetSetlistFileContentUrl(libId, setlistId, fileId, updatedAt),
}))

function createFile(overrides: Partial<SetlistFile> = {}): SetlistFile {
	return {
		id: 5,
		setlistId: 10,
		libraryId: 1,
		ocFileId: 999,
		path: 'setlists/notes.pdf',
		filename: 'notes.pdf',
		mimeType: 'application/pdf',
		fileSize: 2048,
		sortOrder: 0,
		createdAt: 100,
		updatedAt: 200,
		...overrides,
	}
}

function mountComposable(initial: {
	libraryId: number
	setlistId: number
	file: SetlistFile | null
	enabled?: boolean
}) {
	const libraryId = ref(initial.libraryId)
	const setlistId = ref(initial.setlistId)
	const file = ref<SetlistFile | null>(initial.file)
	const enabled = ref(initial.enabled ?? true)

	let result!: ReturnType<typeof useSetlistFileAnnotations>
	const Comp = defineComponent({
		setup() {
			result = useSetlistFileAnnotations(() => ({
				libraryId: libraryId.value,
				setlistId: setlistId.value,
				file: file.value,
				enabled: enabled.value,
			}))
			return () => null
		},
	})

	const wrapper = mount(Comp)
	return { wrapper, result, libraryId, setlistId, file, enabled }
}

describe('useSetlistFileAnnotations', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('fetches annotations and builds fileUrl for a PDF file', async () => {
		const sampleAnn: SheetAnnotation = {
			id: 1,
			uuid: 'u-1',
			songId: 0,
			fileId: 999,
			pageNumber: 0,
			type: 'drawing',
			x: 0.1,
			y: 0.1,
			width: 0.2,
			height: 0.2,
			style: {},
			data: {},
		}
		mockFetchSetlistFileAnnotations.mockResolvedValueOnce([sampleAnn])

		const { result } = mountComposable({
			libraryId: 2,
			setlistId: 10,
			file: createFile({ id: 5, updatedAt: 42 }),
		})
		await flushPromises()

		expect(result.isPdf.value).toBe(true)
		expect(result.fileUrl.value).toBe('/api/libraries/2/setlists/10/files/5/content?v=42')
		expect(mockFetchSetlistFileAnnotations).toHaveBeenCalledWith(2, 10, 5)
		expect(result.annotations.value).toEqual([sampleAnn])
	})

	it('detects PDF by MIME type even without .pdf extension', async () => {
		mockFetchSetlistFileAnnotations.mockResolvedValueOnce([])
		const { result } = mountComposable({
			libraryId: 1,
			setlistId: 10,
			file: createFile({ filename: 'program-sheet', mimeType: 'application/pdf' }),
		})
		await flushPromises()

		expect(result.isPdf.value).toBe(true)
		expect(mockFetchSetlistFileAnnotations).toHaveBeenCalledTimes(1)
	})

	it('skips fetching and clears annotations for non-PDF files', async () => {
		const { result } = mountComposable({
			libraryId: 1,
			setlistId: 10,
			file: createFile({ filename: 'schedule.docx', mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' }),
		})
		await flushPromises()

		expect(result.isPdf.value).toBe(false)
		expect(mockFetchSetlistFileAnnotations).not.toHaveBeenCalled()
		expect(result.annotations.value).toEqual([])
	})

	it('skips fetching when disabled or when IDs/file are invalid', async () => {
		const { result, enabled, file } = mountComposable({
			libraryId: 1,
			setlistId: 10,
			file: createFile(),
			enabled: false,
		})
		await flushPromises()
		expect(mockFetchSetlistFileAnnotations).not.toHaveBeenCalled()
		expect(result.annotations.value).toEqual([])

		enabled.value = true
		file.value = null
		await flushPromises()
		expect(mockFetchSetlistFileAnnotations).not.toHaveBeenCalled()
		expect(result.fileUrl.value).toBe('')
		expect(result.isPdf.value).toBe(false)
	})

	it('refetches when file changes and clears annotations on fetch error', async () => {
		mockFetchSetlistFileAnnotations
			.mockResolvedValueOnce([{ id: 1, uuid: 'a1' }])
			.mockRejectedValueOnce(new Error('network error'))

		const { result, file } = mountComposable({
			libraryId: 1,
			setlistId: 10,
			file: createFile({ id: 5 }),
		})
		await flushPromises()
		expect(result.annotations.value).toHaveLength(1)

		file.value = createFile({ id: 6 })
		await flushPromises()
		expect(mockFetchSetlistFileAnnotations).toHaveBeenCalledWith(1, 10, 6)
		expect(result.annotations.value).toEqual([])
	})
})
