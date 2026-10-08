import { expect, test } from '@playwright/test'
import { setupMockApi } from './helpers/mock-api'

test.describe('Song PDF Modifier & Cropping E2E Flow', () => {
	test.beforeEach(async ({ page }) => {
		await setupMockApi(page)
		const fs = await import('node:fs')
		const samplePath = new URL('../fixtures/sample.pdf', import.meta.url)
		const pdfBytes = fs.readFileSync(samplePath)
		await page.route('**/*.pdf*', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/pdf',
				body: pdfBytes,
			})
		})
		await page.goto('/#/library/1/songs?song=5')
	})

	test('navigates from song viewer to PDF modifier via action button', async ({ page }) => {
		// Locate the Modify PDF button in the viewer header
		const modifyBtn = page.getByRole('button', { name: /Modify PDF/i })
		await expect(modifyBtn).toBeVisible()
		await modifyBtn.click()

		// Verify PDF Modifier header and tabs are rendered
		await expect(page.getByRole('heading', { name: /Modify PDF/i })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Rotate Pages' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Crop Pages' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Split PDF' })).toBeVisible()
	})

	test('switches to Crop mode, interacts with crop modal, applies crop, and saves', async ({ page }) => {
		// Navigate directly to modifier
		await page.goto('/#/library/1/songs?song=5&modifyPdf=1')

		// Switch to Crop Pages tab
		const cropTab = page.getByRole('button', { name: 'Crop Pages' })
		await expect(cropTab).toBeVisible()
		await cropTab.click()

		// Verify toolbar controls
		await expect(page.getByText(/Click on any page or "Crop" button to adjust crop margins/i)).toBeVisible()
		const cropAllBtn = page.getByRole('button', { name: 'Crop All Pages…' })
		const resetAllBtn = page.getByRole('button', { name: 'Reset All Crops' })
		const saveCropsBtn = page.getByRole('button', { name: 'Save Crops' })

		await expect(cropAllBtn).toBeVisible()
		await expect(resetAllBtn).toBeVisible()
		await expect(saveCropsBtn).toBeVisible()

		// Before any cropping, Save Crops and Reset All should be disabled
		await expect(saveCropsBtn).toBeDisabled()
		await expect(resetAllBtn).toBeDisabled()

		// Click Crop button on page 1
		const page1CropBtn = page.getByTitle('Crop page 1').first()
		await expect(page1CropBtn).toBeVisible()
		await page1CropBtn.click()

		// Verify Crop Modal is opened
		const cropDialog = page.getByRole('dialog')
		await expect(cropDialog).toBeVisible()
		await expect(cropDialog.getByRole('button', { name: 'Apply Crop' })).toBeVisible()
		await expect(cropDialog.getByRole('button', { name: 'Reset to full page' })).toBeVisible()

		// Click Trim 5% preset button in crop modal toolbar
		const trim5Btn = cropDialog.getByRole('button', { name: 'Trim 5%' })
		await expect(trim5Btn).toBeVisible()
		await trim5Btn.click()

		// Click Apply Crop button
		await cropDialog.getByRole('button', { name: 'Apply Crop' }).click()

		// Verify modal closed
		await expect(cropDialog).toBeHidden()

		// Verify page 1 now shows the "Cropped" badge
		await expect(page.getByText('Cropped').first()).toBeVisible()

		// Now Save Crops and Reset All buttons should be enabled
		await expect(saveCropsBtn).toBeEnabled()
		await expect(resetAllBtn).toBeEnabled()

		// Click Save Crops button
		await saveCropsBtn.click()

		// Verify success notification or return to song viewer
		await expect(page.getByText(/Cropped PDF saved successfully/i).first()).toBeVisible()
	})

	test('supports resetting all crops across pages', async ({ page }) => {
		await page.goto('/#/library/1/songs?song=5&modifyPdf=1')

		// Switch to Crop Pages tab
		await page.getByRole('button', { name: 'Crop Pages' }).click()

		// Open crop modal on page 1 and apply Trim 5%
		const page1CropBtn = page.getByTitle('Crop page 1').first()
		await page1CropBtn.click()

		const cropDialog = page.getByRole('dialog')
		await expect(cropDialog).toBeVisible()

		await cropDialog.getByRole('button', { name: 'Trim 5%' }).click()
		await cropDialog.getByRole('button', { name: 'Apply Crop' }).click()
		await expect(cropDialog).toBeHidden()

		// Verify cropped badge exists
		await expect(page.getByText('Cropped').first()).toBeVisible()

		// Click Reset All Crops
		const resetAllBtn = page.getByRole('button', { name: 'Reset All Crops' })
		await expect(resetAllBtn).toBeEnabled()
		await resetAllBtn.click()

		// Cropped badge should be gone
		await expect(page.getByText('Cropped')).toHaveCount(0)

		// Save Crops button should be disabled again
		await expect(page.getByRole('button', { name: 'Save Crops' })).toBeDisabled()
	})

	test('supports crop all pages modal and applying to all pages', async ({ page }) => {
		await page.goto('/#/library/1/songs?song=5&modifyPdf=1')

		// Switch to Crop Pages tab
		await page.getByRole('button', { name: 'Crop Pages' }).click()

		// Click Crop All Pages toolbar button
		const cropAllBtn = page.getByRole('button', { name: 'Crop All Pages…' })
		await cropAllBtn.click()

		const cropDialog = page.getByRole('dialog')
		await expect(cropDialog).toBeVisible()

		// Click Trim 10%
		await cropDialog.getByRole('button', { name: 'Trim 10%' }).click()

		// Choose "All pages" radio option
		const allPagesRadio = cropDialog.locator('input[type="radio"][value="all"]')
		await allPagesRadio.check()

		// Apply crop
		await cropDialog.getByRole('button', { name: 'Apply Crop' }).click()
		await expect(cropDialog).toBeHidden()

		// All pages should now display the Cropped badge
		const croppedBadges = page.getByText('Cropped')
		await expect(croppedBadges.first()).toBeVisible()
		await expect(croppedBadges).toHaveCount(8)

		// Header save button is active
		await expect(page.getByRole('button', { name: 'Save Crops' })).toBeEnabled()
	})

	test('supports rotating and cropping simultaneously in one step and saves both', async ({ page }) => {
		await page.goto('/#/library/1/songs?song=5&modifyPdf=1')

		// 1. In Rotate mode: rotate page 1 clockwise 90°
		const rotateCwBtn = page.getByTitle('Rotate page 90° CW').first()
		await expect(rotateCwBtn).toBeVisible()
		await rotateCwBtn.click()

		// Verify 90° rotation badge is visible on page 1
		await expect(page.getByText('90°').first()).toBeVisible()

		// 2. Switch to Crop Pages tab
		const cropTab = page.getByRole('button', { name: 'Crop Pages' })
		await cropTab.click()

		// 3. Open Crop modal for page 1
		const page1CropBtn = page.getByTitle('Crop page 1').first()
		await page1CropBtn.click()

		const cropDialog = page.getByRole('dialog')
		await expect(cropDialog).toBeVisible()

		// Apply Trim 5% preset
		await cropDialog.getByRole('button', { name: 'Trim 5%' }).click()
		await cropDialog.getByRole('button', { name: 'Apply Crop' }).click()
		await expect(cropDialog).toBeHidden()

		// Verify both badges ("90°" and "Cropped") are visible on page 1
		await expect(page.getByText('90°').first()).toBeVisible()
		await expect(page.getByText('Cropped').first()).toBeVisible()

		// 4. Save modifications
		const saveBtn = page.getByRole('button', { name: /Save Crops|Save Changes/i })
		await expect(saveBtn).toBeEnabled()
		await saveBtn.click()

		// 5. Verify success toast
		await expect(page.getByText(/saved successfully/i).first()).toBeVisible()
	})

	test('supports deleting a single page, displaying deleted indicators, and saving updated PDF', async ({ page }) => {
		await page.goto('/#/library/1/songs?song=5&modifyPdf=1')

		// Header initially shows 8 pages
		await expect(page.getByText(/8 pages/i)).toBeVisible()

		// Delete page 2
		const delPage2Btn = page.getByTitle('Delete page 2').first()
		await expect(delPage2Btn).toBeVisible()
		await delPage2Btn.click()

		// Page 2 card should show Deleted indicator
		await expect(page.getByText('Deleted').first()).toBeVisible()

		// Header reflects 7 / 8 pages
		await expect(page.getByText(/7 \/ 8 pages/i)).toBeVisible()

		// Save button is now enabled
		const saveBtn = page.getByRole('button', { name: /Save Changes|Save Rotations/i })
		await expect(saveBtn).toBeEnabled()
		await saveBtn.click()

		// Verify success notification
		await expect(page.getByText(/saved successfully/i).first()).toBeVisible()
	})

	test('supports restoring deleted pages via card button and toolbar', async ({ page }) => {
		await page.goto('/#/library/1/songs?song=5&modifyPdf=1')

		// Delete page 2
		const delPage2Btn = page.getByTitle('Delete page 2').first()
		await delPage2Btn.click()

		await expect(page.getByText('Deleted').first()).toBeVisible()
		await expect(page.getByRole('button', { name: /Restore Deleted \(1\)/i })).toBeVisible()

		// Restore page 2 using the card restore button
		const restorePage2Btn = page.getByTitle('Restore page 2').first()
		await expect(restorePage2Btn).toBeVisible()
		await restorePage2Btn.click()

		// Deleted badge and toolbar restore button should disappear
		await expect(page.getByText('Deleted')).toBeHidden()
		await expect(page.getByRole('button', { name: /Restore Deleted/i })).toBeHidden()
		await expect(page.getByText(/8 pages/i)).toBeVisible()

		// Delete pages 2 and 3
		await page.getByTitle('Delete page 2').first().click()
		await page.getByTitle('Delete page 3').first().click()

		const toolbarRestoreBtn = page.getByRole('button', { name: /Restore Deleted \(2\)/i })
		await expect(toolbarRestoreBtn).toBeVisible()

		// Click Restore in toolbar
		await toolbarRestoreBtn.click()

		await expect(page.getByText('Deleted')).toBeHidden()
		await expect(page.getByRole('button', { name: /Restore Deleted/i })).toBeHidden()
		await expect(page.getByText(/8 pages/i)).toBeVisible()
	})
})

