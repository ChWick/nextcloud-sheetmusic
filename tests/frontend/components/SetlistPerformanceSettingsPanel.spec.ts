import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SetlistPerformanceSettingsPanel from '../../../src/components/setlists/SetlistPerformanceSettingsPanel.vue'

describe('SetlistPerformanceSettingsPanel', () => {
	it('emits scope changes and shows save-as-default when custom override is active', async () => {
		const wrapper = mount(SetlistPerformanceSettingsPanel, {
			props: {
				open: true,
				setlistName: 'Weekend Gig',
				isSetlistOverride: false,
			},
		})

		expect(wrapper.text()).toContain('Use library defaults')
		expect(wrapper.text()).toContain('Custom settings for this setlist')
		expect(wrapper.text()).not.toContain('Save current settings as library default')

		await wrapper.find('button[data-settings-scope="setlist"]').trigger('click')
		expect(wrapper.emitted('update:isSetlistOverride')?.[0]).toEqual([true])

		await wrapper.setProps({ isSetlistOverride: true })
		expect(wrapper.text()).toContain('Save current settings as library default')

		const buttons = wrapper.findAll('button')
		const saveDefaultBtn = buttons.find(b => b.text().includes('Save current settings as library default'))
		expect(saveDefaultBtn).toBeDefined()
		await saveDefaultBtn!.trigger('click')
		expect(wrapper.emitted('saveAsLibraryDefault')).toHaveLength(1)

		await wrapper.find('button[data-settings-scope="library"]').trigger('click')
		expect(wrapper.emitted('update:isSetlistOverride')?.[1]).toEqual([false])
	})

	it('renders nothing when closed', () => {
		const wrapper = mount(SetlistPerformanceSettingsPanel, {
			props: {
				open: false,
				setlistName: 'Weekend Gig',
				isSetlistOverride: false,
			},
		})

		expect(wrapper.find('aside').exists()).toBe(false)
	})

	it('emits background color and tight crop updates', async () => {
		const wrapper = mount(SetlistPerformanceSettingsPanel, {
			props: {
				open: true,
				setlistName: '',
				showSetlistScope: false,
				isSetlistOverride: false,
				backgroundColor: '',
				tightCrop: false,
			},
		})

		// Setlist scope section is hidden in single-song mode
		expect(wrapper.find('button[data-settings-scope="setlist"]').exists()).toBe(false)

		await wrapper.find('[data-test="performance-bg-preset-#000000"]').trigger('click')
		expect(wrapper.emitted('update:backgroundColor')?.[0]).toEqual(['#000000'])

		await wrapper.find('[data-test="performance-bg-custom"]').setValue('#123456')
		expect(wrapper.emitted('update:backgroundColor')?.[1]).toEqual(['#123456'])

		await wrapper.find('[data-test="performance-tight-crop-toggle"]').trigger('click')
		expect(wrapper.emitted('update:tightCrop')?.[0]).toEqual([true])

		await wrapper.find('[data-test="performance-score-mode-night"]').trigger('click')
		expect(wrapper.emitted('update:scoreColorMode')?.[0]).toEqual(['night'])

		await wrapper.find('[data-test="performance-score-mode-night-sepia"]').trigger('click')
		expect(wrapper.emitted('update:scoreColorMode')?.[1]).toEqual(['night-sepia'])

		await wrapper.find('[data-test="performance-score-brightness"]').setValue('70')
		expect(wrapper.emitted('update:scoreBrightness')?.[0]).toEqual([70])
	})
})
