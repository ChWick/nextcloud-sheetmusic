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

	it('renders a tight ink-hugging rectangular selection border with no resize handles for stamp annotations', () => {
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
			data: { symbol: '\uE522' }, // forte
		}

		const wrapper = mount(SongAnnotationSelectionBox, {
			props: { item, vbHeight: 1414, uiScale: 1 },
		})

		expect(wrapper.find('circle').exists()).toBe(false)
		const rect = wrapper.find('rect')
		expect(rect.exists()).toBe(true)
		expect(Number(rect.attributes('width'))).toBeGreaterThan(10)
		expect(Number(rect.attributes('height'))).toBeGreaterThan(10)
		expect(wrapper.findAll('[data-resize-handle]')).toHaveLength(0)

		const fingeringItem: SheetAnnotation = {
			...item,
			id: 4,
			uuid: 'stamp-2',
			data: { symbol: '\uED11', category: 'fingering' }, // narrow fingering 1
		}
		const fingeringWrapper = mount(SongAnnotationSelectionBox, {
			props: { item: fingeringItem, vbHeight: 1414, uiScale: 1 },
		})
		const fRect = fingeringWrapper.find('rect')
		expect(Number(fRect.attributes('width'))).toBeLessThan(Number(rect.attributes('width')))
	})

	it('uses the actual circle or rounded box shape without a dashed selection box for link annotations', () => {
		const circleLink: SheetAnnotation = {
			id: 5,
			uuid: 'link-circle-1',
			songId: 1,
			fileId: 10,
			pageNumber: 0,
			type: 'link',
			x: 0.2,
			y: 0.3,
			width: 0.1,
			height: 0.06,
			style: {},
			data: { targetPage: 2, linkStyle: 'circle', label: 'D.S.' },
		}

		const circleWrapper = mount(SongAnnotationSelectionBox, {
			props: { item: circleLink, vbHeight: 1414, uiScale: 1 },
		})
		const circleOutline = circleWrapper.find('[data-test="selection-link-circle"]')
		expect(circleOutline.exists()).toBe(true)
		expect(circleOutline.attributes('stroke-dasharray')).toBeUndefined()
		const circleHandles = circleWrapper.findAll('circle[data-resize-handle]')
		expect(circleHandles).toHaveLength(4)
		// Each circle handle lies on the circle perimeter (distance from center === r)
		const cx = Number(circleOutline.attributes('cx'))
		const cy = Number(circleOutline.attributes('cy'))
		const r = Number(circleOutline.attributes('r'))
		for (const h of circleHandles) {
			const dist = Math.hypot(Number(h.attributes('cx')) - cx, Number(h.attributes('cy')) - cy)
			expect(dist).toBeCloseTo(r, 3)
		}

		const boxLink: SheetAnnotation = {
			...circleLink,
			id: 6,
			uuid: 'link-box-1',
			data: { targetPage: 2, linkStyle: 'box', label: 'To Coda' },
		}
		const boxWrapper = mount(SongAnnotationSelectionBox, {
			props: { item: boxLink, vbHeight: 1414, uiScale: 1 },
		})
		const boxOutline = boxWrapper.find('[data-test="selection-link-box"]')
		expect(boxOutline.exists()).toBe(true)
		expect(boxOutline.attributes('stroke-dasharray')).toBeUndefined()
		expect(boxOutline.attributes('rx')).toBe('4')
		const boxHandles = boxWrapper.findAll('rect[data-resize-handle]')
		expect(boxHandles).toHaveLength(4)
		// NW handle is centered directly on (item.x * 1000, item.y * vbHeight) with zero padding
		expect(Number(boxHandles[0].attributes('x')) + Number(boxHandles[0].attributes('width')) / 2).toBeCloseTo(200, 3)
		expect(Number(boxHandles[0].attributes('y')) + Number(boxHandles[0].attributes('height')) / 2).toBeCloseTo(0.3 * 1414, 3)
	})
})
