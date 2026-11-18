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
import SongAnnotationOverlay from '../../../src/components/song-viewer/SongAnnotationOverlay.vue'

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

		await wrapper.setProps({ showAnnotations: false })
		expect(toggleBtn.attributes('title')).toBe('Show annotations')
	})

	it('SongViewerFileToolbar renders annotation toggle and edit buttons', async () => {
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
			global: {
				stubs: {
					NcActions: {
						name: 'NcActions',
						template: '<div class="nc-actions"><slot /></div>',
					},
					NcActionButton: {
						name: 'NcActionButton',
						emits: ['click'],
						template: '<button type="button" class="nc-action-button" @click="$emit(\'click\')"><slot /><slot name="icon" /></button>',
					},
				},
			},
		})

		const editBtn = wrapper.find('[data-test="edit-annotations-button"]')
		expect(editBtn.exists()).toBe(true)
		await editBtn.trigger('click')
		expect(wrapper.emitted('toggle-edit-annotations')).toBeTruthy()

		const fullscreenBtn = wrapper.find('[data-test="viewer-fullscreen-button"]')
		expect(fullscreenBtn.exists()).toBe(true)
		await fullscreenBtn.trigger('click')
		expect(wrapper.emitted('toggle-fullscreen')).toBeTruthy()

		const toggleBtn = wrapper.find('[data-test="toggle-annotations-button"]')
		expect(toggleBtn.exists()).toBe(true)
		await toggleBtn.trigger('click')
		expect(wrapper.emitted('toggle-annotations')).toBeTruthy()
	})
})

describe('FilePreviewer.vue Annotation Rendering & Reactivity', () => {
	const sampleAnnotations: SheetAnnotation[] = [
		{
			id: 1,
			uuid: 'test-ann-page-0',
			songId: 10,
			fileId: 100,
			pageNumber: 0,
			layerIndex: 0,
			type: 'drawing',
			x: 0.1,
			y: 0.2,
			width: 0.3,
			height: 0.4,
			style: { stroke: '#ff0000', strokeWidth: 2 },
			data: {
				points: [
					{ x: 0.1, y: 0.15 },
					{ x: 0.3, y: 0.3 },
				],
			},
		},
	]

	it('renders isotropic annotation overlay SVG when mounted with annotations', async () => {
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
		expect(overlay.attributes('viewBox')).toBe('0 0 1000 1333.33')
		const path = overlay.find('path')
		expect(path.exists()).toBe(true)
		expect(path.attributes('d')).toBe('M 100.0 200.0 L 300.0 400.0')
	})

	it('dynamically attaches annotation overlay when annotations prop arrives after initial render', async () => {
		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/dav/files/admin/Noten/piece.pdf',
				annotations: [],
				showAnnotations: true,
			},
		})

		await flushPromises()
		expect(wrapper.find('.sheetmusic-annotation-overlay').exists()).toBe(false)

		await wrapper.setProps({ annotations: sampleAnnotations })
		await flushPromises()

		const overlay = wrapper.find('.sheetmusic-annotation-overlay')
		expect(overlay.exists()).toBe(true)
		expect(overlay.find('path').attributes('d')).toBe('M 100.0 200.0 L 300.0 400.0')
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
		expect(wrapper.find('.sheetmusic-annotation-overlay').exists()).toBe(true)

		await wrapper.setProps({ showAnnotations: false })
		await flushPromises()
		expect(wrapper.find('.sheetmusic-annotation-overlay').exists()).toBe(false)

		await wrapper.setProps({ showAnnotations: true })
		await flushPromises()
		expect(wrapper.find('.sheetmusic-annotation-overlay').exists()).toBe(true)
	})

	it('renders straight line shapes and Unicode note stamps in Noto Music', async () => {
		const shapeAndStampAnns: SheetAnnotation[] = [
			{
				id: 20,
				uuid: 'line-shape-1',
				songId: 10,
				fileId: 100,
				pageNumber: 0,
				layerIndex: 0,
				type: 'shape',
				x: 0.1,
				y: 0.6,
				width: 0.2,
				height: 0.001,
				style: { stroke: '#000000', strokeWidth: 3 },
				data: {
					shapeType: 'line',
					points: [{ x: 0.1, y: 0.6 }, { x: 0.3, y: 0.6 }],
				},
			},
			{
				id: 21,
				uuid: 'note-stamp-1',
				songId: 10,
				fileId: 100,
				pageNumber: 0,
				layerIndex: 0,
				type: 'stamp',
				x: 0.5,
				y: 0.4,
				width: 0.05,
				height: 0.05,
				style: { color: '#000000', fontSize: 40 },
				data: {
					symbol: '♩',
					category: 'rhythm',
					fontKind: 'music',
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

		const linePath = overlay.find('[data-test="annotation-shape"]')
		expect(linePath.exists()).toBe(true)
		expect(linePath.element.tagName.toLowerCase()).toBe('path')
		expect(linePath.attributes('d')).toBe('M 100.0 800.0 L 300.0 800.0')

		const stampText = overlay.find('[data-test="annotation-stamp"]')
		expect(stampText.exists()).toBe(true)
		expect(stampText.element.tagName.toLowerCase()).toBe('text')
		expect(stampText.text()).toBe('♩')
		expect(stampText.attributes('font-family')).toContain('Noto Music')
	})

	it('renders text annotations and crescendo hairpins', async () => {
		const textAndCrescendoAnns: SheetAnnotation[] = [
			{
				id: 30,
				uuid: 'text-1',
				songId: 10,
				fileId: 100,
				pageNumber: 0,
				layerIndex: 0,
				type: 'text',
				x: 0.5,
				y: 0.6,
				width: 0.1,
				height: 0.05,
				style: { color: '#112233', fontFamily: 'sans-serif', fontSize: 20 },
				data: { text: 'cresc.' },
			},
			{
				id: 31,
				uuid: 'cresc-1',
				songId: 10,
				fileId: 100,
				pageNumber: 0,
				layerIndex: 0,
				type: 'shape',
				x: 0.2,
				y: 0.5,
				width: 0.4,
				height: 0.02,
				style: { stroke: '#000000', strokeWidth: 2 },
				data: {
					shapeType: 'crescendo',
					points: [{ x: 0.2, y: 0.5 }, { x: 0.6, y: 0.5 }],
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
		const textEl = overlay.find('[data-test="annotation-text"]')
		expect(textEl.exists()).toBe(true)
		expect(textEl.text()).toBe('cresc.')

		const crescPath = overlay.find('[data-test="annotation-shape"]')
		expect(crescPath.exists()).toBe(true)
		expect(crescPath.attributes('d')).toContain('L 200.0 666.7 L')
	})

	it('renders arrow shapes as path with arrowhead', async () => {
		const arrowAnn: SheetAnnotation = {
			id: 40,
			uuid: 'arrow-1',
			songId: 10,
			fileId: 100,
			pageNumber: 0,
			layerIndex: 0,
			type: 'shape',
			x: 0.1,
			y: 0.1,
			width: 0.3,
			height: 0.3,
			style: { stroke: '#ff0000', strokeWidth: 3 },
			data: {
				shapeType: 'arrow',
				points: [{ x: 0.1, y: 0.1 }, { x: 0.4, y: 0.4 }],
			},
		}

		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/dav/files/admin/Noten/piece.pdf',
				annotations: [arrowAnn],
				showAnnotations: true,
			},
		})

		await flushPromises()
		const overlay = wrapper.find('.sheetmusic-annotation-overlay')
		const arrowPath = overlay.find('[data-test="annotation-shape"]')
		expect(arrowPath.exists()).toBe(true)
		expect(arrowPath.attributes('d')).toContain('M 100.0 133.3 L 400.0 533.3 M')
	})

	it('cancels in-progress touch drawing when a second touch pointer starts (pinch/pan gesture)', async () => {
		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: [],
				pageNumber: 0,
				pageWidth: 1000,
				pageHeight: 1333,
				visible: true,
				editable: true,
				activeTool: 'drawing',
				toolStyle: { stroke: '#e53935', strokeWidth: 3 },
			},
		})

		const overlay = wrapper.find('[data-test="annotation-overlay"]')
		const el = overlay.element as SVGSVGElement
		el.getBoundingClientRect = () => ({ left: 0, top: 0, width: 1000, height: 1333, right: 1000, bottom: 1333, x: 0, y: 0, toJSON: () => ({}) })

		const makePointerEvent = (type: string, pointerId: number, clientX: number, clientY: number) => {
			const evt = new Event(type, { bubbles: true, cancelable: true }) as any
			evt.pointerId = pointerId
			evt.pointerType = 'touch'
			evt.button = 0
			evt.clientX = clientX
			evt.clientY = clientY
			return evt
		}

		// Two-finger gesture should cancel drawing and not emit create-annotation
		el.dispatchEvent(makePointerEvent('pointerdown', 1, 100, 100))
		el.dispatchEvent(makePointerEvent('pointerdown', 2, 200, 100))
		el.dispatchEvent(makePointerEvent('pointermove', 1, 150, 150))
		el.dispatchEvent(makePointerEvent('pointerup', 1, 150, 150))
		el.dispatchEvent(makePointerEvent('pointerup', 2, 250, 150))
		await flushPromises()
		expect(wrapper.emitted('create-annotation')).toBeFalsy()

		// Single-finger draw after multi-touch ends should work normally
		el.dispatchEvent(makePointerEvent('pointerdown', 3, 100, 100))
		el.dispatchEvent(makePointerEvent('pointermove', 3, 200, 200))
		el.dispatchEvent(makePointerEvent('pointerup', 3, 200, 200))
		await flushPromises()
		expect(wrapper.emitted('create-annotation')).toHaveLength(1)
	})
})
