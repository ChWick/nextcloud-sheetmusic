import type { Page } from '@playwright/test'

export const mockLibraries = [
	{ id: 1, path: '/dav/files/admin/Noten/MobileSheets.db' },
	{ id: 2, path: '/dav/files/admin/Noten/Secondary.db' },
]

export const mockComposers = [
	{ id: 1, name: 'Ludwig van Beethoven' },
	{ id: 2, name: 'Wolfgang Amadeus Mozart' },
]

export const mockBooks = [
	{ id: 10, title: 'Real Book Vol 1' },
	{ id: 11, title: 'Classical Masters' },
]

export const mockCollections = [
	{ id: 100, name: 'Symphonies' },
	{ id: 101, name: 'Chamber Music' },
]

export const mockArtists = [
	{ id: 1000, name: 'Berliner Philharmoniker' },
	{ id: 1001, name: 'Vienna Philharmonic' },
]

export const mockGenres = [
	{ id: 2000, name: 'Classical' },
	{ id: 2001, name: 'Baroque' },
]

export const mockKeys = [
	{ id: 3000, name: 'C Major' },
	{ id: 3001, name: 'D Minor' },
]

export const mockCustomGroups = [
	{ id: 4000, name: 'Main Choir' },
	{ id: 4001, name: 'Brass Band' },
]

export const mockYears = [
	{ id: 5000, year: 1959, name: '1959' },
	{ id: 5001, year: 1808, name: '1808' },
]

export const mockSignatures = [
	{ id: 6000, name: '4/4' },
	{ id: 6001, name: '3/4' },
]

export const mockSourceTypes = [
	{ id: 7000, name: 'Lead Sheet', type: 'Lead Sheet' },
	{ id: 7001, name: 'Score', type: 'Score' },
]

export const mockSongs = [
	{
		id: 5,
		title: 'Symphony No. 5',
		composers: [mockComposers[0]],
		books: [mockBooks[0]],
		collections: [mockCollections[0]],
		artists: [mockArtists[0]],
		genres: [mockGenres[0]],
		keys: [mockKeys[0]],
		customGroups: [mockCustomGroups[0]],
		years: [mockYears[1]],
		signatures: [mockSignatures[0]],
		sourceTypes: [mockSourceTypes[0]],
		notes: [{ id: 1, songId: 5, note: 'Play allegro con brio', title: 'Tempo note' }],
		tempos: [{ id: 1, songId: 5, tempo: 120 }],
		files: [{ id: 50, path: '/dav/files/admin/Noten/Beethoven - Symphony 5.pdf' }],
	},
	{
		id: 6,
		title: 'Requiem in D minor',
		composers: [mockComposers[1]],
		books: [mockBooks[1]],
		collections: [mockCollections[1]],
		artists: [mockArtists[1]],
		genres: [mockGenres[0]],
		keys: [mockKeys[1]],
		customGroups: [mockCustomGroups[1]],
		years: [mockYears[0]],
		signatures: [mockSignatures[1]],
		sourceTypes: [mockSourceTypes[1]],
		notes: [],
		tempos: [],
		files: [{ id: 51, path: '/dav/files/admin/Noten/Mozart - Requiem.pdf' }],
	},
]

export const mockSyncLog = [
	{ songId: 5, level: 'info', message: 'Checked 1 file for Symphony No. 5.' },
	{ songId: 6, level: 'warning', message: 'Found extra file path Requiem.pdf.' },
	{ songId: 0, level: 'error', message: 'Missing file on disk: Beethoven - Missing.pdf' },
]

export async function setupMockApi(page: Page) {
	// Mock libraries list endpoint
	await page.route('**/api/libraries**', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ocs: {
					meta: { status: 'ok', statuscode: 200 },
					data: { libraries: mockLibraries },
				},
			}),
		})
	})

	// Mock songs / library data endpoint
	await page.route('**/api/songs**', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ocs: {
					meta: { status: 'ok', statuscode: 200 },
					data: {
						songs: mockSongs,
						composers: mockComposers,
						books: mockBooks,
						collections: mockCollections,
						artists: mockArtists,
						genres: mockGenres,
						keys: mockKeys,
						customGroups: mockCustomGroups,
						years: mockYears,
						signatures: mockSignatures,
						sourceTypes: mockSourceTypes,
						createdSongId: 99,
					},
				},
			}),
		})
	})

	// Mock sync library endpoint
	await page.route('**/api/sync-library', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ocs: {
					meta: { status: 'ok', statuscode: 200 },
					data: { log: mockSyncLog },
				},
			}),
		})
	})

	// Mock batch update songs endpoint
	await page.route('**/api/batch-update-songs', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ocs: {
					meta: { status: 'ok', statuscode: 200 },
					data: {
						songs: mockSongs,
						composers: mockComposers,
						books: mockBooks,
						collections: mockCollections,
						artists: mockArtists,
						genres: mockGenres,
						keys: mockKeys,
						customGroups: mockCustomGroups,
						years: mockYears,
						signatures: mockSignatures,
						sourceTypes: mockSourceTypes,
					},
				},
			}),
		})
	})

	// Mock entity creation endpoints
	await page.route('**/api/composers', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ocs: {
					meta: { status: 'ok', statuscode: 200 },
					data: { id: 999 },
				},
			}),
		})
	})

	// Mock PDF file downloads
	const minimalPdf = '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 300 144]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000052 00000 n \n0000000101 00000 n \ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF'
	await page.route('**/*.pdf*', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/pdf',
			body: Buffer.from(minimalPdf, 'utf-8'),
		})
	})
}
