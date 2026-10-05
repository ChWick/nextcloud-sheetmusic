import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FilesSyncToolCard from '../../../src/components/files/FilesSyncToolCard.vue'

describe('FilesSyncToolCard', () => {
	it('renders no sync controls', () => {
		const wrapper = mount(FilesSyncToolCard)
		expect(wrapper.text()).toBe('')
	})
})
