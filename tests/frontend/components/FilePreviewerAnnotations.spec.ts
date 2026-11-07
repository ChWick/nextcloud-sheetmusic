/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { SheetAnnotation } from '../../../src/types/annotation'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import FilePreviewer from '../../../src/components/FilePreviewer.vue'
import FilePreviewerToolbar from '../../../src/components/file-preview/FilePreviewerToolbar.vue'
import SongViewerFileToolbar from '../../../src/components/song-viewer/SongViewerFileToolbar.vue'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn().mockResolvedValue({ data: new ArrayBuffer(8) }),
	},
}))

vi.mock('pdfjs-dist', () => ({
	getDocument: vi.fn().mockReturnValue({
		promise: Promise.resolve({
			numPages: 2,
			getPage: vi.fn().mockResolvedValue({
				getViewport: vi.fn().mockReturnValue({ width: 600, height: 800 }),
				render: vi.fn().mockReturnValue({ promise: Promise.resolve() }),
			}),
		}),
	}),
	GlobalWorkerOptions: {},
}))

describe('Annotations Toggle Controls', () => {
	it('FilePreviewerToolbar renders annotation toggle button and emits toggleAnnotations', async () => {
		const wrapper = mount(FilePreviewerToolbar, {
			props: {
				isPdf: true,
				currentPage: 1,
				totalPages: 5,
				currentPageDisplay: '1',
				pageLayout: 'single',
				zoomSelectValue: 'fit-width',
				isCustomZoom: false,
				displayScale: 1.0,
				showAnnotations: true,
			},
		})

		const toggleBtn = wrapper.find('[data-test="toggle-annotations-button"]')
		expect(toggleBtn.exists()).toBe(true)
		expect(toggleBtn.attributes('title')).toBe('Hide annotations')

		await toggleBtn.trigger('click')
		expect(wrapper.emitted('toggleAnnotations')).toBeTruthy()

		// When showAnnotations is false
		await wrapper.setProps({ showAnnotations: false })
		expect(toggleBtn.attributes('title')).toBe('Show annotations')
	})

	it('SongViewerFileToolbar renders annotation toggle button and emits toggle-annotations', async () => {
		const wrapper = mount(SongViewerFileToolbar, {
			props: {
				file: {
					id: 1,
					songId: 10,
					path: 'scores/sonata.pdf',
					fileSize: 1024,
				} as any,
				isPdf: true,
				currentPage: 1,
				totalPages: 3,
				showAnnotations: true,
			},
		})

		const toggleBtn = wrapper.find('[data-test="toggle-annotations-button"]')
		expect(toggleBtn.exists()).toBe(true)
		expect(toggleBtn.attributes('title')).toBe('Hide annotations')

		await toggleBtn.trigger('click')
		expect(wrapper.emitted('toggle-annotations')).toBeTruthy()

		// When showAnnotations is false
		await wrapper.setProps({ showAnnotations: false })
		expect(toggleBtn.attributes('title')).toBe('Show annotations')
	})
})

describe('FilePreviewer.vue Annotation Rendering & Reactivity', () => {
	const sampleAnnotations: SheetAnnotation[] = [
		{
			id: 1,
			uuid: 'test-ann-page-1',
			songId: 10,
			fileId: 100,
			pageNumber: 1,
			layerIndex: 0,
			type: 'drawing',
			x: 0.1,
			y: 0.2,
			width: 0.3,
			height: 0.4,
			style: { stroke: '#ff0000', strokeWidth: 2 },
			data: { path: 'M 100 200 L 300 400' },
		},
	]

	it('renders annotation overlay SVG when mounted with annotations', async () => {
		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/dav/files/admin/Noten/piece.pdf',
				annotations: sampleAnnotations,
				showAnnotations: true,
			},
		})

		await flushPromises()

		const overlay = wrapper.find('.sheetmusic-annotation-overlay')
		expect(overlay.exists()).toBe(true)
		expect(overlay.attributes('style')).toContain('display: block')
		const path = overlay.find('path')
		expect(path.exists()).toBe(true)
		expect(path.attributes('d')).toBe('M 100 200 L 300 400')
	})

	it('dynamically attaches annotation overlay when annotations prop arrives after initial render', async () => {
		// Mount initially with empty annotations
		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/dav/files/admin/Noten/piece.pdf',
				annotations: [],
				showAnnotations: true,
			},
		})

		await flushPromises()
		expect(wrapper.find('.sheetmusic-annotation-overlay').exists()).toBe(false)

		// Async arrival of annotations
		await wrapper.setProps({ annotations: sampleAnnotations })
		await flushPromises()

		const overlay = wrapper.find('.sheetmusic-annotation-overlay')
		expect(overlay.exists()).toBe(true)
		expect(overlay.find('path').attributes('d')).toBe('M 100 200 L 300 400')
	})

	it('toggles overlay visibility when showAnnotations changes', async () => {
		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/dav/files/admin/Noten/piece.pdf',
				annotations: sampleAnnotations,
				showAnnotations: true,
			},
		})

		await flushPromises()
		const overlay = wrapper.find('.sheetmusic-annotation-overlay')
		expect(overlay.exists()).toBe(true)
		expect(overlay.attributes('style')).toContain('display: block')

		// Hide annotations
		await wrapper.setProps({ showAnnotations: false })
		await flushPromises()
		expect(overlay.attributes('style')).toContain('display: none')

		// Show annotations again
		await wrapper.setProps({ showAnnotations: true })
		await flushPromises()
		expect(overlay.attributes('style')).toContain('display: block')
	})
})
