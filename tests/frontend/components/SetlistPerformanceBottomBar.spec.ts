import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SetlistPerformanceBottomBar from '../../../src/components/setlists/SetlistPerformanceBottomBar.vue'

describe('SetlistPerformanceBottomBar', () => {
	it('renders page status and emits navigation and mode events', async () => {
		const wrapper = mount(SetlistPerformanceBottomBar, {
			props: {
				visible: true,
				hasPrevSong: true,
				hasNextSong: true,
				canGoPrevPage: true,
				canGoNextPage: true,
				currentPageDisplay: '3 / 2',
				totalPages: 4,
				layoutChoice: 'auto',
				effectiveLayout: 'double',
				doublePageTurnMode: 'half',
				zoomChoice: 'fit-page',
			},
		})

		expect(wrapper.text()).toContain('3 / 2')
		expect(wrapper.find('button[aria-label="Fit Page"]').exists()).toBe(true)
		expect(wrapper.find('button[data-turn-mode="step"]').exists()).toBe(true)

		await wrapper.find('button[aria-label="Previous song"]').trigger('click')
		await wrapper.find('button[aria-label="Previous page"]').trigger('click')
		await wrapper.find('button[aria-label="Next page"]').trigger('click')
		await wrapper.find('button[aria-label="Next song"]').trigger('click')
		await wrapper.find('button[aria-label="Setlist items"]').trigger('click')
		await wrapper.find('[data-test="performance-bottom-night-toggle"]').trigger('click')
		await wrapper.find('button[data-turn-mode="step"]').trigger('click')

		const layoutSelect = wrapper.findComponent({ name: 'NcSelect' })
		expect(layoutSelect.exists()).toBe(true)
		layoutSelect.vm.$emit('update:modelValue', { value: 'single', label: 'Single page' })
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('prevSong')).toHaveLength(1)
		expect(wrapper.emitted('prevPage')).toHaveLength(1)
		expect(wrapper.emitted('nextPage')).toHaveLength(1)
		expect(wrapper.emitted('nextSong')).toHaveLength(1)
		expect(wrapper.emitted('toggleDrawer')).toHaveLength(1)
		expect(wrapper.emitted('toggleNightMode')).toHaveLength(1)
		expect(wrapper.emitted('changeDoublePageTurnMode')?.[0]).toEqual(['step'])
		expect(wrapper.emitted('changeLayout')?.[0]).toEqual(['single'])
	})

	it('hides double page turn selector when explicit single layout is active', () => {
		const wrapper = mount(SetlistPerformanceBottomBar, {
			props: {
				visible: true,
				hasPrevSong: false,
				hasNextSong: false,
				canGoPrevPage: false,
				canGoNextPage: false,
				currentPageDisplay: '1',
				totalPages: 4,
				layoutChoice: 'single',
				effectiveLayout: 'single',
				doublePageTurnMode: 'half',
				zoomChoice: 'fit-width',
			},
		})

		expect(wrapper.find('button[data-turn-mode="half"]').exists()).toBe(false)
		expect(wrapper.find('button[aria-label="Fit Width"]').exists()).toBe(true)
	})
})
