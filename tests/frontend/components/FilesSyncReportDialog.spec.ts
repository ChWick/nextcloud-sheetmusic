import type { FileLogEntry } from '../../../src/types/song'

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FilesSyncReportDialog from '../../../src/components/files/FilesSyncReportDialog.vue'

describe('FilesSyncReportDialog', () => {
	const mockLog: FileLogEntry[] = [
		{ songId: 1, songTitle: 'Song 1', filePath: '/f1.pdf', message: 'OK', level: 'info', code: 'path-current', fileId: 10 },
		{ songId: 2, songTitle: 'Song 2', filePath: '/f2.pdf', message: 'Missing', level: 'warning', code: 'source-missing', fileId: null },
		{ songId: 0, songTitle: '', filePath: '', message: 'Error', level: 'error', code: 'hashcodes-unreadable', fileId: null },
	]

	const globalStubs = {
		global: {
			stubs: {
				NcDialog: {
					template: '<div class="nc-dialog-stub"><slot /></div>',
				},
				NcButton: {
					template: '<button :class="variant" @click="$emit(\'click\')"><slot /></button>',
					props: ['variant'],
				},
			},
		},
	}

	it('does not render content when open is false', () => {
		const wrapper = mount(FilesSyncReportDialog, {
			props: {
				open: false,
				isRunning: false,
				runError: '',
				log: mockLog,
				filter: 'info',
			},
			...globalStubs,
		})

		expect(wrapper.find('.nc-dialog-stub').exists()).toBe(false)
	})

	it('renders running indicator when isRunning is true', () => {
		const wrapper = mount(FilesSyncReportDialog, {
			props: {
				open: true,
				isRunning: true,
				runError: '',
				log: [],
				filter: 'info',
			},
			...globalStubs,
		})

		expect(wrapper.text()).toContain('Syncing file paths and metadata…')
	})

	it('renders runError when runError is present', () => {
		const wrapper = mount(FilesSyncReportDialog, {
			props: {
				open: true,
				isRunning: false,
				runError: 'Failed to connect to server',
				log: [],
				filter: 'info',
			},
			...globalStubs,
		})

		expect(wrapper.text()).toContain('Failed to connect to server')
	})

	it('filters log items based on level filter prop', () => {
		const wrapper = mount(FilesSyncReportDialog, {
			props: {
				open: true,
				isRunning: false,
				runError: '',
				log: mockLog,
				filter: 'warning',
			},
			...globalStubs,
		})

		// filter 'warning' includes warning and error, excludes info
		expect(wrapper.text()).toContain('Song 2')
		expect(wrapper.text()).toContain('Library')
		expect(wrapper.text()).not.toContain('Song 1')
	})

	it('emits update:filter on filter button click', async () => {
		const wrapper = mount(FilesSyncReportDialog, {
			props: {
				open: true,
				isRunning: false,
				runError: '',
				log: mockLog,
				filter: 'info',
			},
			...globalStubs,
		})

		const filterButtons = wrapper.findAll('button')
		// Find "Errors only" filter button
		const errorFilterBtn = filterButtons.find((btn) => btn.text().includes('Errors only'))
		expect(errorFilterBtn).toBeDefined()
		await errorFilterBtn!.trigger('click')

		expect(wrapper.emitted('update:filter')).toBeTruthy()
		expect(wrapper.emitted('update:filter')![0]).toEqual(['error'])
	})
})
