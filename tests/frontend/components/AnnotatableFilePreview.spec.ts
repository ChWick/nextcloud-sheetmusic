/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineComponent, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import AnnotatableFilePreview from '../../../src/components/file-preview/AnnotatableFilePreview.vue'
import type { SheetAnnotation } from '../../../src/types/annotation'

vi.mock('../../../src/api/annotations', () => ({
	saveSongAnnotation: vi.fn().mockImplementation(async (_songId: number, ann: SheetAnnotation) => ({ ...ann, id: 1 })),
	saveSetlistFileAnnotation: vi.fn().mockImplementation(async (_l: number, _s: number, _f: number, ann: SheetAnnotation) => ({ ...ann, id: 1 })),
	batchSongAnnotations: vi.fn().mockResolvedValue({ saved: [], deletedIds: [] }),
	batchSetlistFileAnnotations: vi.fn().mockResolvedValue({ saved: [], deletedIds: [] }),
	deleteSongAnnotation: vi.fn().mockResolvedValue(undefined),
}))

const toggleAnnotationsSpy = vi.fn()
const mockShowAnnotations = ref(true)

const FilePreviewerStub = defineComponent({
	name: 'FilePreviewer',
	props: ['filePath', 'fileName', 'annotations', 'editableAnnotations', 'activeAnnotationTool'],
	emits: ['select-annotation', 'create-annotation', 'update-annotation', 'delete-annotation'],
	setup() {
		const currentPage = ref(2)
		const totalPages = ref(5)
		const fileSizeBytes = ref(4096)
		const pageResolutions = ref([])
		return {
			currentPage,
			totalPages,
			fileSizeBytes,
			pageResolutions,
			showAnnotationsState: mockShowAnnotations,
			toggleAnnotations: () => {
				mockShowAnnotations.value = !mockShowAnnotations.value
				toggleAnnotationsSpy()
			},
		}
	},
	template: '<div data-test="file-previewer-stub" :data-editable="String(editableAnnotations)" />',
})

const SongAnnotationToolbarStub = defineComponent({
	name: 'SongAnnotationToolbar',
	props: ['activeTool', 'currentPage'],
	emits: ['done', 'update:active-tool'],
	template: '<div data-test="annotation-toolbar-stub"><button data-test="done-btn" @click="$emit(\'done\')" /></div>',
})

describe('AnnotatableFilePreview.vue', () => {
	it('renders FilePreviewer without toolbar initially and exposes viewer metrics', () => {
		mockShowAnnotations.value = true
		const wrapper = mount(AnnotatableFilePreview, {
			props: {
				filePath: '/files/test.pdf',
				fileName: 'test.pdf',
				annotationContext: { songId: 1, fileId: 10 },
			},
			global: {
				stubs: {
					FilePreviewer: FilePreviewerStub,
					SongAnnotationToolbar: SongAnnotationToolbarStub,
				},
			},
		})

		expect(wrapper.find('[data-test="file-previewer-stub"]').attributes('data-editable')).toBe('false')
		expect(wrapper.find('[data-test="annotation-toolbar-stub"]').exists()).toBe(false)
		expect(wrapper.vm.currentPage).toBe(2)
		expect(wrapper.vm.totalPages).toBe(5)
		expect(wrapper.vm.fileSizeBytes).toBe(4096)
	})

	it('enters edit mode, auto-shows hidden annotations, emits update:isEditing, and exits on done', async () => {
		mockShowAnnotations.value = false
		toggleAnnotationsSpy.mockClear()

		const wrapper = mount(AnnotatableFilePreview, {
			props: {
				filePath: '/files/test.pdf',
				annotationContext: { songId: 1, fileId: 10 },
				canAnnotate: true,
			},
			global: {
				stubs: {
					FilePreviewer: FilePreviewerStub,
					SongAnnotationToolbar: SongAnnotationToolbarStub,
				},
			},
		})

		wrapper.vm.enterEditMode()
		await wrapper.vm.$nextTick()

		expect(toggleAnnotationsSpy).toHaveBeenCalledTimes(1)
		expect(wrapper.find('[data-test="annotation-toolbar-stub"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="file-previewer-stub"]').attributes('data-editable')).toBe('true')
		expect(wrapper.emitted('update:isEditing')?.[0]).toEqual([true])

		await wrapper.find('[data-test="done-btn"]').trigger('click')
		expect(wrapper.find('[data-test="annotation-toolbar-stub"]').exists()).toBe(false)
		expect(wrapper.emitted('update:isEditing')?.[1]).toEqual([false])
	})

	it('prevents entering edit mode when canAnnotate is false', async () => {
		const wrapper = mount(AnnotatableFilePreview, {
			props: {
				filePath: '/files/test.pdf',
				annotationContext: { songId: 1, fileId: 10 },
				canAnnotate: false,
			},
			global: {
				stubs: {
					FilePreviewer: FilePreviewerStub,
					SongAnnotationToolbar: SongAnnotationToolbarStub,
				},
			},
		})

		wrapper.vm.enterEditMode()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-test="annotation-toolbar-stub"]').exists()).toBe(false)
		expect(wrapper.vm.isEditing).toBe(false)
	})

	it('syncs external annotations prop changes into FilePreviewer', async () => {
		const wrapper = mount(AnnotatableFilePreview, {
			props: {
				filePath: '/files/test.pdf',
				annotations: [],
				annotationContext: { songId: 1, fileId: 10 },
			},
			global: {
				stubs: {
					FilePreviewer: FilePreviewerStub,
					SongAnnotationToolbar: SongAnnotationToolbarStub,
				},
			},
		})

		const nextAnns: SheetAnnotation[] = [{
			id: 5,
			uuid: 'u-5',
			songId: 1,
			fileId: 10,
			pageNumber: 0,
			type: 'text',
			x: 0.2,
			y: 0.2,
			width: 0.1,
			height: 0.05,
			style: {},
			data: { text: 'rit.' },
		}]
		await wrapper.setProps({ annotations: nextAnns })
		expect(wrapper.findComponent(FilePreviewerStub).props('annotations')).toEqual(nextAnns)
	})
})
