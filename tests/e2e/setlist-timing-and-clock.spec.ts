import { expect, test, type Route } from '@playwright/test'
import { setupMockApi } from './helpers/mock-api'

async function fulfillOcs(route: Route, data: unknown) {
	await route.fulfill({
		status: 200,
		contentType: 'application/json',
		body: JSON.stringify({
			ocs: { meta: { status: 'ok', statuscode: 200 }, data },
		}),
	})
}

test.describe('Setlist timing, break markers, and stage clock', () => {
	test('adds an intermission break with subtotal, updates total setlist duration, and displays break + stage clock in performance mode', async ({ page }) => {
		await setupMockApi(page)

		const setlist = {
			id: 10,
			libraryId: 1,
			name: 'Evening Recital',
			itemCount: 2,
			items: [
				{ id: 101, songId: 5, songTitle: 'Symphony No. 5', duration: 360, position: 0 },
				{ id: 102, songId: 6, songTitle: 'Moonlight Sonata', duration: 480, position: 1 },
			],
		}

		await page.route('**/api/libraries/*/setlists', (route) => fulfillOcs(route, [setlist]))
		await page.route('**/api/libraries/*/setlists/**', async (route) => {
			if (route.request().url().endsWith('/files')) {
				return fulfillOcs(route, [])
			}
			return fulfillOcs(route, setlist)
		})
		await page.route('**/api/songs/*/annotations**', (route) => fulfillOcs(route, []))

		await page.goto('/#/library/1/setlists')
		const subtitle = page.locator('[data-test="setlist-timing-subtitle"]')
		await expect(subtitle).toBeVisible()
		// 360s (6:00) + 480s (8:00) = 14:00 initially
		await expect(subtitle).toContainText('14:00')

		// Insert a break after the first song (default 15 min -> 29:00 total)
		await page.locator('[data-test="add-break-after-0"]').click()
		await expect(page.locator('[data-test="setlist-break-row-0"]')).toBeVisible()
		await expect(page.locator('[data-test="setlist-segment-subtotal"]')).toContainText('Set 1: 6:00')
		await expect(subtitle).toContainText('1 breaks')
		await expect(subtitle).toContainText('29:00')

		// Edit break duration to 20 min -> 34:00 total
		const minInput = page.locator('[data-test="setlist-break-minutes-0"]')
		await minInput.fill('20')
		await minInput.dispatchEvent('change')
		await expect(subtitle).toContainText('34:00')

		// Launch Performance Mode
		await page.getByRole('button', { name: 'Play Setlist' }).click()
		const dialog = page.getByRole('dialog', { name: 'Evening Recital' })
		await expect(dialog).toBeVisible()

		// Open Setlist Drawer and verify the intermission break divider appears
		await dialog.getByRole('button', { name: /Setlist/i }).click()
		await expect(dialog.locator('[data-test="drawer-break-0"]')).toBeVisible()
		await expect(dialog.locator('[data-test="drawer-break-0"]')).toContainText('20 min')
		await dialog.getByRole('button', { name: 'Close' }).click()

		// Stage Clock badge: switch to Remaining -> shows -34:00 and Est. finish
		const badge = dialog.locator('[data-test="stage-clock-badge"]')
		await expect(badge).toBeVisible()
		await badge.click()
		await expect(dialog.locator('[data-test="stage-clock-popover"]')).toBeVisible()

		await dialog.locator('[data-test="stage-clock-mode-remaining"]').click()
		await expect(badge).toContainText('-34:00')
		await expect(dialog.locator('[data-test="stage-clock-finish-time"]')).toContainText('Est. finish:')

		// Switch to Countdown timer, select 15m preset, start & pause
		await dialog.locator('[data-test="stage-clock-mode-countdown"]').click()
		await dialog.locator('[data-test="stage-clock-preset-15"]').click()
		await expect(badge).toContainText('15:00')
		await dialog.locator('[data-test="stage-clock-countdown-toggle"]').click()
		await expect(dialog.locator('[data-test="stage-clock-countdown-toggle"]')).toContainText('Pause')
		await dialog.locator('[data-test="stage-clock-countdown-reset"]').click()
		await expect(badge).toContainText('15:00')
	})
})
