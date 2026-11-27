/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SongAnnotationToolbar from '../../src/components/song-viewer/SongAnnotationToolbar.vue'
import { resolveAnnotationBlendMode } from '../../src/utils/annotation'
import { createDefaultToolStyle } from '../../src/utils/annotationToolStyle'
import { isSnappableLineTool, snapPointToAxis } from '../../src/utils/annotationTransform'

describe('annotation highlighter blending and straight-line snapping', () => {
	it('resolves highlight blend mode to multiply in light mode and screen in night mode even when persisted as multiply', () => {
		expect(resolveAnnotationBlendMode({ type: 'highlight', style: {} }, false)).toBe('multiply')
		expect(resolveAnnotationBlendMode({ type: 'highlight', style: {} }, true)).toBe('screen')
		expect(resolveAnnotationBlendMode({ type: 'highlight', style: { blendMode: 'multiply' } }, false)).toBe('multiply')
		expect(resolveAnnotationBlendMode({ type: 'highlight', style: { blendMode: 'multiply' } }, true)).toBe('screen')
		expect(resolveAnnotationBlendMode({ type: 'highlight', style: { blendMode: 'screen' } }, false)).toBe('multiply')
		expect(resolveAnnotationBlendMode({ type: 'highlight', style: { blendMode: 'screen' } }, true)).toBe('screen')
		expect(resolveAnnotationBlendMode({ type: 'highlight', style: { blendMode: 'darken' } }, true)).toBe('darken')
		expect(resolveAnnotationBlendMode({ type: 'drawing', style: {} }, false)).toBe('normal')
		expect(resolveAnnotationBlendMode({ type: 'drawing', style: { blendMode: 'normal' } }, true)).toBe('normal')
	})

	it('snaps endpoints to 0, 45, and 90 degree axes in true visual coordinates and preserves angle at page edges', () => {
		const vbHeight = 1414.21 // A4 portrait

		// Horizontal snap
		const horiz = snapPointToAxis({ x: 0.2, y: 0.5 }, { x: 0.7, y: 0.52 }, vbHeight)
		expect(horiz.y).toBe(0.5)
		expect(horiz.x).toBeGreaterThan(0.65)

		// Vertical snap
		const vert = snapPointToAxis({ x: 0.4, y: 0.2 }, { x: 0.43, y: 0.7 }, vbHeight)
		expect(vert.x).toBe(0.4)
		expect(vert.y).toBeGreaterThan(0.65)

		// True visual 45-degree diagonal on non-square A4 page
		const diag = snapPointToAxis({ x: 0.2, y: 0.2 }, { x: 0.55, y: 0.42 }, vbHeight)
		const dxSvg = (diag.x - 0.2) * 1000
		const dySvg = (diag.y - 0.2) * vbHeight
		expect(Math.abs(dxSvg - dySvg)).toBeLessThan(1)

		// Clamping along the 45-degree ray at right edge preserves 45-degree angle
		const clamped = snapPointToAxis({ x: 0.9, y: 0.1 }, { x: 1.0, y: 0.22 }, vbHeight)
		expect(clamped.x).toBeLessThanOrEqual(1)
		expect(clamped.y).toBeLessThanOrEqual(1)
		const cdx = (clamped.x - 0.9) * 1000
		const cdy = (clamped.y - 0.1) * vbHeight
		expect(Math.abs(cdx - cdy)).toBeLessThan(1)
	})

	it('identifies snappable line tools and exposes the Snap toggle in SongAnnotationToolbar', async () => {
		expect(isSnappableLineTool('drawing')).toBe(true)
		expect(isSnappableLineTool('highlight')).toBe(true)
		expect(isSnappableLineTool('line')).toBe(true)
		expect(isSnappableLineTool('arrow')).toBe(true)
		expect(isSnappableLineTool('text')).toBe(false)
		expect(isSnappableLineTool('rect')).toBe(false)

		const wrapper = mount(SongAnnotationToolbar, {
			props: {
				activeTool: 'highlight',
				toolStyle: createDefaultToolStyle(),
			},
		})

		const snapBtn = wrapper.find('[data-test="annotation-snap-toggle"]')
		expect(snapBtn.exists()).toBe(true)
		expect(snapBtn.attributes('aria-pressed')).toBe('false')

		await snapBtn.trigger('click')
		expect(wrapper.emitted('update:toolStyle')?.[0]?.[0]).toMatchObject({ snapToAxis: true })

		// Hidden for non-line tools like text
		await wrapper.setProps({ activeTool: 'text' })
		expect(wrapper.find('[data-test="annotation-snap-toggle"]').exists()).toBe(false)
	})
})
