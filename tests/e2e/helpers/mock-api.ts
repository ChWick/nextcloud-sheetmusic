import type { Page } from '@playwright/test'

export const mockLibraries = [
	{ id: 1, path: '/dav/files/admin/Noten/MobileSheets.db' },
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

export const mockSongs = [
	{
		id: 5,
		title: 'Symphony No. 5',
		composers: [mockComposers[0]],
		books: [mockBooks[0]],
		collections: [mockCollections[0]],
		artists: [mockArtists[0]],
		files: [{ id: 50, path: '/dav/files/admin/Noten/Beethoven - Symphony 5.pdf' }],
	},
	{
		id: 6,
		title: 'Requiem in D minor',
		composers: [mockComposers[1]],
		books: [mockBooks[1]],
		collections: [mockCollections[1]],
		artists: [mockArtists[1]],
		files: [{ id: 51, path: '/dav/files/admin/Noten/Mozart - Requiem.pdf' }],
	},
]

export async function setupMockApi(page: Page) {
	// Mock libraries list endpoint
	await page.route('**/api/libraries', async (route) => {
		if (route.request().method() === 'GET') {
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
		} else {
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
		}
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
					},
				},
			}),
		})
	})
}
