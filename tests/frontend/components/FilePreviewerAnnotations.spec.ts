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

	it('renders straight line shapes and note stamps accurately', async () => {
		const shapeAndStampAnns: SheetAnnotation[] = [
			{
				id: 20,
				uuid: 'line-shape-1',
				songId: 10,
				fileId: 100,
				pageNumber: 1,
				layerIndex: 0,
				type: 'shape',
				x: 0.1,
				y: 0.7,
				width: 0.2,
				height: 0.001,
				style: { stroke: '#000000', strokeWidth: 3 },
				data: {
					drawMode: 0,
					points: [{ x: 0.1, y: 0.7 }, { x: 0.3, y: 0.7 }],
				},
			},
			{
				id: 21,
				uuid: 'note-stamp-1',
				songId: 10,
				fileId: 100,
				pageNumber: 1,
				layerIndex: 0,
				type: 'stamp',
				x: 0.5,
				y: 0.4,
				width: 0.05,
				height: 0.05,
				style: { color: '#000000', fontSize: 68 },
				data: {
					symbol: 'q',
					displaySymbol: '♩',
				},
			},
		]

		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/dav/files/admin/Noten/piece.pdf',
				annotations: shapeAndStampAnns,
				showAnnotations: true,
			},
		})

		await flushPromises()
		const overlay = wrapper.find('.sheetmusic-annotation-overlay')
		expect(overlay.exists()).toBe(true)

		// Straight line should render as <path> not <rect>
		const linePath = overlay.find('[data-test="annotation-shape"]')
		expect(linePath.exists()).toBe(true)
		expect(linePath.element.tagName.toLowerCase()).toBe('path')
		expect(linePath.attributes('d')).toBe('M 100.0 700.0 L 300.0 700.0')

		// Note stamp should render as <text> centered at (x + w * 0.2, y + h * 0.25) with central baseline and music font-family
		const stampText = overlay.find('[data-test="annotation-stamp"]')
		expect(stampText.exists()).toBe(true)
		expect(stampText.text()).toBe('♩')
		expect(stampText.attributes('x')).toBe('510')
		expect(stampText.attributes('y')).toBe('412.5')
		expect(stampText.attributes('dominant-baseline')).toBe('central')
		expect(stampText.attributes('text-anchor')).toBe('middle')
		expect(stampText.attributes('font-family')).toContain('Bravura')
		expect(stampText.attributes('font-family')).toContain('Noto Music')
	})

	it('renders text annotations with hanging baseline, valid font family, and crescendo hairpins', async () => {
		const textAndCrescendoAnns: SheetAnnotation[] = [
			{
				id: 30,
				uuid: 'text-1',
				songId: 10,
				fileId: 100,
				pageNumber: 1,
				layerIndex: 0,
				type: 'text',
				x: 0.5,
				y: 0.6,
				width: 0.1,
				height: 0.05,
				style: { color: '#000000', fontSize: 60 },
				data: {
					text: 'foo',
					fontFamily: '0', // MobileSheets raw integer font family
					fontSize: 36,
				},
			},
			{
				id: 31,
				uuid: 'crescendo-1',
				songId: 10,
				fileId: 100,
				pageNumber: 1,
				layerIndex: 0,
				type: 'shape',
				x: 0.1,
				y: 0.7,
				width: 0.2,
				height: 0.03,
				style: { stroke: '#000000', strokeWidth: 2 },
				data: {
					drawMode: 4,
					points: [{ x: 0.1, y: 0.7 }, { x: 0.3, y: 0.7 }],
				},
			},
		]

		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/dav/files/admin/Noten/piece.pdf',
				annotations: textAndCrescendoAnns,
				showAnnotations: true,
			},
		})

		await flushPromises()
		const overlay = wrapper.find('.sheetmusic-annotation-overlay')
		expect(overlay.exists()).toBe(true)

		// Text should have hanging baseline, valid font-family (not "0"), and proper y coordinate
		const textEl = overlay.find('[data-test="annotation-text"]')
		expect(textEl.exists()).toBe(true)
		expect(textEl.text()).toBe('foo')
		expect(textEl.attributes('x')).toBe('500')
		expect(textEl.attributes('y')).toBe('600')
		expect(textEl.attributes('font-family')).toBe('sans-serif')
		expect(textEl.attributes('dominant-baseline')).toBe('hanging')
		expect(textEl.attributes('font-size')).toBe('60')

		// Crescendo should render as hairpin path (<)
		const hairpin = overlay.find('[data-test="annotation-shape"]')
		expect(hairpin.exists()).toBe(true)
		expect(hairpin.element.tagName.toLowerCase()).toBe('path')
		expect(hairpin.attributes('d')).toContain('L 100.0 700.0')
	})
})
