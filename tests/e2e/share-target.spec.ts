import { expect, test, type Page } from '@playwright/test'
import { mockSongs, setupMockApi } from './helpers/mock-api'

async function seedPendingShareInIdb(page: Page, fileName: string, mimeType = 'application/pdf', title = '') {
	await page.evaluate(async ({ fileName, mimeType, title }) => {
		const db = await new Promise<IDBDatabase>((resolve, reject) => {
			const req = indexedDB.open('sheetmusic-pwa-share', 1)
			req.onupgradeneeded = () => {
				if (!req.result.objectStoreNames.contains('pendingShares')) {
					req.result.createObjectStore('pendingShares')
				}
			}
			req.onsuccess = () => resolve(req.result)
			req.onerror = () => reject(req.error)
		})
		const blob = new Blob(['%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF'], { type: mimeType })
		await new Promise<void>((resolve, reject) => {
			const tx = db.transaction('pendingShares', 'readwrite')
			tx.objectStore('pendingShares').put({
				files: [{ name: fileName, type: mimeType, lastModified: Date.now(), blob }],
				title,
				text: '',
				url: '',
				createdAt: Date.now(),
			}, 'latest')
			tx.oncomplete = () => resolve()
			tx.onerror = () => reject(tx.error)
		})
		db.close()
	}, { fileName, mimeType, title })
}

async function setupShareTargetRoutes(page: Page) {
	await setupMockApi(page)

	const songs = mockSongs.map(s => ({ ...s, files: [...(s.files ?? [])] }))
	const setlists: Array<{ id: number; name: string; itemCount: number; items: any[] }> = [
		{ id: 7, name: 'Sunday Service', itemCount: 0, items: [] },
		{ id: 8, name: 'Christmas Concert', itemCount: 0, items: [] },
	]
	const setlistFilesById = new Map<number, Array<{ id: number; name: string; fileSize: number; mimeType: string }>>()

	const state = {
		createSongPayload: null as any,
		uploadedSongId: null as number | null,
		createdSetlistName: null as string | null,
		uploadedSetlistId: null as number | null,
	}

	await page.route(/\/api\/songs(\?.*)?$/, async (route) => {
		const method = route.request().method()
		if (method === 'GET') {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ocs: { meta: { status: 'ok', statuscode: 200 }, data: { songs } } }),
			})
			return
		}
		if (method === 'POST') {
			state.createSongPayload = route.request().postDataJSON()
			const newSong = { ...mockSongs[0], id: 99, title: state.createSongPayload.title, files: [] }
			songs.push(newSong)
			await route.fulfill({
				status: 201,
				contentType: 'application/json',
				body: JSON.stringify({
					ocs: { meta: { status: 'ok', statuscode: 201 }, data: { createdSongId: 99, songs } },
				}),
			})
			return
		}
		await route.fallback()
	})

	await page.route(/\/api\/libraries\/\d+\/songs\/(\d+)\/file\/upload/, async (route) => {
		const match = route.request().url().match(/\/songs\/(\d+)\/file\/upload/)
		state.uploadedSongId = match ? Number(match[1]) : null
		const target = songs.find(s => s.id === state.uploadedSongId)
		if (target) {
			target.files.push({ id: 501, path: '/remote.php/dav/files/admin/Noten/shared.pdf', pageOrder: '1-1', pageCount: 1 })
		}
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({ ocs: { meta: { status: 'ok', statuscode: 200 }, data: { songs } } }),
		})
	})

	await page.route(/\/api\/libraries\/\d+\/setlists$/, async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({ ocs: { meta: { status: 'ok', statuscode: 200 }, data: setlists } }),
		})
	})

	await page.route(/\/api\/libraries\/\d+\/setlists\/(\d+)$/, async (route) => {
		const match = route.request().url().match(/\/setlists\/(\d+)$/)
		const id = match ? Number(match[1]) : -1
		const found = setlists.find(s => s.id === id)
		await route.fulfill({
			status: found ? 200 : 404,
			contentType: 'application/json',
			body: JSON.stringify({ ocs: { meta: { status: found ? 'ok' : 'failure', statuscode: found ? 200 : 404 }, data: found ?? {} } }),
		})
	})

	await page.route(/\/api\/setlists$/, async (route) => {
		if (route.request().method() === 'POST') {
			const body = route.request().postDataJSON()
			state.createdSetlistName = body.name
			const created = { id: 55, name: body.name, itemCount: 0, items: [] }
			setlists.push(created)
			await route.fulfill({
				status: 201,
				contentType: 'application/json',
				body: JSON.stringify({ ocs: { meta: { status: 'ok', statuscode: 201 }, data: created } }),
			})
			return
		}
		await route.fallback()
	})

	await page.route(/\/api\/libraries\/\d+\/setlists\/(\d+)\/files$/, async (route) => {
		const match = route.request().url().match(/\/setlists\/(\d+)\/files$/)
		const id = match ? Number(match[1]) : -1
		if (route.request().method() === 'POST') {
			state.uploadedSetlistId = id
			const uploaded = { id: 701, name: 'shared-note.pdf', fileSize: 128, mimeType: 'application/pdf' }
			const list = setlistFilesById.get(id) ?? []
			list.push(uploaded)
			setlistFilesById.set(id, list)
			await route.fulfill({
				status: 201,
				contentType: 'application/json',
				body: JSON.stringify({ ocs: { meta: { status: 'ok', statuscode: 201 }, data: uploaded } }),
			})
			return
		}
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({ ocs: { meta: { status: 'ok', statuscode: 200 }, data: setlistFilesById.get(id) ?? [] } }),
		})
	})

	return state
}

test.describe('PWA Web Share Target Import', () => {
	test('imports a shared PDF as a new song into the active library and displays it', async ({ page }) => {
		const state = await setupShareTargetRoutes(page)

		await page.goto('/')
		await seedPendingShareInIdb(page, 'Clair de Lune.pdf')
		await page.goto('/?share-target=1')

		await expect(page.getByText('Import Shared File')).toBeVisible()
		await page.getByRole('button', { name: 'Next' }).click()
		await page.getByRole('button', { name: 'Next' }).click()

		const titleInput = page.getByLabel('Song title')
		await expect(titleInput).toHaveValue('Clair de Lune')
		await page.getByRole('button', { name: 'Import', exact: true }).click()

		await expect.poll(() => state.createSongPayload).toEqual({ libraryId: 1, title: 'Clair de Lune' })
		await expect.poll(() => state.uploadedSongId).toBe(99)

		await expect(page).toHaveURL(/#\/library\/1\/songs\?song=99/)
		await expect(page.getByTestId('song-not-found')).toHaveCount(0)
		await expect(page.getByRole('heading', { name: 'Clair de Lune' })).toBeVisible()
	})

	test('adds a shared PDF to an existing song and opens it', async ({ page }) => {
		const state = await setupShareTargetRoutes(page)

		await page.goto('/')
		await seedPendingShareInIdb(page, 'Part 2.pdf')
		await page.goto('/?share-target=1')

		await expect(page.getByText('Import Shared File')).toBeVisible()
		await page.getByRole('button', { name: 'Next' }).click()
		await page.getByText('Add to existing song').click()
		await page.getByRole('button', { name: 'Next' }).click()

		const option = page.getByRole('option', { name: /Symphony No\. 5/ })
		await expect(option).toBeVisible()
		await option.click()
		await page.getByRole('button', { name: 'Import', exact: true }).click()

		await expect.poll(() => state.uploadedSongId).toBe(5)
		await expect(page).toHaveURL(/#\/library\/1\/songs\?song=5/)
		await expect(page.getByRole('heading', { name: 'Symphony No. 5' })).toBeVisible()
	})

	test('attaches a shared file to an existing setlist and opens it', async ({ page }) => {
		const state = await setupShareTargetRoutes(page)

		await page.goto('/')
		await seedPendingShareInIdb(page, 'Program.pdf')
		await page.goto('/?share-target=1')

		await expect(page.getByText('Import Shared File')).toBeVisible()
		await page.getByRole('button', { name: 'Next' }).click()
		await page.getByText('Annotation of setlist').click()
		await page.getByRole('button', { name: 'Next' }).click()

		const option = page.getByRole('option', { name: 'Christmas Concert' })
		await expect(option).toBeVisible()
		await option.click()
		await page.getByRole('button', { name: 'Import', exact: true }).click()

		await expect.poll(() => state.uploadedSetlistId).toBe(8)
		await expect(page).toHaveURL(/#\/library\/1\/setlists\?setlist=8/)
		await expect(page.getByRole('heading', { name: 'Christmas Concert' })).toBeVisible()
	})

	test('creates a new setlist with a shared file and opens it', async ({ page }) => {
		const state = await setupShareTargetRoutes(page)

		await page.goto('/')
		await seedPendingShareInIdb(page, 'Easter Program.pdf')
		await page.goto('/?share-target=1')

		await expect(page.getByText('Import Shared File')).toBeVisible()
		await page.getByRole('button', { name: 'Next' }).click()
		await page.getByText('New setlist').click()
		await page.getByRole('button', { name: 'Next' }).click()

		const nameInput = page.getByLabel('Setlist name')
		await expect(nameInput).toHaveValue('Easter Program')
		await page.getByRole('button', { name: 'Import', exact: true }).click()

		await expect.poll(() => state.createdSetlistName).toBe('Easter Program')
		await expect.poll(() => state.uploadedSetlistId).toBe(55)
		await expect(page).toHaveURL(/#\/library\/1\/setlists\?setlist=55/)
		await expect(page.getByRole('heading', { name: 'Easter Program' })).toBeVisible()
	})
})
