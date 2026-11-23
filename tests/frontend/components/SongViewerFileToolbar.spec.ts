/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SongViewerFileToolbar from '../../../src/components/song-viewer/SongViewerFileToolbar.vue'

const stubs = {
	NcActions: {
		name: 'NcActions',
		template: '<div class="nc-actions" data-test="pdf-actions-menu"><slot /></div>',
	},
	NcActionButton: {
		name: 'NcActionButton',
		props: ['title'],
		emits: ['click'],
		template: '<button type="button" class="nc-action-button" :title="title" @click="$emit(\'click\')"><slot /></button>',
	},
	PdfInfoPopover: {
		name: 'PdfInfoPopover',
		props: ['formattedFileSize', 'totalPages'],
		template: '<div data-test="pdf-info-popover">{{ formattedFileSize }} / {{ totalPages }}</div>',
	},
	SongViewerBookmarksBar: {
		name: 'SongViewerBookmarksBar',
		props: ['bookmarks', 'activeBookmarkPage', 'canWrite'],
		emits: ['jump', 'edit'],
		template: '<div data-test="bookmarks-bar"><button data-test="bm-jump" @click="$emit(\'jump\', 3)" /><button data-test="bm-edit" @click="$emit(\'edit\')" /></div>',
	},
}

describe('SongViewerFileToolbar.vue', () => {
	const baseFile = {
		id: 1,
		path: 'scores/sonata.pdf',
		fileSize: 2048,
	}

	it('renders filename and single-file mode, or file selector when multiple files exist', async () => {
		const singleWrapper = mount(SongViewerFileToolbar, {
			props: { file: baseFile, isPdf: true },
			global: { stubs },
		})
		expect(singleWrapper.text()).toContain('sonata.pdf')
		expect(singleWrapper.find('[data-test="song-file-selector"]').exists()).toBe(false)

		const multiWrapper = mount(SongViewerFileToolbar, {
			props: {
				file: baseFile,
				files: [
					{ id: 1, path: 'scores/sonata.pdf' },
					{ id: 2, path: 'scores/sonata-part2.pdf' },
				],
				isPdf: true,
			},
			global: { stubs },
		})
		const select = multiWrapper.find('[data-test="song-file-selector"]')
		expect(select.exists()).toBe(true)
		await select.setValue('2')
		expect(multiWrapper.emitted('select-file')?.[0]).toEqual(['2'])
	})

	it('renders Back button only when showBack is true and emits back', async () => {
		const hiddenWrapper = mount(SongViewerFileToolbar, {
			props: { file: baseFile, isPdf: true },
			global: { stubs },
		})
		expect(hiddenWrapper.find('[data-test="viewer-back-button"]').exists()).toBe(false)

		const shownWrapper = mount(SongViewerFileToolbar, {
			props: { file: baseFile, isPdf: true, showBack: true, backLabel: 'Close preview' },
			global: { stubs },
		})
		const backBtn = shownWrapper.find('[data-test="viewer-back-button"]')
		expect(backBtn.exists()).toBe(true)
		expect(backBtn.attributes('title')).toBe('Close preview')
		await backBtn.trigger('click')
		expect(shownWrapper.emitted('back')).toHaveLength(1)
	})

	it('respects song-only capability flags and hides NcActions when no menu items apply', () => {
		const setlistPdfWrapper = mount(SongViewerFileToolbar, {
			props: {
				file: baseFile,
				isPdf: true,
				showPlay: false,
				showBookmarks: false,
				showModifyPdf: false,
				showVersions: false,
				showExternal: false,
			},
			global: { stubs },
		})
		expect(setlistPdfWrapper.find('[data-test="play-song-button"]').exists()).toBe(false)
		expect(setlistPdfWrapper.find('[data-test="bookmarks-bar"]').exists()).toBe(false)
		expect(setlistPdfWrapper.find('[data-test="edit-annotations-button"]').exists()).toBe(true)
		expect(setlistPdfWrapper.find('[data-test="viewer-fullscreen-button"]').exists()).toBe(true)
		expect(setlistPdfWrapper.find('[data-test="pdf-actions-menu"]').exists()).toBe(true)
		expect(setlistPdfWrapper.find('[data-test="toggle-annotations-button"]').exists()).toBe(true)
		expect(setlistPdfWrapper.findAll('.nc-action-button')).toHaveLength(1)

		const setlistDocxWrapper = mount(SongViewerFileToolbar, {
			props: {
				file: { id: 2, path: 'notes.docx', fileSize: 1024 },
				isPdf: false,
				showPlay: false,
				showBookmarks: false,
				showModifyPdf: false,
				showVersions: false,
				showExternal: false,
			},
			global: { stubs },
		})
		expect(setlistDocxWrapper.find('[data-test="edit-annotations-button"]').exists()).toBe(false)
		expect(setlistDocxWrapper.find('[data-test="pdf-actions-menu"]').exists()).toBe(false)
		expect(setlistDocxWrapper.find('[data-test="viewer-fullscreen-button"]').exists()).toBe(true)
	})

	it('hides write-only actions when canWrite is false and shows Merge PDFs only for 2+ PDFs', () => {
		const readOnlyWrapper = mount(SongViewerFileToolbar, {
			props: {
				file: baseFile,
				files: [
					{ id: 1, path: 'a.pdf' },
					{ id: 2, path: 'b.pdf' },
				],
				isPdf: true,
				canWrite: false,
			},
			global: { stubs },
		})
		expect(readOnlyWrapper.find('[data-test="edit-annotations-button"]').exists()).toBe(false)
		expect(readOnlyWrapper.find('[data-test="merge-pdfs-action"]').exists()).toBe(false)

		const writableMultiWrapper = mount(SongViewerFileToolbar, {
			props: {
				file: baseFile,
				files: [
					{ id: 1, path: 'a.pdf' },
					{ id: 2, path: 'b.pdf' },
				],
				isPdf: true,
				canWrite: true,
			},
			global: { stubs },
		})
		expect(writableMultiWrapper.find('[data-test="merge-pdfs-action"]').exists()).toBe(true)
	})

	it('updates titles for isEditingAnnotations, isFullscreen, and showAnnotations and emits all actions', async () => {
		const wrapper = mount(SongViewerFileToolbar, {
			props: {
				file: baseFile,
				files: [
					{ id: 1, path: 'a.pdf' },
					{ id: 2, path: 'b.pdf' },
				],
				isPdf: true,
				isEditingAnnotations: true,
				isFullscreen: true,
				showAnnotations: false,
			},
			global: { stubs },
		})

		expect(wrapper.find('[data-test="edit-annotations-button"]').attributes('title')).toBe('Exit annotation editor')
		expect(wrapper.find('[data-test="viewer-fullscreen-button"]').attributes('title')).toBe('Exit fullscreen')
		expect(wrapper.find('[data-test="toggle-annotations-button"]').attributes('title')).toBe('Show annotations')

		await wrapper.find('[data-test="play-song-button"]').trigger('click')
		await wrapper.find('[data-test="bm-jump"]').trigger('click')
		await wrapper.find('[data-test="bm-edit"]').trigger('click')
		await wrapper.find('[data-test="merge-pdfs-action"]').trigger('click')

		expect(wrapper.emitted('play')).toHaveLength(1)
		expect(wrapper.emitted('jump-to-bookmark')?.[0]).toEqual([3])
		expect(wrapper.emitted('edit-bookmarks')).toHaveLength(1)
		expect(wrapper.emitted('merge-pdfs')).toHaveLength(1)
	})
})
