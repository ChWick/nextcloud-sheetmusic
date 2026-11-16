/**
 * Two-page (double spread) page-turning strategies for sheet music performance.
 *
 * - `half`:   1/2 -> 3/2 -> 3/4 (replaces the finished left page first, then the right page)
 * - `step`:   1/2 -> 2/3 -> 3/4 (slides the two-page window by 1 page)
 * - `spread`: 1/2 -> 3/4        (jumps a full two-page spread)
 */
export type DoublePageTurnMode = 'half' | 'step' | 'spread'

/**
 * 1-indexed logical page numbers currently shown in `[left, right]` slots.
 * `right` is `null` when only a single page can be shown (e.g. a 1-page piece or trailing odd page).
 */
export type DoubleSpread = [number, number | null]

export function getInitialDoubleSpread(totalPages: number): DoubleSpread {
	if (totalPages <= 1) {
		return [1, null]
	}
	return [1, 2]
}

export function getDoubleSpreadForPage(
	page: number,
	totalPages: number,
	mode: DoublePageTurnMode,
): DoubleSpread {
	const total = Math.max(1, totalPages)
	const clamped = Math.min(Math.max(1, page), total)

	if (total === 1) {
		return [1, null]
	}

	if (mode === 'step') {
		const left = clamped >= total ? total - 1 : clamped
		return [left, left + 1]
	}

	const left = clamped % 2 === 1 ? clamped : clamped - 1
	const right = left + 1 <= total ? left + 1 : null
	return [left, right]
}

export function nextDoubleSpread(
	spread: DoubleSpread,
	totalPages: number,
	mode: DoublePageTurnMode,
): DoubleSpread | null {
	const [left, right] = spread
	const total = Math.max(1, totalPages)

	if (mode === 'spread') {
		const maxShown = Math.max(left, right ?? left)
		if (maxShown >= total) {
			return null
		}
		const nextLeft = maxShown + 1
		const nextRight = nextLeft + 1 <= total ? nextLeft + 1 : null
		return [nextLeft, nextRight]
	}

	if (mode === 'step') {
		if (right === null || right >= total) {
			return null
		}
		const nextLeft = left + 1
		return [nextLeft, nextLeft + 1]
	}

	// 'half': 1/2 -> 3/2 -> 3/4
	if (right === null) {
		return null
	}
	if (left < right) {
		if (right + 1 > total) {
			return null
		}
		return [right + 1, right]
	}
	// Staggered state (e.g. [3, 2]): advance right slot to [3, 4]
	if (left + 1 > total) {
		return null
	}
	return [left, left + 1]
}

export function prevDoubleSpread(
	spread: DoubleSpread,
	totalPages: number,
	mode: DoublePageTurnMode,
): DoubleSpread | null {
	const [left, right] = spread
	const total = Math.max(1, totalPages)

	if (mode === 'spread') {
		if (left <= 1) {
			return null
		}
		const prevLeft = Math.max(1, left - 2)
		const prevRight = prevLeft + 1 <= total ? prevLeft + 1 : null
		return [prevLeft, prevRight]
	}

	if (mode === 'step') {
		if (left <= 1) {
			return null
		}
		const prevLeft = left - 1
		const prevRight = prevLeft + 1 <= total ? prevLeft + 1 : null
		return [prevLeft, prevRight]
	}

	// 'half': 3/4 -> 3/2 -> 1/2
	if (right === null) {
		if (left <= 1) {
			return null
		}
		// Trailing single odd page (e.g. [5, null] -> [3, 4])
		return [Math.max(1, left - 2), left - 1]
	}
	if (left < right) {
		if (left <= 1) {
			return null
		}
		return [left, left - 1]
	}
	// Staggered state (e.g. [3, 2]): revert left slot back to [1, 2]
	if (right <= 1) {
		return null
	}
	return [right - 1, right]
}

export function formatDoubleSpreadDisplay(spread: DoubleSpread): string {
	const [left, right] = spread
	if (right === null || right === left) {
		return `${left}`
	}
	if (left > right) {
		return `${left} / ${right}`
	}
	return `${left}–${right}`
}
