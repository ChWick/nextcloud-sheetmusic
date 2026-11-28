import { expect, test, type Route } from '@playwright/test'
import { PDFDocument } from 'pdf-lib'
import { mockSongs, setupMockApi } from './helpers/mock-api'

async function createSinglePagePdfBytes(width = 600, height = 800): Promise<Buffer> {
	const doc = await PDFDocument.create()
	doc.addPage([width, height])
	return Buffer.from(await doc.save())
}

async function fulfillOcs(route: Route, data: unknown) {
	await route.fulfill({
		status: 200,
		contentType: 'application/json',
		body: JSON.stringify({
			ocs: { meta: { status: 'ok', statuscode: 200 }, data },
		}),
	})
}

test.describe('Performance Mode background preloading and flicker-free song transitions', () => {
	test('preloads next song in background and keeps previous score visible during slow song switches', async ({ page }) => {
		await setupMockApi(page)

		const song1Bytes = await createSinglePagePdfBytes(600, 800)
		const song2Bytes = await createSinglePagePdfBytes(640, 820)
		const song3Bytes = await createSinglePagePdfBytes(620, 840)

		const songsWithThird = [
			...mockSongs,
			{
				...mockSongs[1],
				id: 7,
				title: 'Brandenburg Concerto',
				files: [{ id: 52, path: '/dav/files/admin/Noten/Bach - Brandenburg.pdf' }],
			},
			{
				...mockSongs[1],
				id: 8,
				title: 'Toccata and Fugue',
				files: [{ id: 53, path: '/dav/files/admin/Noten/Bach - Toccata.pdf' }],
			},
		]

		const setlist = {
			id: 77,
			libraryId: 1,
			name: 'Transition Concert',
			itemCount: 4,
			items: [
				{ id: 1, setlistId: 77, songId: 5, position: 0, songTitle: 'Symphony No. 5', pageNum: 1 },
				{ id: 2, setlistId: 77, songId: 6, position: 1, songTitle: 'Requiem in D minor', pageNum: 1 },
				{ id: 3, setlistId: 77, songId: 7, position: 2, songTitle: 'Brandenburg Concerto', pageNum: 1 },
				{ id: 4, setlistId: 77, songId: 8, position: 3, songTitle: 'Toccata and Fugue', pageNum: 1 },
			],
		}

		// Override songs endpoint to include 4 songs so Song 4 is outside initial lookahead (+1, +2)
		await page.route('**/api/songs**', async (route) => {
			const url = route.request().url()
			if (url.includes('/api/songs/5/annotations')) {
				return fulfillOcs(route, [
					{
						id: 901,
						uuid: 'ann-song-1',
						songId: 5,
						fileId: 50,
						pageNumber: 0,
						layerIndex: 0,
						type: 'text',
						x: 0.2,
						y: 0.2,
						width: 0.3,
						height: 0.1,
						style: { color: '#ff0000', fontSize: 24 },
						data: { text: 'SONG 1 ONLY NOTE' },
					},
				])
			}
			if (url.includes('/annotations')) {
				return fulfillOcs(route, [])
			}
			return fulfillOcs(route, { songs: songsWithThird })
		})
		await page.route('**/api/libraries/*/setlists', (route) => fulfillOcs(route, [setlist]))
		await page.route('**/api/libraries/*/setlists/**', async (route) => {
			if (route.request().url().endsWith('/files')) {
				return fulfillOcs(route, [])
			}
			return fulfillOcs(route, setlist)
		})

		const fetchCounts = { song1: 0, song2: 0, song3: 0, song4: 0 }
		let releaseSong4!: () => void
		const song4Gate = new Promise<void>((resolve) => {
			releaseSong4 = resolve
		})

		await page.route('**/Beethoven*', async (route) => {
			fetchCounts.song1++
			await route.fulfill({ status: 200, contentType: 'application/pdf', body: song1Bytes })
		})
		await page.route('**/Mozart*', async (route) => {
			fetchCounts.song2++
			await route.fulfill({ status: 200, contentType: 'application/pdf', body: song2Bytes })
		})
		await page.route('**/Brandenburg*', async (route) => {
			fetchCounts.song3++
			await route.fulfill({ status: 200, contentType: 'application/pdf', body: song3Bytes })
		})
		await page.route('**/Toccata*', async (route) => {
			fetchCounts.song4++
			await song4Gate
			await route.fulfill({ status: 200, contentType: 'application/pdf', body: song3Bytes })
		})

		await page.goto('/#/library/1/setlists')
		await page.getByRole('button', { name: 'Play Setlist' }).click()

		const dialog = page.getByRole('dialog', { name: 'Transition Concert' })
		await expect(dialog).toBeVisible()
		await expect(dialog.locator('canvas.sheetmusic-pdf-page').first()).toBeVisible()

		// Song 1's annotation is visible on Song 1
		await expect(dialog).toContainText('SONG 1 ONLY NOTE')

		// Background preload should have fetched Song 2 (Mozart) automatically while viewing Song 1
		await expect.poll(() => fetchCounts.song2).toBe(1)

		// Advance to Song 2 -> should NOT trigger a second network fetch, should keep canvas visible,
		// and MUST NOT show Song 1's annotation on Song 2
		await page.keyboard.press('ArrowRight')
		await expect(dialog).toContainText('Requiem in D minor')
		await expect(dialog.locator('canvas.sheetmusic-pdf-page').first()).toBeVisible()
		await expect(dialog).not.toContainText('SONG 1 ONLY NOTE')
		expect(fetchCounts.song2).toBe(1)

		// Jump to Song 4 via Setlist drawer while Song 4's network response is still gated:
		// Previous song's canvas MUST remain visible while Song 4 is loading (no blanking/flicker)
		await dialog.getByRole('button', { name: /Setlist/i }).click()
		await dialog.getByRole('listitem').filter({ hasText: 'Toccata and Fugue' }).click()
		await expect(dialog).toContainText('Toccata and Fugue')
		await expect(dialog.locator('[data-test="file-preview-transition-loading"]')).toBeVisible()
		await expect(dialog.locator('canvas.sheetmusic-pdf-page').first()).toBeVisible()

		// Release Song 4 -> transition spinner disappears and new canvas renders
		releaseSong4()
		await expect(dialog.locator('[data-test="file-preview-transition-loading"]')).toBeHidden()
		await expect(dialog.locator('canvas.sheetmusic-pdf-page').first()).toBeVisible()
	})

	test('preserves page positions when flipping forward and backward in interleaved half-page mode', async ({ page }) => {
		await setupMockApi(page)

		const createMultiPagePdf = async (count: number) => {
			const doc = await PDFDocument.create()
			for (let i = 0; i < count; i++) {
				doc.addPage([600, 800])
			}
			return Buffer.from(await doc.save())
		}
		const song1Bytes = await createMultiPagePdf(3)
		const song2Bytes = await createMultiPagePdf(3)

		const setlist = {
			id: 78,
			libraryId: 1,
			name: 'Interleave Concert',
			itemCount: 2,
			items: [
				{ id: 1, setlistId: 78, songId: 5, position: 0, songTitle: 'Symphony No. 5', pageNum: 1 },
				{ id: 2, setlistId: 78, songId: 6, position: 1, songTitle: 'Requiem in D minor', pageNum: 1 },
			],
		}

		await page.route('**/api/libraries/*/setlists', (route) => fulfillOcs(route, [setlist]))
		await page.route('**/api/libraries/*/setlists/**', async (route) => {
			if (route.request().url().endsWith('/files')) {
				return fulfillOcs(route, [])
			}
			return fulfillOcs(route, setlist)
		})
		await page.route('**/api/songs/*/annotations**', (route) => {
			if (route.request().url().includes('/api/songs/5/annotations')) {
				return fulfillOcs(route, [
					{
						id: 902,
						uuid: 'ann-interleave-s1p1',
						songId: 5,
						fileId: 50,
						pageNumber: 0,
						layerIndex: 0,
						type: 'text',
						x: 0.2,
						y: 0.2,
						width: 0.3,
						height: 0.1,
						style: { color: '#ff0000', fontSize: 24 },
						data: { text: 'SONG A PAGE 1 ANNOTATION' },
					},
				])
			}
			return fulfillOcs(route, [])
		})
		await page.route('**/Beethoven*', (route) => route.fulfill({ status: 200, contentType: 'application/pdf', body: song1Bytes }))
		await page.route('**/Mozart*', (route) => route.fulfill({ status: 200, contentType: 'application/pdf', body: song2Bytes }))

		await page.goto('/#/library/1/setlists')
		await page.getByRole('button', { name: 'Play Setlist' }).click()

		const dialog = page.getByRole('dialog', { name: 'Interleave Concert' })
		await expect(dialog).toBeVisible()

		// Switch to Two pages layout and enable Interleave
		const layoutSelect = dialog.locator('[data-test="performance-layout-select"]')
		await layoutSelect.locator('.vs__dropdown-toggle').click()
		await page.getByRole('option', { name: 'Two pages' }).click()
		await dialog.getByRole('button', { name: /Interleave last page/i }).click()

		const footer = dialog.locator('footer')
		await expect(footer).toContainText('Page 1–2 of 3')

		// Forward in half-page mode: [1,2] -> [3,2] -> [3,→1] -> Song 2 [2,1] -> Song 2 [2,3]
		await page.keyboard.press('ArrowRight')
		await expect(footer).toContainText('Page 3 / 2 of 3')

		await page.keyboard.press('ArrowRight')
		await expect(footer).toContainText('Page 3 / →1 of 3')
		await expect(dialog.locator('.sheetmusic-double-slot')).toHaveCount(2)

		await page.keyboard.press('ArrowRight')
		await expect(dialog).toContainText('Requiem in D minor')
		await expect(footer).toContainText('Page 2 / 1 of 3')

		await page.keyboard.press('ArrowRight')
		await expect(footer).toContainText('Page 2–3 of 3')

		// Backward in half-page mode: [2,3] -> [2,1] -> Song 1 [3,→1] -> [3,2] -> [1,2]
		await page.keyboard.press('ArrowLeft')
		await expect(footer).toContainText('Page 2 / 1 of 3')

		await page.keyboard.press('ArrowLeft')
		await expect(dialog).toContainText('Symphony No. 5')
		await expect(footer).toContainText('Page 3 / →1 of 3')
		await expect(dialog.locator('.sheetmusic-double-slot')).toHaveCount(2)
		// Song A page 1's annotation must NOT appear on Song B's interleaved page 1
		await expect(dialog).not.toContainText('SONG A PAGE 1 ANNOTATION')

		// Advancing again from cached [3 / →1] must still land on [2 / 1] (NOT [1–2])
		await page.keyboard.press('ArrowRight')
		await expect(dialog).toContainText('Requiem in D minor')
		await expect(footer).toContainText('Page 2 / 1 of 3')

		// Step back through Song A all the way to [1–2]
		await page.keyboard.press('ArrowLeft')
		await expect(footer).toContainText('Page 3 / →1 of 3')
		await expect(dialog).not.toContainText('SONG A PAGE 1 ANNOTATION')

		await page.keyboard.press('ArrowLeft')
		await expect(footer).toContainText('Page 3 / 2 of 3')

		await page.keyboard.press('ArrowLeft')
		await expect(footer).toContainText('Page 1–2 of 3')
		await expect(dialog).toContainText('SONG A PAGE 1 ANNOTATION')
	})

	test('supports multiple bookmarks on the same page (#32) and bounded bookmark setlist flow (#33)', async ({ page }) => {
		await setupMockApi(page)

		const doc = await PDFDocument.create()
		for (let i = 0; i < 6; i++) {
			doc.addPage([600, 800])
		}
		const sixPageBytes = Buffer.from(await doc.save())

		const setlist = {
			id: 79,
			libraryId: 1,
			name: 'Movements Concert',
			itemCount: 3,
			items: [
				{ id: 1, setlistId: 79, songId: 5, bookmarkId: 101, position: 0, songTitle: 'Symphony No. 5', bookmarkName: 'Prelude', pageNum: 1, endPage: 1 },
				{ id: 2, setlistId: 79, songId: 5, bookmarkId: 102, position: 1, songTitle: 'Symphony No. 5', bookmarkName: 'Fugue', pageNum: 1, endPage: 2 },
				{ id: 3, setlistId: 79, songId: 5, bookmarkId: 103, position: 2, songTitle: 'Symphony No. 5', bookmarkName: 'Andante', pageNum: 4, endPage: 5 },
			],
		}

		await page.route('**/api/libraries/*/setlists', (route) => fulfillOcs(route, [setlist]))
		await page.route('**/api/libraries/*/setlists/**', async (route) => {
			if (route.request().url().endsWith('/files')) {
				return fulfillOcs(route, [])
			}
			return fulfillOcs(route, setlist)
		})
		await page.route('**/api/songs/*/annotations**', (route) => fulfillOcs(route, []))
		await page.route('**/Beethoven*', (route) => route.fulfill({ status: 200, contentType: 'application/pdf', body: sixPageBytes }))

		await page.goto('/#/library/1/setlists')
		await page.getByRole('button', { name: 'Play Setlist' }).click()

		const dialog = page.getByRole('dialog', { name: 'Movements Concert' })
		await expect(dialog).toBeVisible()

		// Switch to Single page layout
		const layoutSelect = dialog.locator('[data-test="performance-layout-select"]')
		await layoutSelect.locator('.vs__dropdown-toggle').click()
		await page.getByRole('option', { name: 'Single page' }).click()

		const footer = dialog.locator('footer')
		// Item 1: Prelude (pp. 1-1 -> 1 page)
		await expect(dialog).toContainText('Prelude')
		await expect(footer).toContainText('Page 1 of 1')

		// Advancing moves to Item 2: Fugue (also starts on page 1, pp. 1-2 -> 2 pages)
		await page.keyboard.press('ArrowRight')
		await expect(dialog).toContainText('Fugue')
		await expect(footer).toContainText('Page 1 of 2')

		await page.keyboard.press('ArrowRight')
		await expect(footer).toContainText('Page 2 of 2')

		// Advancing past Fugue's endPage (2) automatically jumps to Item 3: Andante (pp. 4-5 -> 2 pages)
		await page.keyboard.press('ArrowRight')
		await expect(dialog).toContainText('Andante')
		await expect(footer).toContainText('Page 1 of 2')

		// Stepping back from Andante lands on Fugue's last page (Page 2 of 2)
		await page.keyboard.press('ArrowLeft')
		await expect(dialog).toContainText('Fugue')
		await expect(footer).toContainText('Page 2 of 2')
	})
})
