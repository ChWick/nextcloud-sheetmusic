import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongLinkPickerModal from '../../../src/components/song-viewer/SongLinkPickerModal.vue'

vi.mock('../../../src/utils/file-binary', () => ({
	fetchCachedBinaryFile: vi.fn().mockResolvedValue(null),
}))

describe('SongLinkPickerModal', () => {
	it('steps pages, applies bookmarks, toggles circle/reverse, and emits save payload', async () => {
		const wrapper = mount(SongLinkPickerModal, {
			props: {
				open: true,
				filePath: '/test.pdf',
				totalPages: 4,
				initialSourcePageIndex: 0,
				initialTargetPageIndex: 1,
				bookmarks: [{ name: 'Coda', pageNum: 4 }],
			},
			attachTo: document.body,
		})

		const modal = document.body.querySelector('[data-test="link-picker-modal"]')
		expect(modal).not.toBeNull()

		// Default Box style shows rounded box pin with fallback label
		const sourcePin = modal!.querySelector('[data-test="link-picker-source-pin"]')!
		expect(sourcePin.getAttribute('data-pin-style')).toBe('box')
		expect(sourcePin.textContent?.trim()).toBe('→ p. 2')

		// Step target page forward (2 -> 3)
		;(modal!.querySelector('[data-test="link-picker-target-next"]') as HTMLButtonElement).click()
		await wrapper.vm.$nextTick()
		expect(modal!.querySelector('[data-test="link-picker-target-page"]')?.textContent).toContain('Page 3 / 4')
		expect(sourcePin.textContent?.trim()).toBe('→ p. 3')

		// Select bookmark -> jumps to page 4 and fills label
		const bmSelect = modal!.querySelector('[data-test="link-picker-bookmark-select"]') as HTMLSelectElement
		bmSelect.value = '0'
		bmSelect.dispatchEvent(new Event('change'))
		await wrapper.vm.$nextTick()
		expect(modal!.querySelector('[data-test="link-picker-target-page"]')?.textContent).toContain('Page 4 / 4')
		expect(sourcePin.textContent?.trim()).toBe('Coda')

		// Choose circle style + reverse link -> source pin switches to circle
		;(modal!.querySelector('[data-test="link-picker-style-circle"]') as HTMLButtonElement).click()
		const revCheckbox = modal!.querySelector('[data-test="link-picker-reverse-checkbox"]') as HTMLInputElement
		revCheckbox.checked = true
		revCheckbox.dispatchEvent(new Event('change'))
		await wrapper.vm.$nextTick()
		expect(sourcePin.getAttribute('data-pin-style')).toBe('circle')

		;(modal!.querySelector('[data-test="link-picker-save"]') as HTMLButtonElement).click()
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('save')).toEqual([[expect.objectContaining({
			sourcePageIndex: 0,
			targetPageIndex: 3,
			label: 'Coda',
			linkStyle: 'circle',
			createReverse: true,
		})]])

		wrapper.unmount()
	})
})
