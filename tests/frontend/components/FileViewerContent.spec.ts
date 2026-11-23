/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineComponent, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import FileViewerContent from '../../../src/components/file-preview/FileViewerContent.vue'

const toggleAnnotationsSpy = vi.fn()
const toggleEditModeSpy = vi.fn()
const exitEditModeSpy = vi.fn()

const AnnotatableFilePreviewStub = defineComponent({
	name: 'AnnotatableFilePreview',
	props: ['filePath', 'fileName', 'canAnnotate', 'annotations'],
	emits: ['update:annotations', 'update:is-editing'],
	setup() {
		const currentPage = ref(3)
		const totalPages = ref(8)
		const fileSizeBytes = ref(12345)
		const pageResolutions = ref([])
		const showAnnotationsState = ref(true)
		return {
			currentPage,
			totalPages,
			fileSizeBytes,
			pageResolutions,
			showAnnotationsState,
			toggleAnnotations: toggleAnnotationsSpy,
			toggleEditMode: toggleEditModeSpy,
			exitEditMode: exitEditModeSpy,
		}
	},
	template: '<div data-test="annotatable-preview-stub" />',
})

const SongViewerFileToolbarStub = defineComponent({
	name: 'SongViewerFileToolbar',
	props: ['file', 'isPdf', 'currentPage', 'totalPages', 'isFullscreen', 'showBack', 'showPlay'],
	emits: ['back', 'toggle-annotations', 'toggle-edit-annotations', 'toggle-fullscreen', 'play'],
	template: `
		<div data-test="toolbar-stub">
			<button data-test="tb-back" @click="$emit('back')" />
			<button data-test="tb-ann" @click="$emit('toggle-annotations')" />
			<button data-test="tb-edit" @click="$emit('toggle-edit-annotations')" />
			<button data-test="tb-fs" @click="$emit('toggle-fullscreen')" />
			<button data-test="tb-play" @click="$emit('play')" />
		</div>
	`,
})

describe('FileViewerContent.vue', () => {
	it('wires SongViewerFileToolbar and AnnotatableFilePreview and exposes currentPage & exitEditMode', async () => {
		toggleAnnotationsSpy.mockClear()
		toggleEditModeSpy.mockClear()
		exitEditModeSpy.mockClear()

		const wrapper = mount(FileViewerContent, {
			props: {
				file: { id: 1, path: 'notes.pdf', fileSize: 1024 },
				filePath: '/api/file/1',
				fileName: 'notes.pdf',
				isPdf: true,
				annotationContext: { libraryId: 1, setlistId: 2, setlistFileId: 1, fileId: 99 },
				showBack: true,
				showPlay: false,
			},
			global: {
				stubs: {
					AnnotatableFilePreview: AnnotatableFilePreviewStub,
					SongViewerFileToolbar: SongViewerFileToolbarStub,
				},
			},
		})

		const toolbar = wrapper.findComponent(SongViewerFileToolbarStub)
		expect(toolbar.props('showBack')).toBe(true)
		expect(toolbar.props('showPlay')).toBe(false)
		expect(wrapper.vm.currentPage).toBe(3)

		await wrapper.find('[data-test="tb-ann"]').trigger('click')
		await wrapper.find('[data-test="tb-edit"]').trigger('click')
		await wrapper.find('[data-test="tb-back"]').trigger('click')
		await wrapper.find('[data-test="tb-fs"]').trigger('click')
		await wrapper.find('[data-test="tb-play"]').trigger('click')

		expect(toggleAnnotationsSpy).toHaveBeenCalledTimes(1)
		expect(toggleEditModeSpy).toHaveBeenCalledTimes(1)
		expect(wrapper.emitted('back')).toHaveLength(1)
		expect(wrapper.emitted('toggle-fullscreen')).toHaveLength(1)
		expect(wrapper.emitted('play')).toHaveLength(1)

		wrapper.vm.exitEditMode()
		expect(exitEditModeSpy).toHaveBeenCalledTimes(1)
	})

	it('renders the #missing-file slot when filePath is empty', () => {
		const wrapper = mount(FileViewerContent, {
			props: {
				file: { id: 1, path: '' },
				filePath: '',
				isPdf: false,
				annotationContext: { songId: 1, fileId: 0 },
			},
			slots: {
				missingFile: '<div data-test="missing-slot">File missing</div>',
			},
			global: {
				stubs: {
					AnnotatableFilePreview: AnnotatableFilePreviewStub,
					SongViewerFileToolbar: SongViewerFileToolbarStub,
				},
			},
		})

		expect(wrapper.find('[data-test="annotatable-preview-stub"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="missing-slot"]').text()).toBe('File missing')
	})
})
