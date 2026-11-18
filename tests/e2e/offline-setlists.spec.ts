import { expect, test, type Page, type Route } from '@playwright/test'
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

async function setupSetlistsOfflineMocks(page: Page) {
	await setupMockApi(page)

	const setlist1 = {
		id: 10,
		libraryId: 1,
		name: 'Sunday Concert',
		itemCount: 1,
		items: [{ id: 101, songId: 5, position: 0 }],
	}
	const setlist2 = {
		id: 11,
		libraryId: 1,
		name: 'Evening Rehearsal',
		itemCount: 0,
		items: [],
	}

	await page.route('**/api/libraries/*/setlists', route => fulfillOcs(route, [setlist1, setlist2]))

	await page.route('**/api/libraries/*/setlists/**', async (route) => {
		const url = route.request().url()
		if (url.endsWith('/files')) {
			return fulfillOcs(route, [])
		}
		return fulfillOcs(route, url.includes('/setlists/11') ? setlist2 : setlist1)
	})

	await page.route('**/api/songs/*/annotations**', route => fulfillOcs(route, []))
}

async function saveFirstSetlistOffline(page: Page) {
	await page.goto('/#/library/1/setlists')
	await expect(page.getByRole('button', { name: 'Save offline' })).toBeVisible()
	await page.getByRole('button', { name: 'Save offline' }).click()
	await expect(page.getByRole('button', { name: 'Available offline' })).toBeVisible()
}

test.describe('Offline Setlists & Online/Offline Transitions', () => {
	test('saves and removes a setlist offline in regular browser mode', async ({ page }) => {
		await setupSetlistsOfflineMocks(page)
		await saveFirstSetlistOffline(page)

		await expect(page.locator('[title="Available offline"]').first()).toBeVisible()
		const stored = await page.evaluate(() => localStorage.getItem('sheetmusic_offline_setlists_v1'))
		expect(stored).toContain('"setlistId":10')

		// Remove offline availability
		await page.getByRole('button', { name: 'Available offline' }).click()
		await expect(page.getByRole('button', { name: 'Save offline' })).toBeVisible()
		const afterRemove = await page.evaluate(() => localStorage.getItem('sheetmusic_offline_setlists_v1'))
		expect(afterRemove).toBe('{}')
	})

	test('simulated offline mode verifies cached setlist without network requests and returns online to same setlist', async ({ page }) => {
		await setupSetlistsOfflineMocks(page)
		await saveFirstSetlistOffline(page)

		const offlineNetworkRequests: string[] = []
		page.on('request', (req) => {
			const url = req.url()
			if (url.includes('/api/') || url.includes('/remote.php/dav/')) {
				offlineNetworkRequests.push(url)
			}
		})

		// Enter simulated offline mode via main navigation menu
		await page.locator('.app-navigation-entry__name').getByText('Offline mode', { exact: true }).click()
		await expect(page.getByText('Simulated offline mode — verifying setlists saved for offline use')).toBeVisible()
		await expect(page.getByRole('button', { name: 'Return online' })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Sunday Concert' })).toBeVisible()

		// Play setlist offline and exit -> must use Cache Storage with zero API/DAV network requests
		await page.getByRole('button', { name: 'Play Setlist' }).click()
		await expect(page.getByRole('button', { name: 'Exit' })).toBeVisible()
		await page.getByRole('button', { name: 'Exit' }).click()
		expect(offlineNetworkRequests).toEqual([])

		// Return online while viewing Sunday Concert -> stays on Sunday Concert
		await page.getByRole('button', { name: 'Return online' }).click()
		await expect(page).toHaveURL(/#\/library\/1\/setlists\?setlist=10/)
		await expect(page.getByRole('heading', { name: 'Sunday Concert' })).toBeVisible()
	})

	test('tracks per-setlist sync spinner independently when switching setlists', async ({ page }) => {
		await setupSetlistsOfflineMocks(page)

		let releaseAnnotations!: () => void
		const annotationsGate = new Promise<void>((resolve) => {
			releaseAnnotations = resolve
		})
		await page.route('**/api/songs/*/annotations**', async (route) => {
			await annotationsGate
			await fulfillOcs(route, [])
		})

		await page.goto('/#/library/1/setlists')
		await page.getByRole('button', { name: 'Save offline' }).click()

		// Sidebar row for Sunday Concert shows spinning indicator while syncing
		await expect(page.locator('span[title="Saving offline…"]')).toBeVisible()

		// Switch to Evening Rehearsal -> header must show its own "Save offline" state, not spinning
		await page.getByRole('button', { name: 'Evening Rehearsal' }).click()
		await expect(page.getByRole('heading', { name: 'Evening Rehearsal' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Save offline' })).toBeVisible()
		await expect(page.locator('span[title="Saving offline…"]')).toBeVisible()

		releaseAnnotations()
		await expect(page.getByRole('img', { name: 'Available offline' }).first()).toBeVisible()
	})

	test('restores active performance playback route when returning online', async ({ page }) => {
		await setupSetlistsOfflineMocks(page)
		await page.goto('/#/library/1/setlists?setlist=10&play=1&item=0')

		await expect(page.getByRole('button', { name: 'Exit' })).toBeVisible()
		await page.getByRole('button', { name: 'Exit' }).click()
		await expect(page.getByRole('heading', { name: 'Sunday Concert' })).toBeVisible()
	})
})
