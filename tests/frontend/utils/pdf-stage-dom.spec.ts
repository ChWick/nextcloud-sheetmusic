/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import {
	applyStageLayoutStyles,
	createContinuousPagePlaceholder,
	createDoublePageSlot,
	createPagePlaceholder,
} from '../../../src/utils/pdf-stage-dom'

describe('pdf-stage-dom', () => {
	it('applies and clears two-page grid layout styles on stage', () => {
		const stage = document.createElement('div')
		applyStageLayoutStyles(stage, { gap: '24px', isDoubleTwoPage: true, isCustomZoom: false })
		expect(stage.style.gap).toBe('24px')
		expect(stage.style.display).toBe('grid')
		expect(stage.style.width).toBe('100%')

		applyStageLayoutStyles(stage, { gap: '16px', isDoubleTwoPage: true, isCustomZoom: true })
		expect(stage.style.width).toBe('max-content')

		applyStageLayoutStyles(stage, { gap: '12px', isDoubleTwoPage: false, isCustomZoom: false })
		expect(stage.style.display).toBe('')
		expect(stage.style.gridTemplateColumns).toBe('')
		expect(stage.style.width).toBe('')
	})

	it('creates tagged placeholders and scaled continuous placeholders', () => {
		const basic = createPagePlaceholder({ pageNum: 3, index: 2 })
		expect(basic.dataset.pageNum).toBe('3')
		expect(basic.dataset.pageIndex).toBe('2')

		const cont = createContinuousPagePlaceholder({ pageNum: 5, index: 4 }, 2.5)
		expect(cont.className).toBe('sheetmusic-page-placeholder')
		expect(cont.style.minHeight).toBe('1600px')
	})

	it('creates double-page slots for fit and custom zoom modes', () => {
		const twoPageFit = createDoublePageSlot({ pageNum: 1, index: 1 }, {
			doubleSlotW: 500,
			isTwoPageSpread: true,
			isCustomZoom: false,
		})
		expect(twoPageFit.slot.className).toBe('sheetmusic-double-slot')
		expect(twoPageFit.slot.style.maxWidth).toBe('500px')
		expect(twoPageFit.slot.firstElementChild).toBe(twoPageFit.placeholder)

		const singleCustom = createDoublePageSlot({ pageNum: 2, index: 2 }, {
			doubleSlotW: 500,
			isTwoPageSpread: false,
			isCustomZoom: true,
		})
		expect(singleCustom.slot.style.minWidth).toBe('500px')
		expect(singleCustom.slot.style.overflow).toBe('')
	})
})
