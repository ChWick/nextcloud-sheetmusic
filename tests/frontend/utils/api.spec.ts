import { describe, expect, it, vi } from 'vitest'
import { describeRequestFailure } from '../../../src/utils/api'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string) => text,
}))

describe('api utils', () => {
	describe('describeRequestFailure', () => {
		it('returns fallback message when error has no status', () => {
			const error = new Error('Network Error')
			expect(describeRequestFailure(error, 'Fallback message')).toBe('Fallback message')
		})

		it('returns fallback message for non-404/405 HTTP errors', () => {
			const error = { response: { status: 500 } }
			expect(describeRequestFailure(error, 'Fallback message')).toBe('Fallback message')
		})

		it('returns special message for HTTP 404', () => {
			const error = { response: { status: 404 } }
			const result = describeRequestFailure(error, 'Fallback message')
			expect(result).toContain('HTTP {status}')
		})

		it('returns special message for HTTP 405', () => {
			const error = { response: { status: 405 } }
			const result = describeRequestFailure(error, 'Fallback message')
			expect(result).toContain('HTTP {status}')
		})
	})
})
