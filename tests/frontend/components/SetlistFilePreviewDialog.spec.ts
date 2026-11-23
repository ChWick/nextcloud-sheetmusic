/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineComponent } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SetlistFilePreviewDialog from '../../../src/components/setlists/SetlistFilePreviewDialog.vue'
import type { SetlistFile } from '../../../src/types/song'

const mockFetchSetlistFileAnnotations = vi.fn().mockResolvedValue([])

vi.mock('../../../src/api/annotations', () => ({
	fetchSetlistFileAnnotations: (...args: unknown[]) => mockFetchSetlistFileAnnotations(...args),
}))

vi.mock('../../../src/api/setlists', () => ({
	getSetlistFileContentUrl: (libId: number, setlistId: number, fileId: number) =>
		`/api/libraries/${libId}/setlists/${setlistId}/files/${fileId}/content`,
}))

const exitEditModeSpy = vi.fn()

const FileViewerContentStub = defineComponent({
	name: 'FileViewerContent',
	props: [
		'file', 'filePath', 'fileName', 'isPdf', 'annotations', 'annotationContext',
		'canWrite', 'isFullscreen', 'showPlay', 'showBookmarks', 'showModifyPdf',
		'showVersions', 'showExternal', 'showBack', 'backLabel',
	],
	emits: ['back', 'update:annotations', 'toggle-fullscreen'],
	setup() {
		return { exitEditMode: exitEditModeSpy }
	},
	template: `
		<div data-test="file-viewer-content-stub" :data-fullscreen="String(isFullscreen)">
			<button data-test="stub-back" @click="$emit('back')" />
			<button data-test="stub-fullscreen" @click="$emit('toggle-fullscreen')" />
		</div>
	`,
})

function makeFile(overrides: Partial<SetlistFile> = {}): SetlistFile {
	return {
		id: 7,
		setlistId: 3,
		libraryId: 1,
		ocFileId: 555,
		path: 'setlists/notes.pdf',
		filename: 'notes.pdf',
		mimeType: 'application/pdf',
		fileSize: 4096,
		sortOrder: 0,
		createdAt: 100,
		updatedAt: 200,
		...overrides,
	}
}

describe('SetlistFilePreviewDialog.vue', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		document.body.innerHTML = ''
		document.body.className = ''
	})

	it('teleports dialog to body and passes setlist-scoped props to FileViewerContent', async () => {
		const wrapper = mount(SetlistFilePreviewDialog, {
			props: {
				libraryId: 1,
				setlistId: 3,
				file: makeFile(),
				canWrite: true,
			},
			global: {
				stubs: {
					Teleport: { template: '<div><slot /></div>' },
					FileViewerContent: FileViewerContentStub,
				},
			},
		})
		await flushPromises()

		const dialog = wrapper.find('[role="dialog"]')
		expect(dialog.exists()).toBe(true)
		expect(dialog.attributes('aria-modal')).toBe('true')
		expect(dialog.attributes('aria-label')).toBe('notes.pdf')

		const viewer = wrapper.findComponent(FileViewerContentStub)
		expect(viewer.props('isPdf')).toBe(true)
		expect(viewer.props('showBack')).toBe(true)
		expect(viewer.props('showPlay')).toBe(false)
		expect(viewer.props('showBookmarks')).toBe(false)
		expect(viewer.props('showModifyPdf')).toBe(false)
		expect(viewer.props('showVersions')).toBe(false)
		expect(viewer.props('showExternal')).toBe(false)
		expect(viewer.props('annotationContext')).toEqual({
			libraryId: 1,
			setlistId: 3,
			setlistFileId: 7,
			fileId: 555,
		})
	})

	it('closes on Back button, backdrop click, and Escape key', async () => {
		const wrapper = mount(SetlistFilePreviewDialog, {
			props: { libraryId: 1, setlistId: 3, file: makeFile() },
			global: {
				stubs: {
					Teleport: { template: '<div><slot /></div>' },
					FileViewerContent: FileViewerContentStub,
				},
			},
		})

		await wrapper.find('[data-test="stub-back"]').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(exitEditModeSpy).toHaveBeenCalledTimes(1)

		await wrapper.find('[role="dialog"]').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(2)

		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
		expect(wrapper.emitted('close')).toHaveLength(3)

		// Default-prevented Escape should be ignored
		const prevented = new KeyboardEvent('keydown', { key: 'Escape', cancelable: true })
		prevented.preventDefault()
		window.dispatchEvent(prevented)
		expect(wrapper.emitted('close')).toHaveLength(3)

		wrapper.unmount()
	})

	it('toggles fullscreen state and exits fullscreen when closing', async () => {
		const wrapper = mount(SetlistFilePreviewDialog, {
			props: { libraryId: 1, setlistId: 3, file: makeFile() },
			global: {
				stubs: {
					Teleport: { template: '<div><slot /></div>' },
					FileViewerContent: FileViewerContentStub,
				},
			},
		})

		expect(wrapper.find('[data-test="file-viewer-content-stub"]').attributes('data-fullscreen')).toBe('false')

		await wrapper.find('[data-test="stub-fullscreen"]').trigger('click')
		expect(wrapper.find('[data-test="file-viewer-content-stub"]').attributes('data-fullscreen')).toBe('true')
		expect(document.body.classList.contains('sheetmusic-fullscreen-active')).toBe(true)

		await wrapper.find('[data-test="stub-back"]').trigger('click')
		expect(document.body.classList.contains('sheetmusic-fullscreen-active')).toBe(false)
		wrapper.unmount()
	})

	it('exits edit mode when switching to a different file', async () => {
		const wrapper = mount(SetlistFilePreviewDialog, {
			props: { libraryId: 1, setlistId: 3, file: makeFile({ id: 7 }) },
			global: {
				stubs: {
					Teleport: { template: '<div><slot /></div>' },
					FileViewerContent: FileViewerContentStub,
				},
			},
		})

		await wrapper.setProps({ file: makeFile({ id: 8, filename: 'other.pdf' }) })
		expect(exitEditModeSpy).toHaveBeenCalledTimes(1)
		wrapper.unmount()
	})
})
