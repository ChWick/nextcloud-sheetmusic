import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FilesSyncStatsChips from '../../../src/components/files/FilesSyncStatsChips.vue'
import type { SyncStats } from '../../../src/utils/file-sync'

describe('FilesSyncStatsChips', () => {
	it('renders stats chips correctly', () => {
		const stats: SyncStats = {
			files: 5,
			moved: 2,
			updated: 1,
			current: 2,
			warnings: 1,
			errors: 0,
		}

		const wrapper = mount(FilesSyncStatsChips, {
			props: { stats },
		})

		expect(wrapper.text()).toContain('5 files checked')
		expect(wrapper.text()).toContain('2 moved')
		expect(wrapper.text()).toContain('1 document properties updated')
		expect(wrapper.text()).toContain('2 already up to date')
		expect(wrapper.text()).toContain('⚠ 1 warnings')
		expect(wrapper.text()).not.toContain('Everything is up to date')
	})

	it('renders "Everything is up to date" notice when zero warnings and errors', () => {
		const stats: SyncStats = {
			files: 3,
			moved: 0,
			updated: 0,
			current: 3,
			warnings: 0,
			errors: 0,
		}

		const wrapper = mount(FilesSyncStatsChips, {
			props: { stats },
		})

		expect(wrapper.text()).toContain('Everything is up to date: file paths and metadata.')
	})
})
