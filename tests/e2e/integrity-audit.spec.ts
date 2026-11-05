import { expect, test } from '@playwright/test'
import { setupMockApi } from './helpers/mock-api'

test.describe('Integrity Audit Flow', () => {
	test.beforeEach(async ({ page }) => {
		await setupMockApi(page)
		await page.goto('/#/library/1/integrity')
	})

	test('renders integrity audit and auto-fixes a single invalid naming rule issue with toast status', async ({ page }) => {
		// Verify heading and group
		await expect(page.getByRole('heading', { name: 'Integrity Audit' })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Invalid Naming Rules' })).toBeVisible()
		await expect(page.getByText('Symphony No. 5')).toBeVisible()

		// Locate Auto-Fix Issue button
		const autoFixBtn = page.getByRole('button', { name: 'Auto-Fix Issue' })
		await expect(autoFixBtn).toBeVisible()
		await autoFixBtn.click()

		// Toast notification with song title should be visible
		await expect(page.getByText(/Naming rule applied for "Symphony No. 5"/i)).toBeVisible()

		// Issue should be removed and clean notice displayed
		await expect(page.getByText('All System Checks Passed!')).toBeVisible()
	})

	test('renders batch fix in group and shows status toast', async ({ page }) => {
		await expect(page.getByRole('heading', { name: 'Integrity Audit' })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Invalid Naming Rules' })).toBeVisible()

		const fixAllBtn = page.getByRole('button', { name: /Fix All in Group/i })
		await expect(fixAllBtn).toBeVisible()
		await fixAllBtn.click()

		// Toast notification should appear
		await expect(page.getByText(/Fixed 1 integrity issue/i).first()).toBeVisible()

		// Clean notice displayed
		await expect(page.getByText('All System Checks Passed!')).toBeVisible()
	})

	test('opens Duplicate Songs Resolution dialog, copies fields via arrow buttons (including arrangers and duration), and merges songs', async ({ page }) => {
		let mergePayload: any = null
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
									type: 'duplicate_file_path',
									severity: 'error',
									message: 'Multiple database entries point to the same file path',
									songId: 5,
									songTitle: 'Symphony No. 5',
									fixable: false,
									details: {
										songIds: [5, 6],
										exactDuplicate: false,
									},
								},
							],
						},
					},
				}),
			})
		})
		await page.route('**/api/songs/merge', async (route) => {
			mergePayload = route.request().postDataJSON()
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ocs: {
						meta: { status: 'ok', statuscode: 200 },
						data: { songs: [] },
					},
				}),
			})
		})

		await page.goto('/#/library/1/integrity')
		await expect(page.getByText('Multiple database entries point to the same file path')).toBeVisible()

		// Open Duplicate Songs Resolution dialog in Merge mode
		await page.getByRole('button', { name: /Merge Songs/i }).click()

		const dialog = page.locator('.modal-container')
		await expect(dialog).toBeVisible()
		await expect(dialog.getByText('Duplicate Songs Resolution')).toBeVisible()

		// Verify newly supported entities & scalars are visible in the dialog
		await expect(dialog.locator('[data-test="merged-field-arrangers"]')).toBeVisible()
		await expect(dialog.locator('[data-test="merged-field-instruments"]')).toBeVisible()
		await expect(dialog.locator('[data-test="merged-field-duration"]')).toBeVisible()
		await expect(dialog.locator('[data-test="merged-field-stars"]')).toBeVisible()
		await expect(dialog.locator('[data-test="merged-field-difficulty"]')).toBeVisible()

		// Click arrow to copy Title from Song A -> verify real input value changes in browser
		await dialog.locator('[data-test="copy-from-a-title"]').click()
		await expect(dialog.locator('input#merge-title-input')).toHaveValue('Symphony No. 5')

		// Click arrow to copy Arrangers from Song B -> verify real NcSelect displays Franz Liszt
		await dialog.locator('[data-test="copy-from-b-arrangers"]').click()
		await expect(dialog.locator('[data-test="merged-field-arrangers"]')).toContainText('Franz Liszt')

		// Click arrow to copy Duration from Song A -> verify real duration input value changes to 420
		await dialog.locator('[data-test="copy-from-a-duration"]').click()
		await expect(dialog.locator('input#merge-duration-input')).toHaveValue('420')

		// Click arrow to copy Rating (Stars) from Song B -> verify real rating input value changes to 4
		await dialog.locator('[data-test="copy-from-b-stars"]').click()
		await expect(dialog.locator('input#merge-stars-input')).toHaveValue('4')

		// Submit Merge Songs
		await dialog.locator('[data-test="submit-merge-btn"]').click()

		await expect.poll(() => mergePayload).toBeTruthy()
		expect(mergePayload).toMatchObject({
			libraryId: 1,
			keepId: 5,
			droppedId: 6,
			merged: {
				title: 'Symphony No. 5',
				arrangerIds: [9001],
				duration: 420,
				stars: 4,
			},
		})
	})

	test('switches to Keep as 2 Separate Songs mode, copies fields via arrow buttons, and saves both songs', async ({ page }) => {
		let resolvePayload: any = null
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
									type: 'duplicate_file_path',
									severity: 'error',
									message: 'Multiple database entries point to the same file path',
									songId: 5,
									songTitle: 'Symphony No. 5',
									fixable: false,
									details: {
										songIds: [5, 6],
										exactDuplicate: false,
									},
								},
							],
						},
					},
				}),
			})
		})
		await page.route('**/api/songs/resolve-both', async (route) => {
			resolvePayload = route.request().postDataJSON()
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ocs: {
						meta: { status: 'ok', statuscode: 200 },
						data: { songs: [] },
					},
				}),
			})
		})

		await page.goto('/#/library/1/integrity')
		await page.getByRole('button', { name: /Keep as Two/i }).click()

		const dialog = page.locator('.modal-container')
		await expect(dialog).toBeVisible()
		await expect(dialog.locator('input#resolve-a-title-input')).toHaveValue('Symphony No. 5')
		await expect(dialog.locator('input#resolve-b-title-input')).toHaveValue('Requiem in D minor')
		await expect(dialog.locator('input#resolve-a-duration-input')).toHaveValue('420')
		await expect(dialog.locator('input#resolve-b-duration-input')).toHaveValue('310')

		// Copy Song A duration to Song B via arrow button
		await dialog.locator('[data-test="copy-a-to-b-duration"]').click()
		await expect(dialog.locator('input#resolve-b-duration-input')).toHaveValue('420')

		// Copy Song B arrangers to Song A via arrow button
		await dialog.locator('[data-test="copy-b-to-a-arrangers"]').click()
		await expect(dialog.locator('[data-test="field-a-arrangers"]')).toContainText('Franz Liszt')

		await dialog.locator('[data-test="submit-resolve-both-btn"]').click()

		await expect.poll(() => resolvePayload).toBeTruthy()
		expect(resolvePayload).toMatchObject({
			libraryId: 1,
			songA: {
				id: 5,
				title: 'Symphony No. 5',
				arrangerIds: [9001],
				duration: 420,
			},
			songB: {
				id: 6,
				title: 'Requiem in D minor',
				arrangerIds: [9001],
				duration: 420,
			},
		})
	})
})
