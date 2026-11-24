/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SongAnnotationSelectionBox from '../../../src/components/song-viewer/SongAnnotationSelectionBox.vue'
import type { SheetAnnotation } from '../../../src/types/annotation'

describe('SongAnnotationSelectionBox.vue', () => {
	it('renders corner resize handles for box annotations and scales dimensions by uiScale', () => {
		const item: SheetAnnotation = {
			id: 1,
			uuid: 'box-1',
			songId: 1,
			fileId: 10,
			pageNumber: 0,
			type: 'text',
			x: 0.2,
			y: 0.3,
			width: 0.25,
			height: 0.1,
			style: {},
			data: { text: 'Allegro' },
		}

		const wrapper = mount(SongAnnotationSelectionBox, {
			props: { item, vbHeight: 1414, uiScale: 2 },
		})

		const outline = wrapper.find('rect:not([data-resize-handle])')
		expect(Number(outline.attributes('stroke-width'))).toBeCloseTo(3, 5) // 1.5 * 2
		expect(outline.attributes('stroke-dasharray')).toBe('8, 6') // 4*2, 3*2

		const handles = wrapper.findAll('[data-resize-handle]')
		expect(handles.map((h) => h.attributes('data-resize-handle'))).toEqual(['nw', 'ne', 'sw', 'se'])
		expect(Number(handles[0].attributes('width'))).toBeCloseTo(18, 5) // 9 * 2
		expect(Number(handles[0].attributes('stroke-width'))).toBeCloseTo(4, 5) // 2 * 2
	})

	it('renders endpoint and height handles for two-point shapes', () => {
		const item: SheetAnnotation = {
			id: 2,
			uuid: 'slur-1',
			songId: 1,
			fileId: 10,
			pageNumber: 0,
			type: 'shape',
			x: 0.1,
			y: 0.2,
			width: 0.3,
			height: 0.05,
			style: {},
			data: {
				shapeType: 'slur',
				curvature: -20,
				points: [
					{ x: 0.1, y: 0.2 },
					{ x: 0.4, y: 0.2 },
				],
			},
		}

		const wrapper = mount(SongAnnotationSelectionBox, {
			props: { item, vbHeight: 1414, uiScale: 1.5 },
		})

		const p0 = wrapper.find('[data-resize-handle="p0"]')
		const p1 = wrapper.find('[data-resize-handle="p1"]')
		const height = wrapper.find('[data-resize-handle="height"]')

		expect(p0.exists()).toBe(true)
		expect(p1.exists()).toBe(true)
		expect(height.exists()).toBe(true)
		expect(Number(p0.attributes('r'))).toBeCloseTo(9, 5) // 6 * 1.5
		expect(Number(p0.attributes('stroke-width'))).toBeCloseTo(3, 5) // 2 * 1.5
	})

	it('renders a circular selection outline with no resize handles for stamp annotations', () => {
		const item: SheetAnnotation = {
			id: 3,
			uuid: 'stamp-1',
			songId: 1,
			fileId: 10,
			pageNumber: 0,
			type: 'stamp',
			x: 0.5,
			y: 0.5,
			width: 0,
			height: 0,
			style: { fontSize: 40 },
			data: { symbol: '𝆑' },
		}

		const wrapper = mount(SongAnnotationSelectionBox, {
			props: { item, vbHeight: 1414, uiScale: 1 },
		})

		const circle = wrapper.find('circle')
		expect(circle.exists()).toBe(true)
		expect(Number(circle.attributes('r'))).toBeCloseTo(19, 5) // 40 * 0.4 + 4 * 0.75
		expect(wrapper.findAll('[data-resize-handle]')).toHaveLength(0)

		const fingeringItem: SheetAnnotation = {
			...item,
			id: 4,
			uuid: 'stamp-2',
			data: { symbol: '3', category: 'fingering', fontKind: 'fingering' },
		}
		const fingeringWrapper = mount(SongAnnotationSelectionBox, {
			props: { item: fingeringItem, vbHeight: 1414, uiScale: 1 },
		})
		expect(Number(fingeringWrapper.find('circle').attributes('r'))).toBeCloseTo(9, 5)
	})
})
