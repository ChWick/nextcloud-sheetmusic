/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongAnnotationOverlay from '../../../src/components/song-viewer/SongAnnotationOverlay.vue'
import type { SheetAnnotation } from '../../../src/types/annotation'

describe('SongAnnotationOverlay.vue', () => {
	const sampleAnnotations: SheetAnnotation[] = [
		{
			id: 1,
			uuid: 'ann-drawing-1',
			songId: 10,
			fileId: 20,
			pageNumber: 0,
			layerIndex: 0,
			type: 'drawing',
			x: 0.1,
			y: 0.1,
			width: 0.4,
			height: 0.3,
			style: {
				stroke: '#ff0000',
				strokeWidth: 3,
				opacity: 0.9,
				blendMode: 'normal',
			},
			data: {
				points: [
					{ x: 0.1, y: 0.1 },
					{ x: 0.2, y: 0.2 },
					{ x: 0.3, y: 0.25 },
				],
			},
		},
		{
			id: 2,
			uuid: 'ann-highlight-1',
			songId: 10,
			fileId: 20,
			pageNumber: 0,
			layerIndex: 0,
			type: 'highlight',
			x: 0.1,
			y: 0.5,
			width: 0.8,
			height: 0.05,
			style: {
				stroke: '#ffff00',
				strokeWidth: 15,
				opacity: 0.5,
				blendMode: 'multiply',
			},
			data: {
				points: [
					{ x: 0.1, y: 0.5 },
					{ x: 0.9, y: 0.5 },
				],
			},
		},
		{
			id: 3,
			uuid: 'ann-text-1',
			songId: 10,
			fileId: 20,
			pageNumber: 0,
			layerIndex: 0,
			type: 'text',
			x: 0.15,
			y: 0.7,
			width: 0.2,
			height: 0.05,
			style: {
				color: '#0000ff',
				fontSize: 18,
				fontFamily: 'serif',
			},
			data: {
				text: 'Allegro vivace',
			},
		},
		{
			id: 4,
			uuid: 'ann-link-1',
			songId: 10,
			fileId: 20,
			pageNumber: 0,
			layerIndex: 0,
			type: 'link',
			x: 0.8,
			y: 0.85,
			width: 0.1,
			height: 0.08,
			style: {},
			data: {
				targetPage: 3,
				label: 'To Coda',
			},
		},
		{
			id: 5,
			uuid: 'ann-page2-1',
			songId: 10,
			fileId: 20,
			pageNumber: 1,
			layerIndex: 0,
			type: 'drawing',
			x: 0.2,
			y: 0.2,
			width: 0.1,
			height: 0.1,
			style: { stroke: '#00ff00' },
			data: {
				points: [
					{ x: 0.2, y: 0.2 },
					{ x: 0.25, y: 0.25 },
				],
			},
		},
		{
			id: 6,
			uuid: 'ann-stamp-1',
			songId: 10,
			fileId: 20,
			pageNumber: 0,
			layerIndex: 0,
			type: 'stamp',
			x: 0.5,
			y: 0.3,
			width: 0.05,
			height: 0.05,
			style: { color: '#d32f2f', fontSize: 36 },
			data: { symbol: '𝆑𝆑', category: 'dynamic', fontKind: 'music' },
		},
		{
			id: 7,
			uuid: 'ann-shape-ellipse',
			songId: 10,
			fileId: 20,
			pageNumber: 0,
			layerIndex: 0,
			type: 'shape',
			x: 0.3,
			y: 0.4,
			width: 0.1,
			height: 0.1,
			style: { stroke: '#1e88e5', strokeWidth: 2.5 },
			data: { shapeType: 'ellipse' },
		},
	]

	it('renders isotropic SVG viewBox and all annotation types for the specified page', () => {
		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: sampleAnnotations,
				pageNumber: 1, // 1-indexed PDF page -> matches pageNumber: 0
				pageWidth: 595,
				pageHeight: 842,
				visible: true,
			},
		})

		const svg = wrapper.find('[data-test="annotation-overlay"]')
		expect(svg.exists()).toBe(true)
		expect(svg.attributes('viewBox')).toBe('0 0 1000 1415.13')
		expect(svg.attributes('preserveAspectRatio')).toBe('xMidYMid meet')

		// Drawing + Highlight
		const drawings = wrapper.findAll('[data-test="annotation-drawing"]')
		expect(drawings.length).toBe(2)
		expect(drawings[0].attributes('stroke')).toBe('#ff0000')
		expect(drawings[1].attributes('stroke')).toBe('#ffff00')

		// Ellipse shape
		const ellipse = wrapper.find('ellipse[data-test="annotation-shape"]')
		expect(ellipse.exists()).toBe(true)

		// Text
		const textEl = wrapper.find('[data-test="annotation-text"]')
		expect(textEl.exists()).toBe(true)
		expect(textEl.text()).toContain('Allegro vivace')

		// Unicode music stamp rendered with Noto Music
		const stamp = wrapper.find('[data-test="annotation-stamp"]')
		expect(stamp.exists()).toBe(true)
		expect(stamp.text()).toBe('𝆑𝆑')
		expect(stamp.attributes('font-family')).toContain('Noto Music')

		// Link
		const link = wrapper.find('[data-test="annotation-link"]')
		expect(link.exists()).toBe(true)
	})

	it('emits jump-to-page event when a navigation link is clicked in view mode', async () => {
		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: sampleAnnotations,
				pageNumber: 1,
				visible: true,
				editable: false,
			},
		})

		const linkGroup = wrapper.find('.sheetmusic-annotation-link-group')
		await linkGroup.trigger('click')

		expect(wrapper.emitted('jump-to-page')).toEqual([[3]])
	})

	it('hides all markings when visible prop is false', () => {
		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: sampleAnnotations,
				pageNumber: 1,
				visible: false,
			},
		})

		expect(wrapper.find('[data-test="annotation-overlay"]').exists()).toBe(false)
	})

	it('filters out annotations for other pages and soft-deleted annotations', () => {
		const withDeleted: SheetAnnotation[] = [
			...sampleAnnotations,
			{
				...sampleAnnotations[0],
				uuid: 'deleted-ann',
				deletedAt: 1700000000,
			},
		]
		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: withDeleted,
				pageNumber: 2, // matches pageNumber: 1
				visible: true,
			},
		})

		const drawings = wrapper.findAll('[data-test="annotation-drawing"]')
		expect(drawings.length).toBe(1)
		expect(drawings[0].attributes('stroke')).toBe('#00ff00')
	})

	it('renders dashed lines and non-smooth linear strokes', () => {
		const dashedAnn: SheetAnnotation = {
			id: 10,
			uuid: 'ann-dashed',
			songId: 10,
			fileId: 20,
			pageNumber: 0,
			type: 'drawing',
			x: 0.1,
			y: 0.1,
			width: 0.4,
			height: 0.4,
			style: {
				stroke: '#0000ff',
				strokeWidth: 4,
				lineStyle: 'dashed',
			},
			data: {
				smooth: false,
				points: [
					{ x: 0.1, y: 0.1 },
					{ x: 0.2, y: 0.3 },
					{ x: 0.4, y: 0.2 },
				],
			},
		}

		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: [dashedAnn],
				pageNumber: 1,
				pageWidth: 1000,
				pageHeight: 1000,
				visible: true,
			},
		})

		const path = wrapper.find('[data-test="annotation-drawing"]')
		expect(path.attributes('stroke-dasharray')).toBe('10, 6')
		expect(path.attributes('d')).toBe('M 100.0 100.0 L 200.0 300.0 L 400.0 200.0')
	})

	it('renders centered selection ring without corner handles for stamps and corner handles for boxes', () => {
		const stampWrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: sampleAnnotations,
				pageNumber: 1,
				visible: true,
				editable: true,
				activeTool: 'select',
				selectedUuid: 'ann-stamp-1',
			},
		})

		expect(stampWrapper.find('.sheetmusic-selection-box circle').exists()).toBe(true)
		expect(stampWrapper.findAll('[data-resize-handle]').length).toBe(0)

		const rectWrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: sampleAnnotations,
				pageNumber: 1,
				visible: true,
				editable: true,
				activeTool: 'select',
				selectedUuid: 'ann-shape-ellipse',
			},
		})

		expect(rectWrapper.find('.sheetmusic-selection-box rect').exists()).toBe(true)
		expect(rectWrapper.findAll('[data-resize-handle]').length).toBe(4)
	})

	it('creates a stamp annotation on mouse pointerdown/pointerup when editable', async () => {
		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: [],
				pageNumber: 1,
				pageWidth: 595,
				pageHeight: 842,
				visible: true,
				editable: true,
				activeTool: 'stamp',
			},
		})

		const svg = wrapper.find('[data-test="annotation-overlay"]')
		vi.spyOn(svg.element, 'getBoundingClientRect').mockReturnValue({
			left: 0,
			top: 0,
			width: 595,
			height: 842,
			right: 595,
			bottom: 842,
			x: 0,
			y: 0,
			toJSON: () => ({}),
		})

		await svg.trigger('pointerdown', { button: 0, clientX: 297.5, clientY: 421, pointerId: 1 })
		await svg.trigger('pointerup', { button: 0, clientX: 297.5, clientY: 421, pointerId: 1 })

		const emitted = wrapper.emitted('create-annotation')
		expect(emitted).toBeTruthy()
		expect(emitted?.[0]?.[0]).toMatchObject({
			pageNumber: 0,
			type: 'stamp',
			data: {
				symbol: '𝆑𝆑',
				category: 'dynamic',
				fontKind: 'music',
			},
		})
	})

	it('deletes an annotation when clicked with the eraser tool', async () => {
		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: sampleAnnotations,
				pageNumber: 1,
				visible: true,
				editable: true,
				activeTool: 'eraser',
			},
		})

		const stampEl = wrapper.find('[data-test="annotation-stamp"]')
		await stampEl.trigger('pointerdown', { button: 0, pointerId: 1 })

		const emitted = wrapper.emitted('delete-annotation')
		expect(emitted).toBeTruthy()
		expect(emitted?.[0]?.[0]).toMatchObject({ uuid: 'ann-stamp-1' })
	})

	it('renders scale-independent hit targets and highlights shapes on hover in eraser/select mode', async () => {
		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: sampleAnnotations,
				pageNumber: 1,
				visible: true,
				editable: true,
				activeTool: 'eraser',
			},
		})

		const hitTargets = wrapper.findAll('[data-test="annotation-hit-target"]')
		expect(hitTargets.length).toBeGreaterThan(0)
		expect(hitTargets[0].attributes('vector-effect')).toBe('non-scaling-stroke')
		expect(hitTargets[0].attributes('stroke-width')).toBe('14')

		const ellipseHitTarget = wrapper.find('ellipse[data-test="annotation-hit-target"]')
		expect(ellipseHitTarget.exists()).toBe(true)
		await ellipseHitTarget.trigger('pointermove', { clientX: 100, clientY: 100 })

		const hoveredGroup = wrapper.find('g[data-ann-uuid="ann-shape-ellipse"]')
		expect(hoveredGroup.classes()).toContain('is-hovered-eraser')

		await ellipseHitTarget.trigger('pointerdown', { button: 0, pointerId: 1 })
		expect(wrapper.emitted('delete-annotation')?.[0]?.[0]).toMatchObject({ uuid: 'ann-shape-ellipse' })
	})

	it('renders height handle for selected hairpin and updates aperture on drag', async () => {
		const crescAnn: SheetAnnotation = {
			id: 8,
			uuid: 'ann-cresc-1',
			songId: 10,
			fileId: 20,
			pageNumber: 0,
			type: 'shape',
			x: 0.1,
			y: 0.48,
			width: 0.4,
			height: 0.04,
			style: { stroke: '#e53935', strokeWidth: 2.5 },
			data: {
				shapeType: 'crescendo',
				points: [{ x: 0.1, y: 0.5 }, { x: 0.5, y: 0.5 }],
				aperture: 18,
			},
		}

		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: [crescAnn],
				pageNumber: 1,
				pageWidth: 1000,
				pageHeight: 1000,
				visible: true,
				editable: true,
				activeTool: 'select',
				selectedUuid: 'ann-cresc-1',
			},
		})

		const svg = wrapper.find('[data-test="annotation-overlay"]')
		vi.spyOn(svg.element, 'getBoundingClientRect').mockReturnValue({
			left: 0,
			top: 0,
			width: 1000,
			height: 1000,
			right: 1000,
			bottom: 1000,
			x: 0,
			y: 0,
			toJSON: () => ({}),
		})

		const heightHandle = wrapper.find('[data-resize-handle="height"]')
		expect(heightHandle.exists()).toBe(true)

		await heightHandle.trigger('pointerdown', { button: 0, clientX: 500, clientY: 518, pointerId: 1 })
		await svg.trigger('pointermove', { button: 0, clientX: 500, clientY: 545, pointerId: 1 })
		await svg.trigger('pointerup', { button: 0, clientX: 500, clientY: 545, pointerId: 1 })

		const updated = wrapper.emitted('update-annotation')
		expect(updated).toBeTruthy()
		expect((updated?.[0]?.[1] as SheetAnnotation).data.aperture).toBe(45)
	})
})
