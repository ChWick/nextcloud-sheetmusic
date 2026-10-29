import { describe, expect, it } from 'vitest'
import { config, mount } from '@vue/test-utils'
import SongPdfVersionsModal, {
	formatFileSize,
	formatTimestamp,
	type FileVersionInfo,
} from '../../../src/components/song-pdf/SongPdfVersionsModal.vue'

// Avoid teleporting NcDialog to body so wrapper.find queries work without mocks
config.global.stubs = { teleport: true }

describe('SongPdfVersionsModal.vue', () => {
	describe('helper functions', () => {
		it('formats file sizes accurately', () => {
			expect(formatFileSize(500)).toBe('500 B')
			expect(formatFileSize(1024)).toBe('1.0 KB')
			expect(formatFileSize(1536)).toBe('1.5 KB')
			expect(formatFileSize(1048576)).toBe('1.0 MB')
			expect(formatFileSize(2621440)).toBe('2.5 MB')
		})

		it('formats timestamp to local date string or empty string', () => {
			expect(formatTimestamp(0)).toBe('')
			const formatted = formatTimestamp(1700000000)
			expect(typeof formatted).toBe('string')
			expect(formatted.length).toBeGreaterThan(0)
		})
	})

	describe('component rendering and actions', () => {
		const sampleVersions: FileVersionInfo[] = [
			{ revision: 101, timestamp: 1700000000, size: 2048 },
			{ revision: 102, timestamp: 1700010000, size: 4096000 },
		]

		it('does not render dialog content when closed', () => {
			const wrapper = mount(SongPdfVersionsModal, {
				props: {
					open: false,
					versions: sampleVersions,
				},
			})
			expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
		})

		it('renders loading state when isLoading is true', () => {
			const wrapper = mount(SongPdfVersionsModal, {
				props: {
					open: true,
					isLoading: true,
					versions: [],
				},
			})
			expect(wrapper.text()).toContain('Loading version history…')
		})

		it('renders empty state when versions list is empty', () => {
			const wrapper = mount(SongPdfVersionsModal, {
				props: {
					open: true,
					isLoading: false,
					versions: [],
				},
			})
			expect(wrapper.text()).toContain('No previous versions found for this file.')
		})

		it('renders unsaved changes notice and emits discard-unsaved when clicked', async () => {
			const wrapper = mount(SongPdfVersionsModal, {
				props: {
					open: true,
					isLoading: false,
					versions: [],
					hasUnsavedChanges: true,
				},
			})
			expect(wrapper.text()).toContain('You have unsaved changes in this editing session')
			const discardBtn = wrapper.findAll('button').find((b) => b.text().includes('Discard unsaved changes'))
			expect(discardBtn).toBeDefined()
			await discardBtn!.trigger('click')
			expect(wrapper.emitted('discard-unsaved')).toHaveLength(1)
		})

		it('renders version list with dates, file sizes, and action buttons', () => {
			const wrapper = mount(SongPdfVersionsModal, {
				props: {
					open: true,
					isLoading: false,
					versions: sampleVersions,
				},
			})
			expect(wrapper.text()).toContain('2.0 KB')
			expect(wrapper.text()).toContain('3.9 MB')
			const buttons = wrapper.findAll('button')
			const revertButtons = buttons.filter((b) => b.text().includes('Revert to this version'))
			expect(revertButtons).toHaveLength(2)
		})

		it('emits revert when revert button is clicked', async () => {
			const wrapper = mount(SongPdfVersionsModal, {
				props: {
					open: true,
					isLoading: false,
					versions: sampleVersions,
				},
			})
			const revertButtons = wrapper.findAll('button').filter((b) => b.text().includes('Revert to this version'))
			await revertButtons[0].trigger('click')

			expect(wrapper.emitted('revert')).toBeTruthy()
			expect(wrapper.emitted('revert')![0]).toEqual([101])
		})

		it('disables revert buttons and displays Reverting… when isReverting is true', () => {
			const wrapper = mount(SongPdfVersionsModal, {
				props: {
					open: true,
					isLoading: false,
					isReverting: true,
					versions: sampleVersions,
				},
			})
			const revertButtons = wrapper.findAll('button').filter((b) => b.text().includes('Reverting…'))
			expect(revertButtons).toHaveLength(2)
			expect(revertButtons[0].attributes('disabled')).toBeDefined()
		})

		it('emits update:open false when Close button is clicked', async () => {
			const wrapper = mount(SongPdfVersionsModal, {
				props: {
					open: true,
					isLoading: false,
					versions: sampleVersions,
				},
			})
			const closeBtn = wrapper.findAll('button').find((b) => b.text().includes('Close'))
			expect(closeBtn).toBeDefined()
			await closeBtn!.trigger('click')

			expect(wrapper.emitted('update:open')).toBeTruthy()
			expect(wrapper.emitted('update:open')![0]).toEqual([false])
		})
	})
})

