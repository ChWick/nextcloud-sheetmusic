/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import {
	formatPageOrder,
	generatePresetOrder,
	parsePageOrder,
	validatePageOrder,
} from '../../../src/utils/page-order'

describe('page-order utilities', () => {
	describe('parsePageOrder', () => {
		it('returns 1 to totalPages when string is empty or blank', () => {
			expect(parsePageOrder('', 3)).toEqual([1, 2, 3])
			expect(parsePageOrder('   ', 4)).toEqual([1, 2, 3, 4])
			expect(parsePageOrder(null, 2)).toEqual([1, 2])
			expect(parsePageOrder(undefined, 1)).toEqual([1])
		})

		it('parses simple ranges and individual pages', () => {
			expect(parsePageOrder('1-3')).toEqual([1, 2, 3])
			expect(parsePageOrder('1, 3, 5')).toEqual([1, 3, 5])
			expect(parsePageOrder('1-2, 4, 6-7')).toEqual([1, 2, 4, 6, 7])
		})

		it('handles descending page ranges', () => {
			expect(parsePageOrder('3-1')).toEqual([3, 2, 1])
		})

		it('handles repeated pages and ranges', () => {
			expect(parsePageOrder('1-2, 1-2')).toEqual([1, 2, 1, 2])
			expect(parsePageOrder('1, 1, 2, 2')).toEqual([1, 1, 2, 2])
		})
	})

	describe('validatePageOrder', () => {
		it('accepts empty string as valid default', () => {
			const res = validatePageOrder('', 5)
			expect(res.valid).toBe(true)
			expect(res.pages).toEqual([1, 2, 3, 4, 5])
		})

		it('accepts valid range and lists', () => {
			const res = validatePageOrder('1-3, 5', 5)
			expect(res.valid).toBe(true)
			expect(res.pages).toEqual([1, 2, 3, 5])
		})

		it('rejects pages exceeding totalPages', () => {
			const res = validatePageOrder('1-4, 7', 5)
			expect(res.valid).toBe(false)
			expect(res.error).toContain('exceeds total document pages (5)')
		})

		it('rejects page range exceeding totalPages', () => {
			const res = validatePageOrder('1-6', 5)
			expect(res.valid).toBe(false)
			expect(res.error).toContain('exceeds total document pages (5)')
		})

		it('rejects invalid syntax or non-numbers', () => {
			expect(validatePageOrder('abc', 5).valid).toBe(false)
			expect(validatePageOrder('1-2-3', 5).valid).toBe(false)
			expect(validatePageOrder('1,,2', 5).valid).toBe(false)
			expect(validatePageOrder('0', 5).valid).toBe(false)
		})
	})

	describe('formatPageOrder', () => {
		it('formats single pages and ranges', () => {
			expect(formatPageOrder([1, 2, 3])).toBe('1-3')
			expect(formatPageOrder([1, 2, 4])).toBe('1, 2, 4')
			expect(formatPageOrder([1, 2, 3, 5, 6, 7])).toBe('1-3, 5-7')
			expect(formatPageOrder([1])).toBe('1')
			expect(formatPageOrder([1, 2])).toBe('1, 2')
			expect(formatPageOrder([1, 2, 1, 2])).toBe('1, 2, 1, 2')
		})

		it('handles empty input', () => {
			expect(formatPageOrder([])).toBe('')
		})
	})

	describe('generatePresetOrder', () => {
		it('generates all pages preset', () => {
			expect(generatePresetOrder('all', 1)).toBe('1')
			expect(generatePresetOrder('all', 4)).toBe('1-4')
		})

		it('generates reverse pages preset', () => {
			expect(generatePresetOrder('reverse', 1)).toBe('1')
			expect(generatePresetOrder('reverse', 3)).toBe('3, 2, 1')
		})

		it('generates repeat pages preset', () => {
			expect(generatePresetOrder('repeat', 1)).toBe('1, 1')
			expect(generatePresetOrder('repeat', 3)).toBe('1-3, 1-3')
		})
	})
})
