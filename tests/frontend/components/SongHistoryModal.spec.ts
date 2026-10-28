import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import SongHistoryModal from '../../../src/components/SongHistoryModal.vue'

vi.mock('@nextcloud/axios')
vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))
vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (path: string) => path,
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string, vars?: Record<string, any>) => {
		if (!vars) return text
		let res = text
		for (const [k, v] of Object.entries(vars)) {
			res = res.replace(`{${k}}`, String(v))
		}
		return res
	},
	getLanguage: () => 'en',
	isRTL: () => false,
}))

describe('SongHistoryModal.vue', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
		document.body.innerHTML = ''
	})

	it('renders dialog when open is false as hidden', () => {
		const wrapper = mount(SongHistoryModal, {
			props: {
				open: false,
				songId: 10,
				songTitle: 'Test Song',
			},
		})
		expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
	})

	it('fetches and displays history timeline entries', async () => {
		const mockEntries = [
			{
				id: 2,
				libraryId: 1,
				songId: 10,
				songUuid: 'uuid-10',
				userId: 'alice',
				action: 'updated',
				summary: 'Title: "Old" → "New"',
				snapshot: { title: 'New', rating: 5, difficulty: 2 },
				createdAt: 1700010000,
			},
			{
				id: 1,
				libraryId: 1,
				songId: 10,
				songUuid: 'uuid-10',
				userId: 'bob',
				action: 'created',
				summary: 'Song created',
				snapshot: { title: 'Old', rating: 3, difficulty: 1 },
				createdAt: 1700000000,
			},
		]

		vi.mocked(axios.get).mockResolvedValueOnce({
			data: { ocs: { data: mockEntries } },
		} as any)

		mount(SongHistoryModal, {
			props: {
				open: true,
				songId: 10,
				songTitle: 'Test Song',
			},
			attachTo: document.body,
		})

		await flushPromises()

		expect(document.body.textContent).toContain('Song History & Revisions: Test Song')
		expect(document.body.textContent).toContain('Current')
		expect(document.body.textContent).toContain('Title: "Old" → "New"')
		expect(document.body.textContent).toContain('Song created')
		expect(document.body.textContent).toContain('by alice')
		expect(document.body.textContent).toContain('by bob')
	})

	it('reverts to selected revision when confirmed', async () => {
		const mockEntries = [
			{
				id: 2,
				libraryId: 1,
				songId: 10,
				songUuid: 'uuid-10',
				userId: 'alice',
				action: 'updated',
				summary: 'Title: "Old" → "New"',
				snapshot: { title: 'New' },
				createdAt: 1700010000,
			},
			{
				id: 1,
				libraryId: 1,
				songId: 10,
				songUuid: 'uuid-10',
				userId: 'bob',
				action: 'created',
				summary: 'Song created',
				snapshot: { title: 'Old' },
				createdAt: 1700000000,
			},
		]

		vi.mocked(axios.get).mockResolvedValueOnce({
			data: { ocs: { data: mockEntries } },
		} as any)

		vi.mocked(axios.post).mockResolvedValueOnce({
			data: { ocs: { data: { songs: [{ id: 10, title: 'Old' }] } } },
		} as any)

		// Mock window.confirm
		window.confirm = vi.fn().mockReturnValue(true)

		const wrapper = mount(SongHistoryModal, {
			props: {
				open: true,
				songId: 10,
				songTitle: 'Test Song',
			},
			attachTo: document.body,
		})

		await flushPromises()

		// Click the revert button on the older entry (index 1)
		const revertButtons = Array.from(document.body.querySelectorAll('button')).filter((b) =>
			b.textContent?.includes('Revert'),
		)
		expect(revertButtons.length).toBeGreaterThan(0)
		revertButtons[0].click()

		await flushPromises()

		// Nextcloud confirmation dialog is displayed
		expect(document.body.textContent).toContain('Revert song revision')
		const confirmBtn = Array.from(document.body.querySelectorAll('button')).find((b) =>
			b.textContent?.includes('Revert to this revision'),
		)
		expect(confirmBtn).toBeDefined()
		confirmBtn!.click()

		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/apps/sheetmusic/api/songs/10/revert/1')
		expect(showSuccess).toHaveBeenCalled()
		expect(wrapper.emitted('reverted')).toBeTruthy()
		expect(wrapper.emitted('update:open')?.[0]).toEqual([false])
	})

	it('truncates long song title in header and shows single revision notice', async () => {
		const longTitle = 'Ludwig van Beethoven - Symphony No. 5 in C minor, Op. 67 (Full Orchestral Score with Annotations)'
		const mockEntries = [
			{
				id: 1,
				libraryId: 1,
				songId: 10,
				songUuid: 'uuid-10',
				userId: 'alice',
				action: 'updated',
				summary: 'Title updated',
				snapshot: { title: longTitle },
				createdAt: 1700010000,
			},
		]

		vi.mocked(axios.get).mockResolvedValueOnce({
			data: { ocs: { data: mockEntries } },
		} as any)

		mount(SongHistoryModal, {
			props: {
				open: true,
				songId: 10,
				songTitle: longTitle,
			},
			attachTo: document.body,
		})

		await flushPromises()

		// Title should be truncated in dialog name
		expect(document.body.textContent).toContain('…')
		// Single revision notice should be present
		expect(document.body.textContent).toContain('This is the only revision recorded for this song')
		// Full song title should be visible in the song title header block
		expect(document.body.textContent).toContain(longTitle)
	})

	it('displays additional dynamic fields and custom attributes in expanded details', async () => {
		const mockEntries = [
			{
				id: 1,
				libraryId: 1,
				songId: 10,
				songUuid: 'uuid-10',
				userId: 'alice',
				action: 'created',
				summary: 'Song created',
				snapshot: {
					title: 'Dynamic Song',
					rating: 4,
					tempo: 120,
					keySignature: 'Eb Major',
					attributes: {
						copyrightNotice: 'Public Domain 2026',
						ccliNumber: '1234567',
					},
				},
				createdAt: 1700010000,
			},
		]

		vi.mocked(axios.get).mockResolvedValueOnce({
			data: { ocs: { data: mockEntries } },
		} as any)

		mount(SongHistoryModal, {
			props: {
				open: true,
				songId: 10,
				songTitle: 'Dynamic Song',
			},
			attachTo: document.body,
		})

		await flushPromises()

		// Click "Show details" button to expand snapshot details
		const showDetailsBtn = Array.from(document.querySelectorAll('button')).find(b => b.getAttribute('title')?.includes('Show details'))
		expect(showDetailsBtn).toBeDefined()
		showDetailsBtn?.click()
		await flushPromises()

		expect(document.body.textContent).toContain('Tempo:')
		expect(document.body.textContent).toContain('120')
		expect(document.body.textContent).toContain('Key Signature:')
		expect(document.body.textContent).toContain('Eb Major')
		expect(document.body.textContent).toContain('Copyright Notice:')
		expect(document.body.textContent).toContain('Public Domain 2026')
		expect(document.body.textContent).toContain('Ccli Number:')
		expect(document.body.textContent).toContain('1234567')
	})
})
