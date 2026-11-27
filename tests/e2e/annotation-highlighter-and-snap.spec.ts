import { expect, test } from '@playwright/test'
import { PDFDocument, rgb } from 'pdf-lib'
import { setupMockApi } from './helpers/mock-api'

async function createSheetPdfBytes(): Promise<Buffer> {
	const doc = await PDFDocument.create()
	const page = doc.addPage([600, 800])
	// Draw dark staff lines so highlighter blending over black notation is exercised on a real canvas
	for (let i = 0; i < 5; i++) {
		page.drawLine({
			start: { x: 60, y: 600 - i * 10 },
			end: { x: 540, y: 600 - i * 10 },
			thickness: 1.5,
			color: rgb(0, 0, 0),
		})
	}
	return Buffer.from(await doc.save())
}

test.describe('Behind-the-notes highlighter blending and straight-line snapping', () => {
	test('renders highlighters with multiply/screen blending without stacking isolation and snaps lines via Snap toggle or Shift', async ({ page }) => {
		await setupMockApi(page)
		const pdfBytes = await createSheetPdfBytes()

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
				const saved = { ...body, id: 2000 + savedAnnotations.length }
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

		await page.locator('[data-test="edit-annotations-button"]').click()
		await page.locator('[data-test="tool-highlight"]').click()

		const snapToggle = page.locator('[data-test="annotation-snap-toggle"]')
		await expect(snapToggle).toBeVisible()
		await expect(snapToggle).toHaveAttribute('aria-pressed', 'false')
		await snapToggle.click()
		await expect(snapToggle).toHaveAttribute('aria-pressed', 'true')

		const overlay = page.locator('[data-test="annotation-overlay"]').first()
		await expect(overlay).toHaveClass(/is-editable/)
		await page.waitForTimeout(300)

		const box = await overlay.boundingBox()
		expect(box).not.toBeNull()

		// 1. Draw a slightly off-horizontal highlighter stroke with Snap enabled -> snaps to exact horizontal
		const startX = box!.x + box!.width * 0.2
		const startY = box!.y + box!.height * 0.25
		const endX = box!.x + box!.width * 0.75
		const endY = box!.y + box!.height * 0.28

		await page.mouse.move(startX, startY)
		await page.mouse.down()
		await page.mouse.move((startX + endX) / 2, startY + 14, { steps: 4 })
		await page.mouse.move(endX, endY, { steps: 4 })
		await page.mouse.up()

		await expect.poll(() => savedAnnotations.length).toBe(1)
		const hl = savedAnnotations[0] as {
			uuid: string
			type: string
			style: { blendMode?: string }
			data: { points: Array<{ x: number; y: number }> }
		}
		expect(hl.type).toBe('highlight')
		expect(hl.style.blendMode).toBe('multiply')
		expect(hl.data.points).toHaveLength(2)
		expect(hl.data.points[0].y).toBe(hl.data.points[1].y)

		// Verify real computed blending & non-isolating overlay stacking context in light mode
		// (A perfectly horizontal SVG <path> has geometric height 0 in getBoundingClientRect, so assert attached + non-empty path d)
		const hlPath = overlay.locator(`[data-ann-uuid="${hl.uuid}"][data-test="annotation-drawing"]`)
		await expect(hlPath).toBeAttached()
		await expect(hlPath).toHaveAttribute('d', /^M \d+\.\d+ \d+\.\d+ L \d+\.\d+ \d+\.\d+$/)
		const lightStyles = await hlPath.evaluate((el) => {
			const layer = el.closest('.sheetmusic-annotation-layer') as HTMLElement | null
			const placeholder = el.closest('[data-page-num]') as HTMLElement | null
			return {
				blendMode: getComputedStyle(el).mixBlendMode,
				layerZIndex: layer ? getComputedStyle(layer).zIndex : null,
				placeholderIsolation: placeholder ? getComputedStyle(placeholder).isolation : null,
			}
		})
		expect(lightStyles.blendMode).toBe('multiply')
		expect(lightStyles.layerZIndex).toBe('auto')
		expect(lightStyles.placeholderIsolation).toBe('isolate')

		// 2. Toggle Night Mode -> persisted multiply highlight must automatically switch to screen blend mode
		await page.locator('[data-test="viewer-night-mode-button"]').click()
		await expect
			.poll(async () => hlPath.evaluate((el) => getComputedStyle(el).mixBlendMode))
			.toBe('screen')

		// Switch back to light mode and verify multiply returns
		await page.locator('[data-test="viewer-night-mode-button"]').click()
		await expect
			.poll(async () => hlPath.evaluate((el) => getComputedStyle(el).mixBlendMode))
			.toBe('multiply')

		// 3. Disable Snap toggle, switch to Pen, and hold Shift to draw a snapped 45-degree straight line
		await snapToggle.click()
		await expect(snapToggle).toHaveAttribute('aria-pressed', 'false')
		await page.locator('[data-test="tool-drawing"]').click()

		const penStartX = box!.x + box!.width * 0.2
		const penStartY = box!.y + box!.height * 0.45
		const penEndX = box!.x + box!.width * 0.45
		const penEndY = box!.y + box!.height * 0.62

		await page.keyboard.down('Shift')
		await page.mouse.move(penStartX, penStartY)
		await page.mouse.down()
		await page.mouse.move(penEndX, penEndY, { steps: 4 })
		await page.mouse.up()
		await page.keyboard.up('Shift')

		await expect.poll(() => savedAnnotations.length).toBe(2)
		const pen = savedAnnotations[1] as {
			type: string
			data: { points: Array<{ x: number; y: number }> }
		}
		expect(pen.type).toBe('drawing')
		expect(pen.data.points).toHaveLength(2)
		const dxPx = (pen.data.points[1].x - pen.data.points[0].x) * box!.width
		const dyPx = (pen.data.points[1].y - pen.data.points[0].y) * box!.height
		expect(Math.abs(dxPx - dyPx)).toBeLessThan(3)
	})
})
