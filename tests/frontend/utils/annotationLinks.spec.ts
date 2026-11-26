import { describe, expect, it } from 'vitest'
import {
	buildReverseLinkDraft,
	clampNormalizedCoord,
	LINK_LABEL_PRESETS,
	resolveLinkColors,
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
})
