import { describe, expect, it } from 'vitest'
import {
	formatDoubleSpreadDisplay,
	getDoubleSpreadForPage,
	getInitialDoubleSpread,
	nextDoubleSpread,
	prevDoubleSpread,
} from '../../../src/utils/page-turn'

describe('page-turn utilities', () => {
	it('computes initial double spread', () => {
		expect(getInitialDoubleSpread(1)).toEqual([1, null])
		expect(getInitialDoubleSpread(4)).toEqual([1, 2])
	})

	describe('half mode (1/2 -> 3/2 -> 3/4)', () => {
		it('turns forward and backward through an even-length score', () => {
			let spread = getInitialDoubleSpread(4)
			expect(spread).toEqual([1, 2])
			expect(prevDoubleSpread(spread, 4, 'half')).toBeNull()

			spread = nextDoubleSpread(spread, 4, 'half')!
			expect(spread).toEqual([3, 2])
			expect(formatDoubleSpreadDisplay(spread)).toBe('3 / 2')

			spread = nextDoubleSpread(spread, 4, 'half')!
			expect(spread).toEqual([3, 4])
			expect(formatDoubleSpreadDisplay(spread)).toBe('3–4')

			expect(nextDoubleSpread(spread, 4, 'half')).toBeNull()

			spread = prevDoubleSpread(spread, 4, 'half')!
			expect(spread).toEqual([3, 2])

			spread = prevDoubleSpread(spread, 4, 'half')!
			expect(spread).toEqual([1, 2])
		})

		it('handles odd page counts (e.g. 5 pages)', () => {
			let spread = getInitialDoubleSpread(5)
			spread = nextDoubleSpread(spread, 5, 'half')! // [3, 2]
			spread = nextDoubleSpread(spread, 5, 'half')! // [3, 4]
			spread = nextDoubleSpread(spread, 5, 'half')! // [5, 4]
			expect(spread).toEqual([5, 4])
			expect(nextDoubleSpread(spread, 5, 'half')).toBeNull()

			spread = prevDoubleSpread(spread, 5, 'half')!
			expect(spread).toEqual([3, 4])
		})
	})

	describe('step mode (1/2 -> 2/3 -> 3/4)', () => {
		it('slides by one page forward and backward', () => {
			let spread = getInitialDoubleSpread(4)
			expect(spread).toEqual([1, 2])
			expect(prevDoubleSpread(spread, 4, 'step')).toBeNull()

			spread = nextDoubleSpread(spread, 4, 'step')!
			expect(spread).toEqual([2, 3])

			spread = nextDoubleSpread(spread, 4, 'step')!
			expect(spread).toEqual([3, 4])

			expect(nextDoubleSpread(spread, 4, 'step')).toBeNull()

			spread = prevDoubleSpread(spread, 4, 'step')!
			expect(spread).toEqual([2, 3])
		})
	})

	describe('spread mode (1/2 -> 3/4)', () => {
		it('jumps two pages at a time and handles trailing odd page', () => {
			let spread = getInitialDoubleSpread(5)
			expect(spread).toEqual([1, 2])

			spread = nextDoubleSpread(spread, 5, 'spread')!
			expect(spread).toEqual([3, 4])

			spread = nextDoubleSpread(spread, 5, 'spread')!
			expect(spread).toEqual([5, null])

			expect(nextDoubleSpread(spread, 5, 'spread')).toBeNull()

			spread = prevDoubleSpread(spread, 5, 'spread')!
			expect(spread).toEqual([3, 4])

			spread = prevDoubleSpread(spread, 5, 'spread')!
			expect(spread).toEqual([1, 2])
		})
	})

	it('resolves spread for direct page jumps', () => {
		expect(getDoubleSpreadForPage(3, 5, 'half')).toEqual([3, 4])
		expect(getDoubleSpreadForPage(4, 5, 'half')).toEqual([3, 4])
		expect(getDoubleSpreadForPage(5, 5, 'spread')).toEqual([5, null])
		expect(getDoubleSpreadForPage(5, 5, 'step')).toEqual([4, 5])
	})
})
