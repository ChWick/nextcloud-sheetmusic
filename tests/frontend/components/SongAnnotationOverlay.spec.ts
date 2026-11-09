/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SongAnnotationOverlay from '../../../src/components/song-viewer/SongAnnotationOverlay.vue'
import type { SheetAnnotation } from '../../../src/types/annotation'

describe('SongAnnotationOverlay.vue', () => {
	const sampleAnnotations: SheetAnnotation[] = [
		{
			id: 1,
			uuid: 'ann-drawing-1',
			songId: 10,
			fileId: 20,
			pageNumber: 1,
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
				path: 'M 100 100 L 200 200 L 300 250',
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
			pageNumber: 1,
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
				path: 'M 100 500 L 900 500',
			},
		},
		{
			id: 3,
			uuid: 'ann-text-1',
			songId: 10,
			fileId: 20,
			pageNumber: 1,
			layerIndex: 0,
			type: 'text',
			x: 0.15,
			y: 0.7,
			width: 0.2,
			height: 0.05,
			style: {
				color: '#0000ff',
			},
			data: {
				text: 'Allegro vivace',
				fontSize: 18,
				fontFamily: 'serif',
			},
		},
		{
			id: 4,
			uuid: 'ann-link-1',
			songId: 10,
			fileId: 20,
			pageNumber: 1,
			layerIndex: 0,
			type: 'link',
			x: 0.8,
			y: 0.85,
			width: 0.1,
			height: 0.08,
			style: {},
			data: {
				targetPage: 3,
			},
		},
		{
			id: 5,
			uuid: 'ann-page2-1',
			songId: 10,
			fileId: 20,
			pageNumber: 2,
			layerIndex: 0,
			type: 'drawing',
			x: 0.2,
			y: 0.2,
			width: 0.1,
			height: 0.1,
			style: { stroke: '#00ff00' },
			data: { path: 'M 200 200 L 250 250' },
		},
		{
			id: 6,
			uuid: 'ann-stamp-1',
			songId: 10,
			fileId: 20,
			pageNumber: 1,
			layerIndex: 0,
			type: 'stamp',
			x: 0.3,
			y: 0.4,
			width: 0.05,
			height: 0.05,
			style: {
				color: '#000000',
				fontSize: 36,
			},
			data: {
				symbol: 'q',
				displaySymbol: '♩',
				size: 40,
			},
		},
	]

	it('renders annotations matching the specified page number', () => {
		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: sampleAnnotations,
				pageNumber: 1,
				visible: true,
			},
		})

		expect(wrapper.find('[data-test="annotation-overlay"]').exists()).toBe(true)

		const drawings = wrapper.findAll('[data-test="annotation-drawing"]')
		expect(drawings).toHaveLength(2) // 1 drawing + 1 highlight

		// Verify drawing attributes (Quadratic Bezier midpoint smoothed)
		expect(drawings[0].attributes('d')).toBe('M 100.0 100.0 Q 200.0 200.0, 250.0 225.0 L 300.0 250.0')
		expect(drawings[0].attributes('stroke')).toBe('#ff0000')

		// Verify highlight attributes
		expect(drawings[1].attributes('d')).toBe('M 100 500 L 900 500')
		expect(drawings[1].attributes('stroke')).toBe('#ffff00')

		// Verify text element
		const textEl = wrapper.find('[data-test="annotation-text"]')
		expect(textEl.exists()).toBe(true)
		expect(textEl.text()).toBe('Allegro vivace')
		expect(textEl.attributes('fill')).toBe('#0000ff')

		// Verify navigation link element
		const linkEl = wrapper.find('[data-test="annotation-link"]')
		expect(linkEl.exists()).toBe(true)
		expect(linkEl.attributes('x')).toBe('800') // 0.8 * 1000
		expect(linkEl.attributes('y')).toBe('850') // 0.85 * 1000

		// Verify stamp element renders as self-contained vector SVG centered on tap box
		const stampEl = wrapper.find('[data-test="annotation-stamp"]')
		expect(stampEl.exists()).toBe(true)
		expect(stampEl.element.tagName.toLowerCase()).toBe('svg')
		expect(stampEl.attributes('x')).toBe('300') // 0.3 * 1000
		expect(stampEl.attributes('y')).toBe('400') // 0.4 * 1000
		expect(stampEl.attributes('width')).toBe('50')
		expect(stampEl.attributes('height')).toBe('50')
		expect(stampEl.attributes('viewBox')).toBe('0 0 180 549')
		expect(stampEl.attributes('preserveAspectRatio')).toBe('xMidYMid meet')
		const pathEl = stampEl.find('path')
		expect(pathEl.exists()).toBe(true)
		expect(pathEl.attributes('fill')).toBe('#000000')
	})

	it('renders fallback text element for unmapped custom stamp symbols', () => {
		const unmappedStamp: SheetAnnotation[] = [
			{
				id: 99,
				uuid: 'ann-custom-stamp-1',
				songId: 10,
				fileId: 20,
				pageNumber: 1,
				layerIndex: 0,
				type: 'stamp',
				x: 0.5,
				y: 0.6,
				width: 0.05,
				height: 0.05,
				style: { color: '#ff0000', fontSize: 32 },
				data: { symbol: 'custom-mark' },
			},
		]

		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: unmappedStamp,
				pageNumber: 1,
				visible: true,
			},
		})

		const stampText = wrapper.find('[data-test="annotation-stamp"]')
		expect(stampText.exists()).toBe(true)
		expect(stampText.element.tagName.toLowerCase()).toBe('text')
		expect(stampText.text()).toBe('custom-mark')
		expect(stampText.attributes('x')).toBe('525') // (0.5 + 0.05/2) * 1000
		expect(stampText.attributes('y')).toBe('625') // (0.6 + 0.05/2) * 1000
		expect(stampText.attributes('text-anchor')).toBe('middle')
		expect(stampText.attributes('dominant-baseline')).toBe('central')
	})

	it('emits jump-to-page event when a navigation link is clicked', async () => {
		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: sampleAnnotations,
				pageNumber: 1,
				visible: true,
			},
		})

		const linkEl = wrapper.find('[data-test="annotation-link"]')
		await linkEl.trigger('click')

		expect(wrapper.emitted('jump-to-page')).toBeTruthy()
		expect(wrapper.emitted('jump-to-page')?.[0]).toEqual([3])
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

	it('filters out annotations for other pages', () => {
		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: sampleAnnotations,
				pageNumber: 5, // page with no annotations
				visible: true,
			},
		})

		expect(wrapper.find('[data-test="annotation-overlay"]').exists()).toBe(false)
	})

	it('renders dashed lines and handles linear non-smooth drawing mode', () => {
		const dashedAnnotations: SheetAnnotation[] = [
			{
				id: 10,
				uuid: 'ann-dashed-1',
				songId: 10,
				fileId: 20,
				pageNumber: 1,
				layerIndex: 0,
				type: 'drawing',
				x: 0.1,
				y: 0.1,
				width: 0.5,
				height: 0.5,
				style: {
					stroke: '#00ff00',
					strokeWidth: 4,
					lineStyle: 'dashed',
					strokeDashArray: '16, 12',
				},
				data: {
					smoothMode: false,
					points: [
						{ x: 0.1, y: 0.1 },
						{ x: 0.3, y: 0.3 },
						{ x: 0.5, y: 0.5 },
					],
				},
			},
		]

		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				annotations: dashedAnnotations,
				pageNumber: 1,
				visible: true,
			},
		})

		const drawing = wrapper.find('[data-test="annotation-drawing"]')
		expect(drawing.exists()).toBe(true)
		expect(drawing.attributes('stroke-dasharray')).toBe('9, 5')
		expect(drawing.attributes('d')).toBe('M 100.0 100.0 L 300.0 300.0 L 500.0 500.0')
	})
})
