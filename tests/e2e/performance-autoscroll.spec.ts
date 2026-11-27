import { expect, test } from '@playwright/test'
import { PDFDocument } from 'pdf-lib'
import { setupMockApi } from './helpers/mock-api'

async function createThreePagePdfBytes(): Promise<Buffer> {
	const doc = await PDFDocument.create()
	doc.addPage([600, 900])
	doc.addPage([600, 900])
	doc.addPage([600, 900])
	return Buffer.from(await doc.save())
}

test.describe('Performance Mode Auto-Scroll', () => {
	test('switches to continuous scroll, scrolls real 3-page PDF, pauses/resumes via Spacebar & tap, calibrates by duration, and persists speed', async ({ page }) => {
		await setupMockApi(page)
		const pdfBytes = await createThreePagePdfBytes()

		await page.route('**/Beethoven*', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/pdf',
				body: pdfBytes,
			})
		})

		// Song 5 (Symphony No. 5) has duration=420 (07:00)
		await page.goto('/#/library/1/songs?song=5')
		await expect(page.getByRole('heading', { name: 'Symphony No. 5' })).toBeVisible()
		await page.locator('[data-test="play-song-button"]').click()

		const dialog = page.getByRole('dialog', { name: 'Symphony No. 5' })
		const pdfContainer = dialog.locator('[data-test="pdf-viewer-container"]')
		await expect(pdfContainer).toBeVisible()

		// Start from explicit Single page layout to verify Auto-Scroll automatically switches to Scroll (continuous)
		const layoutSelect = dialog.locator('[data-test="performance-layout-select"]')
		await layoutSelect.locator('.vs__dropdown-toggle').click()
		await page.getByRole('option', { name: 'Single page' }).click()
		await expect(layoutSelect).toContainText('Single page')

		// Open Auto-Scroll popover via side arrow, bump logarithmic speed slider to ~120 px/s
		await dialog.locator('[data-test="performance-autoscroll-menu"]').click()
		const popover = dialog.locator('[data-test="performance-autoscroll-popover"]')
		await expect(popover).toBeVisible()
		await popover.locator('[data-test="autoscroll-speed-slider"]').fill(String(Math.log2(120).toFixed(2)))
		await expect(popover.locator('[data-test="autoscroll-speed-value"]')).toHaveText('120 px/s')

		// Pressing Escape closes the popover without exiting Performance Mode
		await page.keyboard.press('Escape')
		await expect(popover).toBeHidden()
		await expect(dialog.locator('[data-test="stop-performance-button"]')).toBeVisible()

		// Start Auto-Scroll -> automatically switches to continuous scroll and advances real scrollTop
		const toggleBtn = dialog.locator('[data-test="performance-autoscroll-toggle"]')
		await toggleBtn.click()
		await expect(layoutSelect).toContainText('Scroll')
		await expect(toggleBtn).toHaveAttribute('aria-pressed', 'true')

		// Wait until the real 3-page PDF renders and scrollTop measurably advances
		await expect.poll(async () => {
			return pdfContainer.evaluate((el) => el.scrollTop)
		}, { timeout: 5000 }).toBeGreaterThan(25)

		// Press Spacebar -> pauses Auto-Scroll and freezes scrollTop
		await page.keyboard.press('Space')
		await expect(toggleBtn).toHaveAttribute('aria-pressed', 'false')
		const pausedScrollTop = await pdfContainer.evaluate((el) => el.scrollTop)
		await page.waitForTimeout(250)
		const afterPauseScrollTop = await pdfContainer.evaluate((el) => el.scrollTop)
		expect(Math.abs(afterPauseScrollTop - pausedScrollTop)).toBeLessThanOrEqual(2)

		// Press Spacebar again -> resumes Auto-Scroll and scrollTop advances further
		await page.keyboard.press('Space')
		await expect(toggleBtn).toHaveAttribute('aria-pressed', 'true')
		await expect.poll(async () => {
			return pdfContainer.evaluate((el) => el.scrollTop)
		}, { timeout: 5000 }).toBeGreaterThan(pausedScrollTop + 20)

		// Clicking the stage while actively auto-scrolling pauses it
		const box = await pdfContainer.boundingBox()
		expect(box).not.toBeNull()
		await page.mouse.click(box!.x + box!.width / 2, box!.y + box!.height / 2)
		await expect(toggleBtn).toHaveAttribute('aria-pressed', 'false')

		// Open popover -> verify Song duration calibration (07:00) and Back to top
		await dialog.locator('[data-test="performance-autoscroll-menu"]').click()
		await expect(popover).toBeVisible()
		await popover.locator('[data-test="autoscroll-mode-duration"]').click()
		await expect(popover.locator('[data-test="autoscroll-duration-hint"]')).toContainText('7:00')

		await popover.locator('[data-test="autoscroll-reset-top"]').click()
		const resetTop = await pdfContainer.evaluate((el) => el.scrollTop)
		expect(resetTop).toBe(0)

		// Close popover, exit and reopen Performance Mode -> persisted speed (120 px/s) is restored
		await page.keyboard.press('Escape')
		await expect(popover).toBeHidden()
		await dialog.locator('[data-test="stop-performance-button"]').click()
		await page.locator('[data-test="play-song-button"]').click()

		const reopenedDialog = page.getByRole('dialog', { name: 'Symphony No. 5' })
		await reopenedDialog.locator('[data-test="performance-autoscroll-menu"]').click()
		await expect(reopenedDialog.locator('[data-test="autoscroll-speed-value"]')).toHaveText('120 px/s')
	})
})
