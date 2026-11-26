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

test.describe('Interactive Link Points (D.S. / Coda jumps)', () => {
	test('creates a link with presets/bookmarks and jumps with arrival beacon + return chip in performance mode', async ({ page }) => {
		await setupMockApi(page)
		const pdfBytes = await createThreePagePdfBytes()

		// Serve a real 3-page PDF for Song 5
		await page.route('**/Beethoven*', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/pdf',
				body: pdfBytes,
			})
		})

		// Seed a link annotation on page 1 (0-indexed: 0) jumping to page 3 (0-indexed: 2)
		const seededLink = {
			id: 501,
			uuid: 'link-to-coda-1',
			songId: 5,
			fileId: 50,
			pageNumber: 0,
			layerIndex: 0,
			type: 'link',
			x: 0.25,
			y: 0.25,
			width: 0.22,
			height: 0.08,
			style: { color: '#d32f2f', opacity: 1, fontSize: 16 },
			data: { targetPage: 2, targetX: 0.5, targetY: 0.54, label: 'To Coda' },
		}

		let annotationsStore: Array<Record<string, unknown>> = [seededLink]
		let nextAnnId = 600

		await page.route('**/api/songs/*/annotations/batch**', async (route) => {
			const payload = route.request().postDataJSON() as { upserts?: Array<Record<string, unknown>> } | null
			const saved: Array<Record<string, unknown>> = []
			for (const item of payload?.upserts ?? []) {
				const existingIdx = annotationsStore.findIndex((a) => a.uuid === item.uuid)
				const record = { id: nextAnnId++, ...item }
				if (existingIdx >= 0) {
					annotationsStore[existingIdx] = record
				} else {
					annotationsStore.push(record)
				}
				saved.push(record)
			}
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ocs: {
						meta: { status: 'ok', statuscode: 200 },
						data: { saved, deletedIds: [] },
					},
				}),
			})
		})

		await page.route('**/api/songs/*/annotations**', async (route) => {
			if (route.request().url().includes('/batch')) {
				await route.fallback()
				return
			}
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					ocs: {
						meta: { status: 'ok', statuscode: 200 },
						data: annotationsStore,
					},
				}),
			})
		})

		await page.goto('/#/library/1/songs?song=5')
		await expect(page.getByRole('heading', { name: 'Symphony No. 5' })).toBeVisible()
		await expect(page.locator('[data-test="annotation-link"]').first()).toBeVisible()

		// Zoom to 50% in continuous scroll view so the bottom of page 1 and top of page 2 are both visible
		await page.getByRole('combobox', { name: 'Zoom level' }).selectOption('0.5')
		await page.waitForTimeout(300)

		// 1. Verify edit mode link popover exposes presets, bookmark selector, and target spot chips
		await page.getByRole('button', { name: 'Edit annotations' }).click()
		await page.getByRole('button', { name: /Page Jump Link/i }).click()

		const overlay = page.locator('[data-test="annotation-overlay"]').first()
		await expect(overlay).toHaveClass(/is-editable/)
		// Wait for debounced PdfResizeObserver re-render after the annotation toolbar expands
		await page.waitForTimeout(400)
		// Click near the very bottom of page 1 (where an unteleported popover would overlap page 2)
		const box = await overlay.boundingBox()
		expect(box).not.toBeNull()
		await page.mouse.click(box!.x + box!.width * 0.5, box!.y + box!.height - 15)

		const linkPopover = page.locator('[data-test="annotation-link-popover"]')
		await expect(linkPopover).toBeVisible()
		await expect(page.locator('[data-test="annotation-link-presets"]')).toBeVisible()
		await expect(page.locator('[data-test="annotation-link-bookmark-select"]')).toBeVisible()

		await page.locator('[data-test="annotation-link-preset-d-s-"]').click()
		await expect(page.locator('[data-test="annotation-link-label-input"]')).toHaveValue('D.S.')

		// Open side-by-side picker, choose page 3 target, Circle style, and Create reverse link
		await page.locator('[data-test="annotation-link-open-picker"]').click()
		const picker = page.locator('[data-test="link-picker-modal"]')
		await expect(picker).toBeVisible()
		await picker.locator('[data-test="link-picker-target-next"]').click()
		await expect(picker.locator('[data-test="link-picker-target-page"]')).toContainText('Page 3 / 3')
		await picker.locator('[data-test="link-picker-style-circle"]').click()
		await picker.locator('[data-test="link-picker-reverse-checkbox"]').check()
		await picker.locator('[data-test="link-picker-save"]').click()
		await expect(picker).toBeHidden()

		// Verify a circle link was created on page 1 (in addition to the seeded box link) and reverse link on page 3
		await expect(page.locator('[data-link-style="circle"]').first()).toBeVisible()

		// Select the existing link to verify editing its target page, style & picker via the toolbar popover
		await page.getByRole('button', { name: /Select/i }).first().click()
		await page.locator('[data-test="annotation-link"]').first().click()
		await page.locator('[data-test="selected-link-edit-button"]').click()
		await expect(page.locator('[data-test="selected-link-page-input"]')).toHaveValue('3')
		await expect(page.locator('[data-test="selected-link-style-circle"]')).toBeVisible()
		await expect(page.locator('[data-test="selected-link-open-picker"]')).toBeVisible()
		await page.getByRole('button', { name: 'Done' }).click()

		// 2. Open Performance Mode in single-page layout so page 1 -> page 3 jump is unmistakable
		await page.locator('[data-test="play-song-button"]').click()
		const dialog = page.getByRole('dialog', { name: 'Symphony No. 5' })
		await expect(dialog.locator('[data-test="performance-metronome-button"]')).toBeVisible()
		await dialog.locator('select').first().selectOption('single')

		// Verify seeded link renders in performance mode and click it to jump to page 3
		const linkRect = dialog.locator('[data-test="annotation-link"]').first()
		await expect(linkRect).toBeVisible()
		await linkRect.click()

		// Arrival beacon appears on target page 3 and Return chip appears
		await expect(dialog.locator('[data-test="annotation-link-beacon"]')).toBeVisible()
		const returnChip = dialog.locator('[data-test="link-jump-back-button"]')
		await expect(returnChip).toBeVisible()
		await expect(returnChip).toContainText('Return to p. 1')

		// Click Return chip to jump back to page 1
		await returnChip.click()
		await expect(returnChip).toBeHidden()
		await expect(dialog.locator('[data-test="annotation-link"]').first()).toBeVisible()
	})
})
