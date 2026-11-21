import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ConfirmDeleteDialog from '../../../src/components/ConfirmDeleteDialog.vue'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string) => text,
	getLanguage: () => 'en',
	isRTL: () => false,
}))

describe('ConfirmDeleteDialog.vue', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
	})

	it('renders title, body slot, and emits confirm/close when buttons are clicked', async () => {
		const wrapper = mount(ConfirmDeleteDialog, {
			props: {
				open: true,
				title: 'Delete item',
				confirmLabel: 'Remove now',
			},
			slots: {
				default: '<p>Are you sure?</p>',
			},
			attachTo: document.body,
		})

		expect(document.body.textContent).toContain('Delete item')
		expect(document.body.textContent).toContain('Are you sure?')

		const cancelButton = Array.from(document.querySelectorAll('button')).find(
			b => b.textContent?.includes('Cancel'),
		)
		const confirmButton = Array.from(document.querySelectorAll('button')).find(
			b => b.textContent?.includes('Remove now'),
		)

		expect(cancelButton).toBeDefined()
		expect(confirmButton).toBeDefined()

		await cancelButton?.click()
		await confirmButton?.click()

		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.emitted('confirm')).toHaveLength(1)
	})

	it('displays error message with alert role and blocks closing while deleting', async () => {
		const wrapper = mount(ConfirmDeleteDialog, {
			props: {
				open: true,
				title: 'Delete item',
				confirmLabel: 'Delete',
				isDeleting: true,
				error: 'Permission denied',
			},
			attachTo: document.body,
		})

		const alert = document.body.querySelector('[role="alert"]')
		expect(alert?.textContent).toBe('Permission denied')

		const cancelButton = Array.from(document.querySelectorAll('button')).find(
			b => b.textContent?.includes('Cancel'),
		)
		await cancelButton?.click()

		expect(wrapper.emitted('close')).toBeUndefined()
	})
})
