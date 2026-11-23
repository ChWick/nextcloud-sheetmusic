/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineComponent } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SetlistPerformanceSupplementalDrawer from '../../../src/components/setlists/SetlistPerformanceSupplementalDrawer.vue'
import type { SetlistFile } from '../../../src/types/song'

const mockFetchSetlistFileAnnotations = vi.fn().mockResolvedValue([])

vi.mock('../../../src/api/annotations', () => ({
	fetchSetlistFileAnnotations: (...args: unknown[]) => mockFetchSetlistFileAnnotations(...args),
}))

vi.mock('../../../src/api/setlists', () => ({
	getSetlistFileContentUrl: (libId: number, setlistId: number, fileId: number) =>
		`/api/libraries/${libId}/setlists/${setlistId}/files/${fileId}/content`,
}))

const FilePreviewerStub = defineComponent({
	name: 'FilePreviewer',
	props: ['filePath', 'fileName', 'annotations', 'hideToolbar', 'showExternalButton', 'noPadding'],
	template: `
		<div data-test="previewer-stub" :data-hide-toolbar="String(hideToolbar)" :data-file-name="fileName">
			<button type="button" data-test="inner-toolbar-btn">Zoom</button>
		</div>
	`,
})

function makeFile(id: number, filename: string): SetlistFile {
	return {
		id,
		setlistId: 10,
		libraryId: 1,
		ocFileId: 1000 + id,
		path: `setlists/${filename}`,
		filename,
		mimeType: filename.endsWith('.pdf') ? 'application/pdf' : 'application/octet-stream',
		fileSize: 2048,
		sortOrder: id,
		createdAt: 100,
		updatedAt: 200,
	}
}

describe('SetlistPerformanceSupplementalDrawer.vue', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('does not render when closed and renders drawer + tabs when open with multiple files', async () => {
		const files = [makeFile(1, 'notes.pdf'), makeFile(2, 'stageplot.pdf')]
		const wrapper = mount(SetlistPerformanceSupplementalDrawer, {
			props: { open: false, libraryId: 1, setlistId: 10, files },
			global: { stubs: { FilePreviewer: FilePreviewerStub } },
		})

		expect(wrapper.find('[role="dialog"]').exists()).toBe(false)

		await wrapper.setProps({ open: true })
		await flushPromises()

		expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
		const tabs = wrapper.findAll('[role="tab"]')
		expect(tabs).toHaveLength(2)
		expect(wrapper.find('[data-test="previewer-stub"]').attributes('data-file-name')).toBe('notes.pdf')

		await tabs[1].trigger('click')
		expect(wrapper.find('[data-test="previewer-stub"]').attributes('data-file-name')).toBe('stageplot.pdf')
	})

	it('toggles PDF toolbar visibility on viewer body tap and ignores clicks on buttons', async () => {
		const wrapper = mount(SetlistPerformanceSupplementalDrawer, {
			props: { open: true, libraryId: 1, setlistId: 10, files: [makeFile(1, 'notes.pdf')] },
			global: { stubs: { FilePreviewer: FilePreviewerStub } },
		})

		// Hidden by default
		expect(wrapper.find('[data-test="previewer-stub"]').attributes('data-hide-toolbar')).toBe('true')

		// Tapping PDF area toggles toolbar on
		await wrapper.find('[data-test="previewer-stub"]').trigger('click')
		expect(wrapper.find('[data-test="previewer-stub"]').attributes('data-hide-toolbar')).toBe('false')

		// Clicking an interactive button inside the viewer should NOT toggle toolbar off
		await wrapper.find('[data-test="inner-toolbar-btn"]').trigger('click')
		expect(wrapper.find('[data-test="previewer-stub"]').attributes('data-hide-toolbar')).toBe('false')

		// Tapping PDF area again hides toolbar
		await wrapper.find('[data-test="previewer-stub"]').trigger('click')
		expect(wrapper.find('[data-test="previewer-stub"]').attributes('data-hide-toolbar')).toBe('true')
	})

	it('resets toolbar to hidden when reopened and closes on close button or backdrop click', async () => {
		const wrapper = mount(SetlistPerformanceSupplementalDrawer, {
			props: { open: true, libraryId: 1, setlistId: 10, files: [makeFile(1, 'notes.pdf')] },
			global: { stubs: { FilePreviewer: FilePreviewerStub } },
		})

		await wrapper.find('[data-test="previewer-stub"]').trigger('click')
		expect(wrapper.find('[data-test="previewer-stub"]').attributes('data-hide-toolbar')).toBe('false')

		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })
		expect(wrapper.find('[data-test="previewer-stub"]').attributes('data-hide-toolbar')).toBe('true')

		await wrapper.find('button[aria-label="Close"]').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
