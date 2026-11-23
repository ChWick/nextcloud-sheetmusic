/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import type { SheetAnnotation } from '../../../src/types/annotation'
import {
	createDefaultToolStyle,
	mergeAnnotationStyleIntoToolStyle,
	stepStrokeWidth,
} from '../../../src/utils/annotationToolStyle'

describe('annotationToolStyle', () => {
	it('steps stroke widths by 0.5 below 4 and by 1 above 4, clamped to 1..64', () => {
		expect(stepStrokeWidth(2.5, 1)).toBe(3)
		expect(stepStrokeWidth(4, 1)).toBe(5)
		expect(stepStrokeWidth(4, -1)).toBe(3.5)
		expect(stepStrokeWidth(1, -1)).toBe(1)
		expect(stepStrokeWidth(64, 1)).toBe(64)
	})

	it('merges highlight, shape, stamp, text, and link styles without mutating input', () => {
		const base = createDefaultToolStyle()

		const highlight: SheetAnnotation = {
			uuid: 'h1',
			songId: 1,
			fileId: 2,
			pageNumber: 0,
			type: 'highlight',
			x: 0,
			y: 0,
			width: 0.2,
			height: 0.1,
			style: { stroke: '#40c4ff', strokeWidth: 28, opacity: 0.35 },
			data: {},
		}
		const afterHighlight = mergeAnnotationStyleIntoToolStyle(base, highlight)
		expect(afterHighlight.highlightColor).toBe('#40c4ff')
		expect(afterHighlight.highlightWidth).toBe(28)
		expect(afterHighlight.highlightOpacity).toBe(0.35)
		expect(base.highlightColor).toBe('#ffeb3b')

		const link: SheetAnnotation = {
			uuid: 'l1',
			songId: 1,
			fileId: 2,
			pageNumber: 0,
			type: 'link',
			x: 0,
			y: 0,
			width: 0.1,
			height: 0.05,
			style: { fontSize: 30, opacity: 1 },
			data: { targetPage: 2, label: 'Coda' },
		}
		expect(mergeAnnotationStyleIntoToolStyle(base, link).fontSize).toBe(30)
	})
})
