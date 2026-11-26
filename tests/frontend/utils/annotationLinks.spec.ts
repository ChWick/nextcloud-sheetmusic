import { describe, expect, it } from 'vitest'
import {
	buildLinkDrafts,
	buildReverseLinkDraft,
	clampNormalizedCoord,
	getLinkCenter,
	getLinkCircleGeometry,
	getLinkStyle,
	LINK_LABEL_PRESETS,
	resolveLinkColors,
	resolveLinkLabel,
} from '../../../src/utils/annotationLinks'

describe('annotationLinks', () => {
	it('exposes musical label presets', () => {
		expect(LINK_LABEL_PRESETS).toContain('D.C.')
		expect(LINK_LABEL_PRESETS).toContain('D.S.')
		expect(LINK_LABEL_PRESETS).toContain('To Coda')
	})

	it('clamps normalized coordinates to [0..1]', () => {
		expect(clampNormalizedCoord(0.5)).toBe(0.5)
		expect(clampNormalizedCoord(-0.2)).toBe(0)
		expect(clampNormalizedCoord(1.8)).toBe(1)
		expect(clampNormalizedCoord(Number.NaN)).toBeUndefined()
		expect(clampNormalizedCoord(undefined)).toBeUndefined()
	})

	it('resolves custom hex colors and falls back to default blue for invalid inputs', () => {
		expect(resolveLinkColors('#d32f2f')).toEqual({
			stroke: 'rgba(211, 47, 47, 0.72)',
			fill: 'rgba(211, 47, 47, 0.16)',
			text: '#d32f2f',
		})

		expect(resolveLinkColors(undefined)).toEqual({
			stroke: 'rgba(0, 82, 204, 0.72)',
			fill: 'rgba(0, 82, 204, 0.16)',
			text: '#0052cc',
		})

		expect(resolveLinkColors('not-a-hex')).toEqual({
			stroke: 'rgba(0, 82, 204, 0.72)',
			fill: 'rgba(0, 82, 204, 0.16)',
			text: '#0052cc',
		})
	})

	it('builds a paired reverse link pointing back to the source page and spot', () => {
		const reverse = buildReverseLinkDraft({
			pageNumber: 0,
			layerIndex: 0,
			type: 'link',
			x: 0.2,
			y: 0.3,
			width: 0.1,
			height: 0.06,
			style: { color: '#0082c9', opacity: 1, fontSize: 16 },
			data: { targetPage: 2, targetX: 0.6, targetY: 0.4, label: 'To Coda', linkStyle: 'circle' },
		})

		expect(reverse).toMatchObject({
			pageNumber: 2,
			type: 'link',
			data: {
				targetPage: 0,
				targetX: 0.25,
				targetY: 0.33,
				label: '',
				linkStyle: 'circle',
			},
		})

		// Same-page link returns null
		expect(buildReverseLinkDraft({
			pageNumber: 1,
			layerIndex: 0,
			type: 'link',
			x: 0.2,
			y: 0.3,
			width: 0.1,
			height: 0.06,
			style: { opacity: 1 },
			data: { targetPage: 1, label: 'Repeat' },
		})).toBeNull()
	})

	it('resolves link style, center, circle geometry, labels, and paired drafts', () => {
		expect(getLinkStyle({ data: { linkStyle: 'circle' } })).toBe('circle')
		expect(getLinkStyle({ data: {} })).toBe('box')

		const center = getLinkCenter({ x: 0.2, y: 0.3, width: 0.1, height: 0.06 })
		expect(center.x).toBeCloseTo(0.25, 6)
		expect(center.y).toBeCloseTo(0.33, 6)
		expect(getLinkCenter({ x: 0.98, y: 0.98, width: 0.1, height: 0.06 })).toEqual({ x: 1, y: 1 })

		const geom = getLinkCircleGeometry({ x: 0.2, y: 0.3, width: 0.1, height: 0.06 }, 1000)
		expect(geom.cx).toBeCloseTo(250, 5)
		expect(geom.cy).toBeCloseTo(330, 5)
		expect(geom.r).toBeCloseTo(30, 5)
		const minGeom = getLinkCircleGeometry({ x: 0.1, y: 0.1, width: 0.01, height: 0.01 }, 1000)
		expect(minGeom.r).toBe(14)

		expect(resolveLinkLabel('', 2, 'box')).toBe('→ p. 3')
		expect(resolveLinkLabel('', 2, 'circle')).toBe('')
		expect(resolveLinkLabel('  To Coda  ', 2, 'circle')).toBe('To Coda')

		const drafts = buildLinkDrafts({
			sourcePageIndex: 0,
			x: 0.2,
			y: 0.3,
			width: 0.1,
			height: 0.06,
			targetPageIndex: 2,
			targetX: 0.5,
			targetY: 0.4,
			linkStyle: 'box',
		}, true)
		expect(drafts).toHaveLength(2)
		expect(drafts[0].data?.label).toBe('→ p. 3')
		expect(drafts[1].pageNumber).toBe(2)
		expect(drafts[1].data?.label).toBe('← p. 1')
	})
})
