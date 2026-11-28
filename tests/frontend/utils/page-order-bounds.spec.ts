/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { resolveBookmarkPageBounds } from '../../../src/utils/page-order'

describe('resolveBookmarkPageBounds', () => {
	it('keeps base pageOrder and targetPage when bookmark is unbounded', () => {
		expect(resolveBookmarkPageBounds({
			basePageOrder: null,
			startPage: 5,
			endPage: null,
		})).toEqual({
			pageOrder: null,
			targetPage: 5,
			boundedPageCount: null,
		})
	})

	it('restricts pageOrder to [startPage..endPage] for bounded movements and single-page bookmarks', () => {
		expect(resolveBookmarkPageBounds({
			basePageOrder: null,
			startPage: 5,
			endPage: 8,
		})).toEqual({
			pageOrder: '5-8',
			targetPage: 1,
			boundedPageCount: 4,
		})

		// Single-page bounded bookmark (e.g., two short pieces starting on page 1)
		expect(resolveBookmarkPageBounds({
			basePageOrder: null,
			startPage: 1,
			endPage: 1,
		})).toEqual({
			pageOrder: '1',
			targetPage: 1,
			boundedPageCount: 1,
		})
	})

	it('filters an existing custom basePageOrder to stay within [startPage..endPage]', () => {
		expect(resolveBookmarkPageBounds({
			basePageOrder: '1-3, 2, 4-6',
			startPage: 2,
			endPage: 4,
		})).toEqual({
			pageOrder: '2, 3, 2, 4',
			targetPage: 1,
			boundedPageCount: 4,
		})
	})
})
