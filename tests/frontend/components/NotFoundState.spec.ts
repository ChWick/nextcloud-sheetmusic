import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import NotFoundState from '../../../src/components/NotFoundState.vue'

describe('NotFoundState.vue', () => {
	it('renders title, description, and emits action when button is clicked', async () => {
		const wrapper = mount(NotFoundState, {
			props: {
				title: 'Song not found',
				description: 'The requested song does not exist.',
				actionLabel: 'Back to songs',
				testId: 'song-not-found',
			},
		})

		expect(wrapper.find('[data-test="song-not-found"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Song not found')
		expect(wrapper.text()).toContain('The requested song does not exist.')

		const btn = wrapper.find('[data-test="not-found-action"]')
		expect(btn.exists()).toBe(true)
		expect(btn.text()).toContain('Back to songs')
		await btn.trigger('click')
		expect(wrapper.emitted('action')).toHaveLength(1)
	})

	it('omits action button when actionLabel is not provided', () => {
		const wrapper = mount(NotFoundState, {
			props: {
				title: 'Library not found',
			},
		})

		expect(wrapper.find('[data-test="not-found-action"]').exists()).toBe(false)
	})
})
