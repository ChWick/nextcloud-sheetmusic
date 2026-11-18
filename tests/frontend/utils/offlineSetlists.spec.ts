import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	getOfflineSetlistMap,
	isOfflineSetlistsSupported,
	isSetlistAvailableOffline,
	OFFLINE_SETLIST_CACHE,
	OFFLINE_STORAGE_KEY,
	removeSetlistOffline,
	syncAllOfflineSetlists,
	syncSetlistOffline,
} from '../../../src/utils/offlineSetlists'

const mockIsStandalone = vi.fn()
const mockFetchSetlist = vi.fn()
const mockFetchSetlistFiles = vi.fn()
const mockApiGet = vi.fn()

vi.mock('../../../src/api/client', () => ({
	apiGet: (...args: unknown[]) => mockApiGet(...args),
}))

vi.mock('../../../src/utils/pwa', () => ({
	isStandaloneDisplayMode: () => mockIsStandalone(),
	cacheIntoAppShell: vi.fn(),
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (path: string) => `/ocs/v2.php${path}`,
	generateUrl: (path: string) => `/index.php${path}`,
}))

vi.mock('../../../src/api/setlists', () => ({
	fetchSetlist: (...args: unknown[]) => mockFetchSetlist(...args),
	fetchSetlistFiles: (...args: unknown[]) => mockFetchSetlistFiles(...args),
	getSetlistFileContentUrl: (_libId: number, setlistId: number, fileId: number, version?: number) =>
		`/ocs/v2.php/apps/sheetmusic/api/libraries/1/setlists/${setlistId}/files/${fileId}/content${version ? `?v=${version}` : ''}`,
}))

describe('offlineSetlists', () => {
	const cacheStore = new Map<string, Response>()
	const fakeCache = {
		match: vi.fn(async (url: string) => cacheStore.get(url)),
		put: vi.fn(async (url: string, res: Response) => {
			cacheStore.set(url, res)
		}),
		delete: vi.fn(async (url: string) => cacheStore.delete(url)),
	}

	beforeEach(() => {
		vi.clearAllMocks()
		cacheStore.clear()
		localStorage.removeItem(OFFLINE_STORAGE_KEY)
		mockIsStandalone.mockReturnValue(true)

		Object.defineProperty(globalThis, 'caches', {
			configurable: true,
			value: {
				open: vi.fn(async (name: string) => {
					expect(name).toBe(OFFLINE_SETLIST_CACHE)
					return fakeCache
				}),
			},
		})
		Object.defineProperty(window.navigator, 'serviceWorker', {
			configurable: true,
			value: {},
		})
		globalThis.fetch = vi.fn(async () => new Response('ok', { status: 200 })) as any
	})

	afterEach(() => {
		localStorage.removeItem(OFFLINE_STORAGE_KEY)
	})

	it('is supported in both regular browser tabs and installed PWA standalone mode', () => {
		mockIsStandalone.mockReturnValue(false)
		expect(isOfflineSetlistsSupported()).toBe(true)

		mockIsStandalone.mockReturnValue(true)
		expect(isOfflineSetlistsSupported()).toBe(true)
	})

	it('caches all song PDFs, song annotations, setlist files, and setlist file annotations', async () => {
		mockFetchSetlist.mockResolvedValue({
			id: 7,
			name: 'Sunday Service',
			items: [{ id: 1, songId: 42, position: 0 }],
		})
		mockFetchSetlistFiles.mockResolvedValue([
			{ id: 9, filename: 'Notes.pdf', updatedAt: 123 },
		])

		const songs = [
			{
				id: 42,
				title: 'Sonata',
				files: [{ id: 100, path: '/remote.php/dav/files/admin/Sonata.pdf', updatedAt: 456 }],
			},
		] as any[]

		const progressCalls: Array<[number, number]> = []
		const record = await syncSetlistOffline({
			libraryId: 1,
			setlistId: 7,
			songs,
			onProgress: (done, total) => progressCalls.push([done, total]),
		})

		expect(record.fileCount).toBe(2)
		expect(isSetlistAvailableOffline(1, 7)).toBe(true)
		expect(cacheStore.has('/remote.php/dav/files/admin/Sonata.pdf?v=100%40456')).toBe(true)
		expect(cacheStore.has('/ocs/v2.php/apps/sheetmusic/api/songs/42/annotations?fileId=100')).toBe(true)
		expect(cacheStore.has('/ocs/v2.php/apps/sheetmusic/api/libraries/1/setlists/7/files/9/content?v=123')).toBe(true)
		expect(cacheStore.has('/ocs/v2.php/apps/sheetmusic/api/libraries/1/setlists/7/files/9/annotations')).toBe(true)
		expect(progressCalls[0][0]).toBe(0)
		expect(progressCalls[progressCalls.length - 1][0]).toBe(progressCalls[progressCalls.length - 1][1])
	})

	it('preserves shared URLs when removing one of two offline setlists', async () => {
		mockFetchSetlist.mockResolvedValue({
			id: 7,
			name: 'Setlist A',
			items: [{ id: 1, songId: 42, position: 0 }],
		})
		mockFetchSetlistFiles.mockResolvedValue([])

		const songs = [
			{
				id: 42,
				title: 'Shared Song',
				files: [{ id: 100, path: '/remote.php/dav/files/admin/Shared.pdf', updatedAt: 1 }],
			},
		] as any[]

		await syncSetlistOffline({ libraryId: 1, setlistId: 7, songs })
		mockFetchSetlist.mockResolvedValue({
			id: 8,
			name: 'Setlist B',
			items: [{ id: 2, songId: 42, position: 0 }],
		})
		await syncSetlistOffline({ libraryId: 1, setlistId: 8, songs })

		await removeSetlistOffline(1, 7)
		expect(isSetlistAvailableOffline(1, 7)).toBe(false)
		expect(isSetlistAvailableOffline(1, 8)).toBe(true)
		expect(cacheStore.has('/remote.php/dav/files/admin/Shared.pdf?v=100%401')).toBe(true)

		await removeSetlistOffline(1, 8)
		expect(getOfflineSetlistMap()).toEqual({})
		expect(cacheStore.has('/remote.php/dav/files/admin/Shared.pdf?v=100%401')).toBe(false)
	})

	it('syncAllOfflineSetlists skips already-cached versioned PDFs and refreshes metadata', async () => {
		const songs = [
			{
				id: 42,
				title: 'Sonata',
				files: [{ id: 100, path: '/remote.php/dav/files/admin/Sonata.pdf', updatedAt: 456 }],
			},
		] as any[]

		mockFetchSetlist.mockResolvedValue({
			id: 7,
			name: 'Sunday Service',
			items: [{ id: 1, songId: 42, position: 0 }],
		})
		mockFetchSetlistFiles.mockResolvedValue([])
		await syncSetlistOffline({ libraryId: 1, setlistId: 7, songs })

		const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>
		fetchMock.mockClear()

		mockApiGet.mockResolvedValue({
			data: { ocs: { data: { songs } } },
		})

		await syncAllOfflineSetlists()

		const fetchedUrls = fetchMock.mock.calls.map(c => c[0] as string)
		expect(fetchedUrls).not.toContain('/remote.php/dav/files/admin/Sonata.pdf?v=100%40456')
		expect(fetchedUrls).toContain('/ocs/v2.php/apps/sheetmusic/api/songs/42/annotations?fileId=100')
	})
})
