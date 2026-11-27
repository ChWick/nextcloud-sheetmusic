import { expect, test } from '@playwright/test'
import { PDFDocument } from 'pdf-lib'
import { setupMockApi } from './helpers/mock-api'

async function createThreePagePdfBytes(): Promise<Buffer> {
	const doc = await PDFDocument.create()
	doc.addPage([600, 800])
	doc.addPage([600, 800])
	doc.addPage([600, 800])
	return Buffer.from(await doc.save())
}

test.describe('Performance Concert Mode (lock toolbars & zoom)', () => {
	test('locks HUD and Escape exit, keeps page turns active, ignores brief tap, and unlocks on hold', async ({ page }) => {
		await setupMockApi(page)
		const pdfBytes = await createThreePagePdfBytes()

		await page.route('**/Beethoven*', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/pdf',
				body: pdfBytes,
			})
		})

		await page.goto('/#/library/1/songs?song=5')
		await expect(page.getByRole('heading', { name: 'Symphony No. 5' })).toBeVisible()
		await page.locator('[data-test="play-song-button"]').click()

		const dialog = page.getByRole('dialog', { name: 'Symphony No. 5' })
		const pdfContainer = dialog.locator('[data-test="pdf-viewer-container"]')
		await expect(pdfContainer).toBeVisible()

		// Switch to Single page layout so page 1 -> page 2 turn is unmistakable
		const layoutSelect = dialog.locator('[data-test="performance-layout-select"]')
		await layoutSelect.locator('.vs__dropdown-toggle').click()
		await page.getByRole('option', { name: 'Single page' }).click()
		await expect(dialog.locator('footer')).toContainText('Page 1 of 3')

		// Enter Concert Mode via top-bar lock button
		await dialog.locator('[data-test="performance-concert-mode-button"]').click()
		await expect(dialog).toHaveAttribute('data-concert-mode', 'true')
		await expect(dialog.locator('header')).toBeHidden()
		await expect(dialog.locator('footer')).toBeHidden()

		const unlockBtn = dialog.locator('[data-test="concert-mode-unlock-button"]')
		await expect(unlockBtn).toBeVisible()

		// Center click on stage does NOT reveal HUD in Concert Mode
		const box = await pdfContainer.boundingBox()
		expect(box).not.toBeNull()
		await page.mouse.click(box!.x + box!.width / 2, box!.y + box!.height / 2)
		await expect(dialog.locator('header')).toBeHidden()
		await expect(dialog.locator('footer')).toBeHidden()

		// Pressing Escape does NOT exit Performance Mode while locked
		await page.keyboard.press('Escape')
		await expect(dialog).toBeVisible()
		await expect(dialog).toHaveAttribute('data-concert-mode', 'true')

		// Page turning via keyboard (ArrowRight) STILL works in Concert Mode
		await page.keyboard.press('ArrowRight')

		// Quick accidental tap on unlock badge does NOT unlock and shows hint
		await unlockBtn.click()
		await expect(dialog).toHaveAttribute('data-concert-mode', 'true')
		await expect(dialog.locator('[data-test="concert-mode-hint"]')).toBeVisible()

		// Deliberate press-and-hold (750ms) unlocks Concert Mode and restores HUD
		const lockBox = await unlockBtn.boundingBox()
		expect(lockBox).not.toBeNull()
		await page.mouse.move(lockBox!.x + lockBox!.width / 2, lockBox!.y + lockBox!.height / 2)
		await page.mouse.down()
		await page.waitForTimeout(750)
		await page.mouse.up()

		await expect(dialog).toHaveAttribute('data-concert-mode', 'false')
		await expect(dialog.locator('header')).toBeVisible()
		await expect(dialog.locator('footer')).toBeVisible()
		// Confirm the ArrowRight turn during Concert Mode advanced to Page 2 of 3
		await expect(dialog.locator('footer')).toContainText('Page 2 of 3')
	})
})
