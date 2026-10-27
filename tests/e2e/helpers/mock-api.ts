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

export async function setupMockApi(page: Page) {
	const currentLibraries = [...mockLibraries]

	// Mock libraries list endpoint & library creation
	await page.route('**/api/libraries**', async (route) => {
		const url = new URL(route.request().url())
		const normalizedPath = url.pathname.replace(/\/+$/, '')
		if (normalizedPath.endsWith('/api/libraries')) {
			if (route.request().method() === 'POST') {
				let postData: any = {}
				try {
					postData = route.request().postDataJSON() || {}
				} catch {
					// empty
				}
				const rawPath = postData.path || '/SheetMusic/NewLibrary'
				const path = rawPath.endsWith('.db')
					? (rawPath.substring(0, rawPath.lastIndexOf('/')) || '/')
					: rawPath
				const newId = currentLibraries.length > 0 ? Math.max(...currentLibraries.map(l => l.id)) + 1 : 1
				const newLib = { id: newId, path }
				currentLibraries.push(newLib)
				await route.fulfill({
					status: 201,
					contentType: 'application/json',
					body: JSON.stringify({
						ocs: {
							meta: { status: 'ok', statuscode: 201 },
							data: { id: newId, path },
						},
					}),
				})
				return
			}
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ocs: {
						meta: { status: 'ok', statuscode: 200 },
						data: { libraries: currentLibraries },
					},
				}),
			})
			return
		}
		await route.fallback()
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

	// Mock file deletion endpoint (used when saving modified/cropped PDF)
	await page.route('**/api/songs/*/file/*/delete**', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ocs: {
					meta: { status: 'ok', statuscode: 200 },
					data: { success: true },
				},
			}),
		})
	})

	// Mock file upload / update endpoint (used when saving modified/cropped PDF)
	await page.route('**/api/libraries/*/songs/*/file/upload**', async (route) => {
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
					},
				},
			}),
		})
	})

	let currentMockVersions = [
		{
			version: '1700000000',
			revision: 1700000000,
			timestamp: 1700000000,
			humanReadableTimestamp: '2023-11-14 22:13:20',
			size: 2048,
			name: 'Beethoven - Symphony 5.pdf',
		},
	]

	// Mock file versions list
	await page.route('**/api/libraries/*/songs/*/file/*/versions', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ocs: {
					meta: { status: 'ok', statuscode: 200 },
					data: currentMockVersions,
				},
			}),
		})
	})

	// Mock file version revert endpoint
	await page.route('**/api/libraries/*/songs/*/file/*/versions/*/revert', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ocs: {
					meta: { status: 'ok', statuscode: 200 },
					data: {
						songs: mockSongs.map((s) => s.id === 5 ? { ...s, lastModified: Date.now() } : s),
						composers: mockComposers,
						books: mockBooks,
					},
				},
			}),
		})
	})

	// Mock file in-place update endpoint
	await page.route('**/api/libraries/*/songs/*/file/*/update', async (route) => {
		currentMockVersions.push({
			version: String(Date.now()),
			revision: Date.now(),
			timestamp: Math.floor(Date.now() / 1000),
			humanReadableTimestamp: 'Just now',
			size: 2048,
			name: 'Beethoven - Symphony 5.pdf',
		})
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ocs: {
					meta: { status: 'ok', statuscode: 200 },
					data: {
						songs: mockSongs.map((s) => s.id === 5 ? { ...s, lastModified: Date.now() } : s),
						composers: mockComposers,
						books: mockBooks,
					},
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

	// Mock integrity audit endpoint
	await page.route('**/api/libraries/*/integrity', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ocs: {
					meta: { status: 'ok', statuscode: 200 },
					data: {
						issues: [
							{
								type: 'invalid_naming_rule',
								severity: 'warning',
								message: 'File path does not match standard naming rule.',
								songId: 5,
								songTitle: 'Symphony No. 5',
								fileId: 50,
								path: 'Noten/OldPath.pdf',
								suggestedPath: 'Noten/Beethoven/Beethoven - Symphony 5.pdf',
								fixable: true,
							},
						],
					},
				},
			}),
		})
	})

	// Mock fix integrity issue endpoint
	await page.route('**/api/libraries/*/integrity/fix', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ocs: {
					meta: { status: 'ok', statuscode: 200 },
					data: {
						success: true,
						fixedCount: 1,
						message: 'File path updated according to naming rules.',
					},
				},
			}),
		})
	})

	// Mock fix-all integrity issues endpoint
	await page.route('**/api/libraries/*/integrity/fix-all', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ocs: {
					meta: { status: 'ok', statuscode: 200 },
					data: {
						success: true,
						fixedCount: 1,
						message: 'Applied autofix to 1 issue(s).',
					},
				},
			}),
		})
	})

	// Mock song history endpoint
	await page.route('**/api/songs/*/history', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ocs: {
					meta: { status: 'ok', statuscode: 200 },
					data: [
						{
							id: 102,
							libraryId: 1,
							songId: 5,
							songUuid: 'uuid-5',
							userId: 'admin',
							action: 'updated',
							summary: 'Title: "Symphony No. 5 in C Minor" → "Symphony No. 5"',
							snapshot: { title: 'Symphony No. 5', rating: 5 },
							createdAt: 1700010000,
						},
						{
							id: 101,
							libraryId: 1,
							songId: 5,
							songUuid: 'uuid-5',
							userId: 'admin',
							action: 'created',
							summary: 'Song created',
							snapshot: { title: 'Symphony No. 5 in C Minor', rating: 4 },
							createdAt: 1700000000,
						},
					],
				},
			}),
		})
	})

	// Mock revert endpoint
	await page.route('**/api/songs/*/revert/*', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ocs: {
					meta: { status: 'ok', statuscode: 200 },
					data: {
						songs: mockSongs.map((s) => s.id === 5 ? { ...s, title: 'Symphony No. 5 in C Minor', lastModified: Date.now() } : s),
						composers: mockComposers,
						books: mockBooks,
					},
				},
			}),
		})
	})

	// Mock deleted songs endpoint
	await page.route('**/api/libraries/*/deleted-songs', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ocs: {
					meta: { status: 'ok', statuscode: 200 },
					data: [
						{
							id: 99,
							uuid: 'uuid-99',
							title: 'Moonlight Sonata',
							composers: ['Ludwig van Beethoven'],
							fileCount: 1,
							deletedAt: 1700005000,
							rating: 5,
						},
					],
				},
			}),
		})
	})

	// Mock restore endpoint
	await page.route('**/api/songs/*/restore', async (route) => {
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
					},
				},
			}),
		})
	})
}
