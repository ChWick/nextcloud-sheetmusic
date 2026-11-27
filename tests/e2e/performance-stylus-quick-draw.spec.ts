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

test.describe('Performance Mode Always-On Stylus Drawing', () => {
	test('draws immediately with pen in Performance Mode, keeps clicks turning pages, and disables stylus drawing in Concert Mode', async ({ page }) => {
		await setupMockApi(page)
		const pdfBytes = await createThreePagePdfBytes()

		await page.route('**/Beethoven*', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/pdf',
				body: pdfBytes,
			})
		})

		const savedAnnotations: Array<Record<string, unknown>> = []
		await page.route('**/api/songs/5/annotations**', async (route) => {
			if (route.request().method() === 'POST') {
				const body = route.request().postDataJSON() as Record<string, unknown>
				const saved = { ...body, id: 1000 + savedAnnotations.length }
				savedAnnotations.push(saved)
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ ocs: { data: saved } }),
				})
				return
			}
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ocs: { data: savedAnnotations } }),
			})
		})

		await page.goto('/#/library/1/songs?song=5')
		await expect(page.getByRole('heading', { name: 'Symphony No. 5' })).toBeVisible()
		await page.locator('[data-test="play-song-button"]').click()

		const dialog = page.getByRole('dialog', { name: 'Symphony No. 5' })
		const pdfContainer = dialog.locator('[data-test="pdf-viewer-container"]')
		await expect(pdfContainer).toBeVisible()

		// Switch to Single page layout
		const layoutSelect = dialog.locator('[data-test="performance-layout-select"]')
		await layoutSelect.locator('.vs__dropdown-toggle').click()
		await page.getByRole('option', { name: 'Single page' }).click()
		await expect(dialog.locator('footer')).toContainText('Page 1 of 3')

		const overlays = dialog.locator('[data-test="annotation-overlay"]')
		await expect(overlays).toHaveCount(1)
		const overlay = overlays.first()
		await expect(overlay).toBeVisible()
		await expect(overlay).toHaveClass(/is-stylus-quick-draw/)

		// Draw a stylus (pen) stroke directly on page 1 without opening any editor
		await overlay.evaluate((el) => {
			const rect = el.getBoundingClientRect()
			const x1 = rect.left + rect.width * 0.25
			const y1 = rect.top + rect.height * 0.3
			const x2 = rect.left + rect.width * 0.55
			const y2 = rect.top + rect.height * 0.45
			el.dispatchEvent(new PointerEvent('pointerdown', {
				bubbles: true, cancelable: true, pointerId: 11, pointerType: 'pen', button: 0, buttons: 1, pressure: 0.6, clientX: x1, clientY: y1,
			}))
			el.dispatchEvent(new PointerEvent('pointermove', {
				bubbles: true, cancelable: true, pointerId: 11, pointerType: 'pen', button: 0, buttons: 1, pressure: 0.6, clientX: x2, clientY: y2,
			}))
			el.dispatchEvent(new PointerEvent('pointerup', {
				bubbles: true, cancelable: true, pointerId: 11, pointerType: 'pen', button: 0, buttons: 0, pressure: 0, clientX: x2, clientY: y2,
			}))
		})

		await expect.poll(() => savedAnnotations.length).toBe(1)
		await expect(overlay.locator(`[data-ann-uuid="${String(savedAnnotations[0].uuid)}"]`).first()).toBeVisible()
		// Stylus stroke must not advance the page
		await expect(dialog.locator('footer')).toContainText('Page 1 of 3')

		// Right-edge mouse/finger tap still turns the page normally
		const box = await pdfContainer.boundingBox()
		expect(box).not.toBeNull()
		await page.mouse.click(box!.x + box!.width * 0.9, box!.y + box!.height / 2)
		await expect(dialog.locator('footer')).toContainText('Page 2 of 3')

		// Enter Concert Mode -> Always-On Stylus Drawing must be disabled
		await dialog.locator('[data-test="performance-concert-mode-button"]').click()
		await expect(dialog).toHaveAttribute('data-concert-mode', 'true')

		const countBeforeConcertPen = savedAnnotations.length
		await pdfContainer.evaluate((el) => {
			const rect = el.getBoundingClientRect()
			const x1 = rect.left + rect.width * 0.3
			const y1 = rect.top + rect.height * 0.35
			const x2 = rect.left + rect.width * 0.6
			const y2 = rect.top + rect.height * 0.5
			el.dispatchEvent(new PointerEvent('pointerdown', {
				bubbles: true, cancelable: true, pointerId: 12, pointerType: 'pen', button: 0, buttons: 1, pressure: 0.6, clientX: x1, clientY: y1,
			}))
			el.dispatchEvent(new PointerEvent('pointermove', {
				bubbles: true, cancelable: true, pointerId: 12, pointerType: 'pen', button: 0, buttons: 1, pressure: 0.6, clientX: x2, clientY: y2,
			}))
			el.dispatchEvent(new PointerEvent('pointerup', {
				bubbles: true, cancelable: true, pointerId: 12, pointerType: 'pen', button: 0, buttons: 0, pressure: 0, clientX: x2, clientY: y2,
			}))
		})

		await page.waitForTimeout(150)
		expect(savedAnnotations.length).toBe(countBeforeConcertPen)

		// Unlock Concert Mode, exit Performance Mode, and confirm the stylus stroke is visible in SongViewer
		const unlockBtn = dialog.locator('[data-test="concert-mode-unlock-button"]')
		const lockBox = await unlockBtn.boundingBox()
		expect(lockBox).not.toBeNull()
		await page.mouse.move(lockBox!.x + lockBox!.width / 2, lockBox!.y + lockBox!.height / 2)
		await page.mouse.down()
		await page.waitForTimeout(750)
		await page.mouse.up()
		await expect(dialog).toHaveAttribute('data-concert-mode', 'false')

		await dialog.locator('[data-test="stop-performance-button"]').click()
		await expect(dialog).toBeHidden()
		await expect(page.locator(`[data-ann-uuid="${String(savedAnnotations[0].uuid)}"]`).first()).toBeVisible()
	})
})
