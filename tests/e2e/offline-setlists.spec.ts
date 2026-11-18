import { expect, test, type Page } from '@playwright/test'
import { setupMockApi } from './helpers/mock-api'

async function setupSetlistsOfflineMocks(page: Page) {
	await setupMockApi(page)

	const setlist1 = {
		id: 10,
		libraryId: 1,
		name: 'Sunday Concert',
		itemCount: 1,
		items: [{ id: 101, songId: 10001, position: 0 }],
	}
	const setlist2 = {
		id: 11,
		libraryId: 1,
		name: 'Evening Rehearsal',
		itemCount: 0,
		items: [],
	}

	await page.route('**/api/libraries/*/setlists', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({
				ocs: { meta: { status: 'ok', statuscode: 200 }, data: [setlist1, setlist2] },
			}),
		})
	})

	await page.route('**/api/libraries/*/setlists/*', async (route) => {
		const url = route.request().url()
		if (url.endsWith('/files')) {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ocs: { meta: { status: 'ok', statuscode: 200 }, data: [] } }),
			})
			return
		}
		const target = url.includes('/setlists/11') ? setlist2 : setlist1
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({ ocs: { meta: { status: 'ok', statuscode: 200 }, data: target } }),
		})
	})

	await page.route('**/api/songs/*/annotations**', async (route) => {
		await route.fulfill({
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify({ ocs: { meta: { status: 'ok', statuscode: 200 }, data: [] } }),
		})
	})
}

test.describe('Offline Setlists & Online/Offline Transitions', () => {
	test('saves and removes a setlist offline in regular browser mode', async ({ page }) => {
		await setupSetlistsOfflineMocks(page)
		await page.goto('/#/library/1/setlists')

		await expect(page.getByRole('button', { name: 'Save offline' })).toBeVisible()
		await page.getByRole('button', { name: 'Save offline' }).click()

		await expect(page.getByRole('button', { name: 'Available offline' })).toBeVisible()
		await expect(page.locator('[title="Available offline"]').first()).toBeVisible()

		const stored = await page.evaluate(() => localStorage.getItem('sheetmusic_offline_setlists_v1'))
		expect(stored).toContain('"setlistId":10')

		// Remove offline availability
		await page.getByRole('button', { name: 'Available offline' }).click()
		await expect(page.getByRole('button', { name: 'Save offline' })).toBeVisible()
		const afterRemove = await page.evaluate(() => localStorage.getItem('sheetmusic_offline_setlists_v1'))
		expect(afterRemove).toBe('{}')
	})

	test('simulated offline mode verifies cached setlist, plays it, and returns online to same setlist', async ({ page }) => {
		await setupSetlistsOfflineMocks(page)
		await page.goto('/#/library/1/setlists')

		// Save setlist offline first
		await page.getByRole('button', { name: 'Save offline' }).click()
		await expect(page.getByRole('button', { name: 'Available offline' })).toBeVisible()

		// Enter simulated offline mode via main navigation menu
		await page.locator('.app-navigation-entry__name').getByText('Offline mode', { exact: true }).click()
		await expect(page.getByText('Simulated offline mode — verifying setlists saved for offline use')).toBeVisible()
		await expect(page.getByRole('button', { name: 'Return online' })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Sunday Concert' })).toBeVisible()

		// Return online while viewing Sunday Concert -> stays on Sunday Concert
		await page.getByRole('button', { name: 'Return online' }).click()
		await expect(page).toHaveURL(/#\/library\/1\/setlists\?setlist=10/)
		await expect(page.getByRole('heading', { name: 'Sunday Concert' })).toBeVisible()
	})

	test('preserves active performance playback across browser offline/online transition', async ({ context, page }) => {
		await setupSetlistsOfflineMocks(page)
		await page.goto('/#/library/1/setlists')

		await page.getByRole('button', { name: 'Save offline' }).click()
		await expect(page.getByRole('button', { name: 'Available offline' })).toBeVisible()

		// Simulate real browser offline event
		await context.setOffline(true)
		await expect(page.getByText('Offline mode — showing setlists saved for offline use')).toBeVisible()
		await expect(page.getByRole('button', { name: 'Return online' })).not.toBeVisible()

		// Start performance playback offline
		await page.getByRole('button', { name: 'Play' }).click()
		await expect(page.getByRole('button', { name: 'Exit' })).toBeVisible()

		// Regain connectivity while playing -> continues playing in online SetlistsView
		await context.setOffline(false)
		await expect(page).toHaveURL(/#\/library\/1\/setlists\?setlist=10/)
		await expect(page.getByRole('button', { name: 'Exit' })).toBeVisible()
	})
})
