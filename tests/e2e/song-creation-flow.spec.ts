import { expect, test } from '@playwright/test'
import { setupMockApi } from './helpers/mock-api'

test.describe('Song Creation & End-to-End Editing Flow', () => {
	test.beforeEach(async ({ page }) => {
		await setupMockApi(page)
		await page.goto('/#/library/1/songs')
	})

	test('opens new song form, fills details, and creates a song', async ({ page }) => {
		// Open Add or import song dropdown in ViewHeader
		const actionMenu = page.locator('[aria-label="Add or import song"], [title="Add or import song"]').first()
		await expect(actionMenu).toBeVisible()
		await actionMenu.click()

		// Click "New song" action item
		await page.getByText('New song').click()

		// Verify editor opens in creation mode
		const titleInput = page.locator('#song-title-input')
		await expect(titleInput).toBeVisible()
		await expect(page).toHaveURL(/#\/library\/1\/new-song/)
		await expect(page.getByRole('heading', { name: 'Create Song' })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Songs' })).toHaveCount(0)
		await expect(page.getByRole('button', { name: 'Cancel' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Create song' })).toBeVisible()

		// Fill in title
		await titleInput.fill('Piano Sonata No. 14')

		// Save new song
		const createBtn = page.getByRole('button', { name: 'Create song' })
		await createBtn.click()

		// Should exit editor back to songs view
		await expect(page).toHaveURL(/#\/library\/1\/songs/)
		await expect(page.getByRole('heading', { name: 'Songs' })).toBeVisible()
	})

	test('creates a song with opus, catalog number, and uploaded PDF and receives naming-rule path', async ({ page }) => {
		let createPayload: any = null
		let uploadSongId: string | null = null

		await page.route(/\/api\/songs$/, async (route) => {
			if (route.request().method() === 'POST') {
				createPayload = route.request().postDataJSON()
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						ocs: {
							meta: { status: 'ok', statuscode: 200 },
							data: {
								createdSongId: 99,
								songs: [
									{
										id: 99,
										title: createPayload.title,
										opus: createPayload.opus,
										catalogNumber: createPayload.catalogNumber,
										files: [],
										composers: [],
										books: [],
										collections: [],
										artists: [],
										genres: [],
										keys: [],
										customGroups: [],
										years: [],
										signatures: [],
										sourceTypes: [],
										instruments: [],
										arrangers: [],
										bookmarks: [],
									},
								],
							},
						},
					}),
				})
				return
			}
			await route.fallback()
		})

		await page.route('**/api/libraries/*/songs/*/file/upload**', async (route) => {
			if (route.request().method() === 'POST') {
				const match = route.request().url().match(/\/api\/libraries\/\d+\/songs\/(\d+)\/file\/upload/)
				uploadSongId = match ? match[1] : null
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						ocs: {
							meta: { status: 'ok', statuscode: 200 },
							data: {
								songs: [
									{
										id: 99,
										title: 'Moonlight Sonata',
										opus: 'Op. 27 No. 2',
										catalogNumber: 'LIB-14',
										files: [
											{
												id: 9901,
												path: '/dav/files/admin/Noten/Songs/Beethoven/Beethoven - Moonlight Sonata (Op. 27 No. 2).pdf',
												pageCount: 1,
												fileId: 9901,
											},
										],
										composers: [],
										books: [],
										collections: [],
										artists: [],
										genres: [],
										keys: [],
										customGroups: [],
										years: [],
										signatures: [],
										sourceTypes: [],
										instruments: [],
										arrangers: [],
										bookmarks: [],
									},
								],
							},
						},
					}),
				})
				return
			}
			await route.fallback()
		})

		await page.goto('/#/library/1/new-song')
		await expect(page.locator('#song-title-input')).toBeVisible()
		await page.locator('#song-title-input').fill('Moonlight Sonata')
		await page.locator('#song-opus-input').fill('Op. 27 No. 2')
		await page.locator('#song-catalog-number-input').fill('LIB-14')

		// Stage a PDF file upload in SongFileSection
		const fileInput = page.locator('input[type="file"]').last()
		await fileInput.setInputFiles({
			name: 'raw_scan.pdf',
			mimeType: 'application/pdf',
			buffer: Buffer.from('%PDF-1.4\n%%EOF'),
		})
		await expect(page.getByText('raw_scan.pdf')).toBeVisible()

		await page.getByRole('button', { name: 'Create song' }).click()

		await expect.poll(() => createPayload).toBeTruthy()
		expect(createPayload).toMatchObject({
			libraryId: 1,
			title: 'Moonlight Sonata',
			opus: 'Op. 27 No. 2',
			catalogNumber: 'LIB-14',
		})
		await expect.poll(() => uploadSongId).toBe('99')
		await expect(page).toHaveURL(/#\/library\/1\/songs/)
		await expect(page.getByText('Beethoven - Moonlight Sonata (Op. 27 No. 2).pdf')).toBeVisible()
	})

	test('stages file deletion and restores it via pending notice', async ({ page }) => {
		// Open existing song in editor
		await page.goto('/#/library/1/songs?song=5')
		await page.getByRole('button', { name: 'Edit song' }).click()

		// Locate attached file list item delete button
		const deleteFileBtn = page.getByRole('button', { name: /Delete file .*/i })
		await deleteFileBtn.dispatchEvent('click')

		// Pending notice should appear with text "marked for removal"
		await expect(page.getByText(/marked for removal/i)).toBeVisible()

		// Click Restore button on pending notice
		const restoreBtn = page.getByRole('button', { name: 'Restore' })
		await expect(restoreBtn).toBeVisible()
		await restoreBtn.dispatchEvent('click')

		// File item delete button should be visible again
		await expect(deleteFileBtn).toBeVisible()
	})
})
