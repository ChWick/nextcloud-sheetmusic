import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SongViewToggle from '../../../src/components/songs/SongViewToggle.vue'

describe('SongViewToggle.vue', () => {
	it('renders view options dropdown menu and manages viewMode props', async () => {
		const wrapper = mount(SongViewToggle, {
			props: { viewMode: 'table' },
		})

		const actions = wrapper.findComponent({ name: 'NcActions' })
		expect(actions.exists()).toBe(true)
		expect(actions.props('ariaLabel')).toBe('Table view')
	})
})
