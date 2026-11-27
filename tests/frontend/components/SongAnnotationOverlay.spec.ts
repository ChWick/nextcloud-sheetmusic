/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SongAnnotationOverlay from '../../../src/components/song-viewer/SongAnnotationOverlay.vue'
import { resetStylusSettingsStateForTests, useStylusSettings } from '../../../src/composables/useStylusSettings'
import type { SheetAnnotation } from '../../../src/types/annotation'

describe('SongAnnotationOverlay.vue', () => {
	beforeEach(() => {
		resetStylusSettingsStateForTests()
	})
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
			data: { symbol: '\uE52F', category: 'dynamic' },
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

		// SMuFL music stamp rendered with Bravura
		const stamp = wrapper.find('[data-test="annotation-stamp"]')
		expect(stamp.exists()).toBe(true)
		expect(stamp.text()).toBe('\uE52F')
		expect(stamp.attributes('font-family')).toContain('Bravura')

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

		expect(wrapper.emitted('jump-to-page')).toEqual([[{
			targetPage: 3,
			sourcePage: 0,
			targetX: undefined,
			targetY: undefined,
			label: 'To Coda',
		}]])
	})

	it('highlights the target point on the destination page when a link is selected in edit mode', () => {
		const link: SheetAnnotation = {
			id: 99,
			uuid: 'link-target-test',
			songId: 1,
			fileId: 1,
			pageNumber: 0,
			type: 'link',
			x: 0.2,
			y: 0.2,
			width: 0.1,
			height: 0.06,
			style: { color: '#0052cc', opacity: 1 },
			data: { targetPage: 2, targetX: 0.65, targetY: 0.4, label: 'To Coda' },
		}

		// Mount overlay for destination page 3 (1-indexed) with no annotations of its own
		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: [link],
				pageNumber: 3,
				visible: true,
				editable: true,
				selectedUuid: 'link-target-test',
			},
		})

		const marker = wrapper.find('[data-test="selected-link-target-marker"]')
		expect(marker.exists()).toBe(true)
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

		expect(stampWrapper.find('.sheetmusic-selection-box rect').exists()).toBe(true)
		expect(stampWrapper.find('.sheetmusic-selection-box circle').exists()).toBe(false)
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
				symbol: '\uE52F',
				category: 'dynamic',
			},
		})
	})

	it('clears selection and immediately inserts a new symbol on blank-page tap', async () => {
		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: sampleAnnotations,
				pageNumber: 1,
				pageWidth: 595,
				pageHeight: 842,
				visible: true,
				editable: true,
				activeTool: 'stamp',
				selectedUuid: 'ann-stamp-1',
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

		// Tapping blank page clears the old selection and inserts the new stamp immediately
		await svg.trigger('pointerdown', { button: 0, clientX: 100, clientY: 100, pointerId: 1 })
		await svg.trigger('pointerup', { button: 0, clientX: 100, clientY: 100, pointerId: 1 })
		expect(wrapper.emitted('select')).toEqual([[null]])
		expect(wrapper.emitted('create-annotation')).toHaveLength(1)
	})

	it('ignores fuzzy tablet/stylus e.target hits when exact pointer coordinates are outside a stamp bounding box', async () => {
		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: sampleAnnotations,
				pageNumber: 1,
				pageWidth: 1000,
				pageHeight: 1000,
				visible: true,
				editable: true,
				activeTool: 'select',
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

		// ann-stamp-1 is centered at (525, 325). Simulate browser touch-adjustment snapping e.target
		// to the stamp hit target even though the stylus coordinate is 30px outside at (560, 325).
		const stampHit = wrapper.find('rect[data-ann-uuid="ann-stamp-1"][data-test="annotation-hit-target"]')
		expect(stampHit.exists()).toBe(true)
		await stampHit.trigger('pointerdown', {
			button: 0,
			pointerType: 'pen',
			clientX: 560,
			clientY: 325,
			pointerId: 1,
		})
		expect(wrapper.emitted('select')?.at(-1)).toEqual([null])

		// Tapping directly inside the stamp's ink box (525, 325) selects it
		await stampHit.trigger('pointerdown', {
			button: 0,
			pointerType: 'pen',
			clientX: 525,
			clientY: 325,
			pointerId: 2,
		})
		expect(wrapper.emitted('select')?.at(-1)).toEqual(['ann-stamp-1'])
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

	it('renders variable-width filled path for pressure-bearing drawing annotations', () => {
		const pressureAnn: SheetAnnotation = {
			id: 12,
			uuid: 'ann-pressure-1',
			songId: 10,
			fileId: 20,
			pageNumber: 0,
			type: 'drawing',
			x: 0.1,
			y: 0.1,
			width: 0.3,
			height: 0.1,
			style: { stroke: '#1e88e5', strokeWidth: 4, lineStyle: 'solid' },
			data: {
				points: [
					{ x: 0.1, y: 0.1, p: 0.2 },
					{ x: 0.25, y: 0.15, p: 0.9 },
					{ x: 0.4, y: 0.12, p: 0.3 },
				],
			},
		}

		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: [pressureAnn],
				pageNumber: 1,
				pageWidth: 1000,
				pageHeight: 1000,
				visible: true,
			},
		})

		const path = wrapper.find('[data-pressure-stroke="true"]')
		expect(path.exists()).toBe(true)
		expect(path.attributes('fill')).toBe('#1e88e5')
		expect(path.attributes('stroke')).toBe('none')
		expect(path.attributes('d')?.endsWith(' Z')).toBe(true)
	})

	it('applies calibrated tip offset and captures pen hardware pressure', async () => {
		const { updateSettings } = useStylusSettings()
		updateSettings({ offsetX: 10, offsetY: -20, pressureMode: 'hardware', pressureSensitivity: 1 })

		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: [],
				pageNumber: 1,
				pageWidth: 1000,
				pageHeight: 1000,
				visible: true,
				editable: true,
				activeTool: 'drawing',
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

		await svg.trigger('pointerdown', {
			button: 0,
			pointerType: 'pen',
			pressure: 0.2,
			clientX: 100,
			clientY: 200,
			pointerId: 1,
		})
		await svg.trigger('pointermove', {
			button: 0,
			pointerType: 'pen',
			pressure: 0.8,
			clientX: 300,
			clientY: 250,
			pointerId: 1,
		})
		await svg.trigger('pointerup', {
			button: 0,
			pointerType: 'pen',
			pressure: 0.8,
			clientX: 300,
			clientY: 250,
			pointerId: 1,
		})

		const created = wrapper.emitted('create-annotation')?.[0]?.[0] as SheetAnnotation
		expect(created).toBeTruthy()
		expect(created.data.points?.[0].x).toBe(0.11) // (100 + 10) / 1000
		expect(created.data.points?.[0].y).toBe(0.18) // (200 - 20) / 1000
		expect(created.data.points?.[0].p).toBeLessThan(created.data.points?.[1].p ?? 0)
	})

	it('blocks touch drawing when stylus-only palm rejection is enabled', async () => {
		const { updateSettings } = useStylusSettings()
		updateSettings({ stylusOnlyDrawing: true })

		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: [],
				pageNumber: 1,
				pageWidth: 1000,
				pageHeight: 1000,
				visible: true,
				editable: true,
				activeTool: 'drawing',
			},
		})

		const svg = wrapper.find('[data-test="annotation-overlay"]')
		expect(svg.classes()).toContain('is-stylus-only')

		await svg.trigger('pointerdown', { button: 0, pointerType: 'touch', clientX: 100, clientY: 100, pointerId: 1 })
		await svg.trigger('pointermove', { button: 0, pointerType: 'touch', clientX: 200, clientY: 200, pointerId: 1 })
		await svg.trigger('pointerup', { button: 0, pointerType: 'touch', clientX: 200, clientY: 200, pointerId: 1 })

		expect(wrapper.emitted('create-annotation')).toBeFalsy()
	})

	it('erases an annotation when using the stylus hardware eraser end', async () => {
		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: sampleAnnotations,
				pageNumber: 1,
				visible: true,
				editable: true,
				activeTool: 'drawing',
			},
		})

		const stampEl = wrapper.find('[data-test="annotation-stamp"]')
		await stampEl.trigger('pointerdown', {
			pointerType: 'pen',
			button: 5,
			buttons: 32,
			pointerId: 1,
		})

		expect(wrapper.emitted('delete-annotation')?.[0]?.[0]).toMatchObject({ uuid: 'ann-stamp-1' })
	})

	it('ignores palm touch move/cancel events while an active pen stroke is in progress', async () => {
		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: [],
				pageNumber: 1,
				pageWidth: 1000,
				pageHeight: 1000,
				visible: true,
				editable: true,
				activeTool: 'drawing',
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

		await svg.trigger('pointerdown', {
			button: 0,
			pointerType: 'pen',
			pressure: 0.4,
			clientX: 100,
			clientY: 100,
			pointerId: 10,
		})
		// Palm touches and gets cancelled by the OS mid-stroke
		await svg.trigger('pointerdown', {
			button: 0,
			pointerType: 'touch',
			clientX: 600,
			clientY: 600,
			pointerId: 20,
		})
		await svg.trigger('pointermove', {
			button: 0,
			pointerType: 'touch',
			clientX: 620,
			clientY: 620,
			pointerId: 20,
		})
		await svg.trigger('pointercancel', {
			pointerType: 'touch',
			pointerId: 20,
		})
		// Pen continues and finishes the stroke
		await svg.trigger('pointermove', {
			button: 0,
			pointerType: 'pen',
			pressure: 0.6,
			clientX: 300,
			clientY: 200,
			pointerId: 10,
		})
		await svg.trigger('pointerup', {
			button: 0,
			pointerType: 'pen',
			pressure: 0.6,
			clientX: 300,
			clientY: 200,
			pointerId: 10,
		})

		const created = wrapper.emitted('create-annotation')?.[0]?.[0] as SheetAnnotation
		expect(created).toBeTruthy()
		expect(created.data.points?.length).toBe(2)
		expect(created.data.points?.[1].x).toBe(0.3)
		expect(created.data.points?.[1].y).toBe(0.2)
	})

	it('cancels an in-progress palm touch stroke when a pen hovers or touches down', async () => {
		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: [],
				pageNumber: 1,
				pageWidth: 1000,
				pageHeight: 1000,
				visible: true,
				editable: true,
				activeTool: 'drawing',
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

		// Palm hits the screen before pen has ever been detected
		await svg.trigger('pointerdown', {
			button: 0,
			pointerType: 'touch',
			clientX: 400,
			clientY: 400,
			pointerId: 30,
		})
		// Pen approaches and hovers over the canvas -> cancels the palm's touch stroke
		await svg.trigger('pointermove', {
			pointerType: 'pen',
			clientX: 200,
			clientY: 200,
			pointerId: 40,
		})
		// Palm lifts -> should NOT commit a 1-dot line
		await svg.trigger('pointerup', {
			button: 0,
			pointerType: 'touch',
			clientX: 400,
			clientY: 400,
			pointerId: 30,
		})

		expect(wrapper.emitted('create-annotation')).toBeFalsy()
	})

	it('highlights on hover, selects on click, and moves on drag over existing annotations in add/insert mode', async () => {
		const existingStamp: SheetAnnotation = {
			id: 50,
			uuid: 'stamp-existing',
			songId: 10,
			fileId: 20,
			pageNumber: 0,
			type: 'stamp',
			x: 0.4,
			y: 0.4,
			width: 0,
			height: 0,
			style: { color: '#000000', fontSize: 40, opacity: 1 },
			data: { symbol: '\uE522', category: 'dynamic' },
		}

		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: [existingStamp],
				pageNumber: 1,
				pageWidth: 1000,
				pageHeight: 1000,
				visible: true,
				editable: true,
				activeTool: 'stamp',
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

		const stampEl = wrapper.find('[data-test="annotation-stamp"]')
		expect(stampEl.exists()).toBe(true)

		// Hovering in insert mode highlights with the same select hover class
		await stampEl.trigger('pointermove', {
			pointerType: 'mouse',
			clientX: 400,
			clientY: 400,
		})
		expect(wrapper.find('g[data-ann-uuid="stamp-existing"]').classes()).toContain('is-hovered-select')
		expect(wrapper.find('g[data-ann-uuid="stamp-existing"]').classes()).not.toContain('is-hovered-eraser')

		// Click/tap on existing annotation selects it without creating a duplicate
		await stampEl.trigger('pointerdown', {
			button: 0,
			pointerType: 'mouse',
			clientX: 400,
			clientY: 400,
			pointerId: 1,
		})
		await svg.trigger('pointerup', {
			button: 0,
			pointerType: 'mouse',
			clientX: 400,
			clientY: 400,
			pointerId: 1,
		})
		expect(wrapper.emitted('select')?.[0]).toEqual(['stamp-existing'])
		expect(wrapper.emitted('create-annotation')).toBeFalsy()
		expect(wrapper.emitted('update-annotation')).toBeFalsy()

		// Click/tap and drag on existing annotation moves it
		await stampEl.trigger('pointerdown', {
			button: 0,
			pointerType: 'mouse',
			clientX: 400,
			clientY: 400,
			pointerId: 2,
		})
		await svg.trigger('pointermove', {
			button: 0,
			pointerType: 'mouse',
			clientX: 550,
			clientY: 500,
			pointerId: 2,
		})
		await svg.trigger('pointerup', {
			button: 0,
			pointerType: 'mouse',
			clientX: 550,
			clientY: 500,
			pointerId: 2,
		})

		expect(wrapper.emitted('create-annotation')).toBeFalsy()
		const updated = wrapper.emitted('update-annotation')?.[0]
		expect(updated).toBeTruthy()
		expect((updated?.[1] as SheetAnnotation).x).toBeCloseTo(0.55, 2)
		expect((updated?.[1] as SheetAnnotation).y).toBeCloseTo(0.5, 2)
	})

	it('adapts ink & shape fill lightness and switches highlight blend mode to screen in nightMode', () => {
		const annotations: SheetAnnotation[] = [
			{
				id: 91,
				uuid: 'night-rect',
				songId: 10,
				fileId: 20,
				pageNumber: 0,
				type: 'shape',
				x: 0.1,
				y: 0.1,
				width: 0.2,
				height: 0.1,
				style: { stroke: '#8b0000', fill: '#ffffff', strokeWidth: 2 },
				data: { shapeType: 'rect' },
			},
			{
				id: 92,
				uuid: 'night-hl',
				songId: 10,
				fileId: 20,
				pageNumber: 0,
				type: 'highlight',
				x: 0.3,
				y: 0.3,
				width: 0.2,
				height: 0.05,
				style: { stroke: '#ffeb3b', opacity: 0.4 },
				data: { rect: true },
			},
		]

		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations,
				pageNumber: 1,
				pageWidth: 1000,
				pageHeight: 1000,
				visible: true,
				nightMode: true,
			},
		})

		const shape = wrapper.find('[data-test="annotation-shape"]')
		// White-out fill flips to dark stage #141414; dark red stroke flips to bright red
		expect(shape.attributes('fill')).toBe('#141414')
		expect(shape.attributes('stroke')).not.toBe('#8b0000')

		const hl = wrapper.find('[data-test="annotation-drawing"]')
		expect(hl.attributes('fill')).toBe('#ffeb3b')
		expect(hl.attributes('style')).toContain('mix-blend-mode: screen')
	})
})
