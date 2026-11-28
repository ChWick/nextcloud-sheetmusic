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

test.describe('Performance Mode customizable tap zones and pedal/controller mapping', () => {
	test('configures Top/Bottom tap zones and 4-pedal Up/Down actions, persists, and resets defaults', async ({ page }) => {
		await setupMockApi(page)
		const pdfBytes = await createThreePagePdfBytes()

		await page.route('**/Beethoven*', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/pdf',
				body: pdfBytes,
			})
		})
		await page.route('**/api/songs/5/annotations**', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ocs: { data: [] } }),
			})
		})

		await page.goto('/#/library/1/songs?song=5')
		await expect(page.getByRole('heading', { name: 'Symphony No. 5' })).toBeVisible()
		await page.locator('[data-test="play-song-button"]').click()

		const dialog = page.getByRole('dialog', { name: 'Symphony No. 5' })
		await expect(dialog).toBeVisible()

		// Switch to Single page layout
		const layoutSelect = dialog.locator('[data-test="performance-layout-select"]')
		await layoutSelect.locator('.vs__dropdown-toggle').click()
		await page.getByRole('option', { name: 'Single page' }).click()
		await expect(dialog.locator('footer')).toContainText('Page 1 of 3')

		// Open Viewer Settings -> select Top / Bottom tap zones and map Pedal 3 (ArrowUp) & Pedal 4 (ArrowDown)
		await dialog.getByRole('button', { name: /Viewer settings/i }).click()
		await expect(dialog.locator('[data-test="stage-input-mapping-controls"]')).toBeVisible()

		await dialog.locator('[data-test="tap-layout-top-bottom"]').click()
		await dialog.locator('[data-test="pedal-slot-pedalAux1"] .vs__dropdown-toggle').click()
		await page.locator('[role="option"] [title="Toggle metronome"]').click()
		await dialog.locator('[data-test="pedal-slot-pedalAux2"] .vs__dropdown-toggle').click()
		await page.locator('[role="option"] [title="Toggle night mode"]').click()

		// Close settings panel
		await dialog.getByRole('button', { name: 'Close' }).click()

		// Tap bottom zone -> advances to Page 2 of 3; tap top zone -> returns to Page 1 of 3
		const pdfContainer = dialog.locator('[data-test="pdf-viewer-container"]')
		const box = await pdfContainer.boundingBox()
		expect(box).not.toBeNull()

		await page.mouse.click(box!.x + box!.width * 0.5, box!.y + box!.height * 0.88)
		await expect(dialog.locator('footer')).toContainText('Page 2 of 3')

		await page.mouse.click(box!.x + box!.width * 0.5, box!.y + box!.height * 0.12)
		await expect(dialog.locator('footer')).toContainText('Page 1 of 3')

		// Press ArrowUp (Pedal 3) -> toggles metronome on and off
		await page.keyboard.press('ArrowUp')
		await expect(dialog.locator('[data-test="performance-metronome-pulse"]')).toBeVisible()
		await page.keyboard.press('ArrowUp')
		await expect(dialog.locator('[data-test="performance-metronome-pulse"]')).toBeHidden()

		// Press ArrowDown (Pedal 4) -> toggles night mode on and off
		await page.keyboard.press('ArrowDown')
		await expect(dialog).toHaveAttribute('data-night-chrome', 'true')
		await page.keyboard.press('ArrowDown')
		await expect(dialog).toHaveAttribute('data-night-chrome', 'false')

		// Re-open settings and reset defaults
		await dialog.getByRole('button', { name: /Viewer settings/i }).click()
		await dialog.locator('[data-test="stage-input-reset-button"]').click()
		await expect(dialog.locator('[data-test="tap-layout-left-right"]')).toHaveAttribute('aria-pressed', 'true')
		await expect(dialog.locator('[data-test="pedal-slot-pedalAux1"]')).toContainText('Disabled')
		await dialog.getByRole('button', { name: 'Close' }).click()

		// Switch to Scroll (continuous) mode -> Next/Prev page button & ArrowRight/ArrowLeft still work
		await layoutSelect.locator('.vs__dropdown-toggle').click()
		await page.getByRole('option', { name: 'Scroll' }).click()
		const nextBtn = dialog.getByRole('button', { name: 'Next page' })
		const prevBtn = dialog.getByRole('button', { name: 'Previous page' })
		await expect(nextBtn).toBeEnabled()
		await expect(prevBtn).toBeDisabled()
		await page.keyboard.press('ArrowRight')
		await expect(prevBtn).toBeEnabled()
		await page.keyboard.press('ArrowLeft')
		await expect(prevBtn).toBeDisabled()
	})
})
