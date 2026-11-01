import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongPagination from '../../../src/components/songs/SongPagination.vue'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string, vars?: Record<string, any>) => {
		let res = text
		if (vars) {
			for (const [k, v] of Object.entries(vars)) {
				res = res.replace(`{${k}}`, String(v))
			}
		}
		return res
	},
}))

describe('SongPagination.vue', () => {
	it('renders summary correctly for paginated list', () => {
		const wrapper = mount(SongPagination, {
			props: {
				currentPage: 2,
				totalItems: 120,
				pageSize: 25,
			},
		})

		expect(wrapper.find('[data-test="pagination-summary"]').text()).toContain('Showing 26–50 of 120 songs')
	})

	it('renders summary correctly when showing all items', () => {
		const wrapper = mount(SongPagination, {
			props: {
				currentPage: 1,
				totalItems: 42,
				pageSize: 'all',
			},
		})

		expect(wrapper.find('[data-test="pagination-summary"]').text()).toContain('Showing all 42 songs')
		// Controls are hidden when totalPages <= 1
		expect(wrapper.find('[data-test="pagination-next"]').exists()).toBe(false)
	})

	it('emits update:currentPage when next, prev, first, last are clicked', async () => {
		const wrapper = mount(SongPagination, {
			props: {
				currentPage: 2,
				totalItems: 100,
				pageSize: 25,
			},
		})

		// Next page
		const nextBtn = wrapper.find('[data-test="pagination-next"]')
		await nextBtn.trigger('click')
		expect(wrapper.emitted('update:currentPage')?.[0]).toEqual([3])

		// Prev page
		const prevBtn = wrapper.find('[data-test="pagination-prev"]')
		await prevBtn.trigger('click')
		expect(wrapper.emitted('update:currentPage')?.[1]).toEqual([1])

		// First page
		const firstBtn = wrapper.find('[data-test="pagination-first"]')
		await firstBtn.trigger('click')
		expect(wrapper.emitted('update:currentPage')?.[2]).toEqual([1])

		// Last page (100 items / 25 per page = page 4)
		const lastBtn = wrapper.find('[data-test="pagination-last"]')
		await lastBtn.trigger('click')
		expect(wrapper.emitted('update:currentPage')?.[3]).toEqual([4])
	})

	it('disables first and prev buttons on page 1', () => {
		const wrapper = mount(SongPagination, {
			props: {
				currentPage: 1,
				totalItems: 100,
				pageSize: 25,
			},
		})

		expect(wrapper.find('[data-test="pagination-first"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-test="pagination-prev"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-test="pagination-next"]').attributes('disabled')).toBeUndefined()
		expect(wrapper.find('[data-test="pagination-last"]').attributes('disabled')).toBeUndefined()
	})

	it('disables next and last buttons on the last page', () => {
		const wrapper = mount(SongPagination, {
			props: {
				currentPage: 4,
				totalItems: 100,
				pageSize: 25,
			},
		})

		expect(wrapper.find('[data-test="pagination-first"]').attributes('disabled')).toBeUndefined()
		expect(wrapper.find('[data-test="pagination-prev"]').attributes('disabled')).toBeUndefined()
		expect(wrapper.find('[data-test="pagination-next"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-test="pagination-last"]').attributes('disabled')).toBeDefined()
	})

	it('emits update:currentPage when specific page button is clicked', async () => {
		const wrapper = mount(SongPagination, {
			props: {
				currentPage: 1,
				totalItems: 100,
				pageSize: 25,
			},
		})

		const page3Btn = wrapper.findAll('button').find((b) => b.text() === '3')
		expect(page3Btn).toBeDefined()
		await page3Btn?.trigger('click')

		expect(wrapper.emitted('update:currentPage')?.[0]).toEqual([3])
	})

	it('emits update:pageSize and resets currentPage to 1 when page size changes', async () => {
		const wrapper = mount(SongPagination, {
			props: {
				currentPage: 3,
				totalItems: 200,
				pageSize: 25,
			},
		})

		const select = wrapper.find('select[data-test="pagination-page-size"]')
		await select.setValue('100')

		expect(wrapper.emitted('update:pageSize')?.[0]).toEqual([100])
		expect(wrapper.emitted('update:currentPage')?.[0]).toEqual([1])
	})
})
