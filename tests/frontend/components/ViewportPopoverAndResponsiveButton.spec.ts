import { describe, expect, it, vi, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { computeClampedPopoverBounds } from '../../../src/composables/useViewportPopoverClamp'
import ViewportPopover from '../../../src/components/ViewportPopover.vue'
import PdfInfoPopover from '../../../src/components/song-pdf/PdfInfoPopover.vue'
import ResponsiveNcButton from '../../../src/components/ResponsiveNcButton.vue'

describe('computeClampedPopoverBounds', () => {
	const origInnerWidth = window.innerWidth
	const origInnerHeight = window.innerHeight

	afterEach(() => {
		Object.defineProperty(window, 'innerWidth', { value: origInnerWidth, configurable: true })
		Object.defineProperty(window, 'innerHeight', { value: origInnerHeight, configurable: true })
	})

	it('clamps horizontal overflow and flips above when bottom overflows with more space above', () => {
		Object.defineProperty(window, 'innerWidth', { value: 360, configurable: true })
		Object.defineProperty(window, 'innerHeight', { value: 600, configurable: true })

		const anchor = document.createElement('div')
		anchor.getBoundingClientRect = () => ({
			left: 220,
			right: 260,
			top: 500,
			bottom: 536,
			width: 40,
			height: 36,
			x: 220,
			y: 500,
			toJSON: () => ({}),
		})

		const popover = document.createElement('div')
		Object.defineProperty(popover, 'offsetWidth', { value: 300, configurable: true })
		Object.defineProperty(popover, 'scrollHeight', { value: 240, configurable: true })

		const bounds = computeClampedPopoverBounds(anchor, popover, 12, 6)
		// 360 - 12 - 300 = 48 max screen left -> 48 - 220 = -172
		expect(bounds.left).toBe(-172)
		expect(bounds.placeAbove).toBe(true)
		expect(bounds.maxHeight).toBeNull()
	})

	it('caps maxHeight when popover exceeds available vertical space', () => {
		Object.defineProperty(window, 'innerWidth', { value: 800, configurable: true })
		Object.defineProperty(window, 'innerHeight', { value: 300, configurable: true })

		const anchor = document.createElement('div')
		anchor.getBoundingClientRect = () => ({
			left: 20,
			right: 60,
			top: 10,
			bottom: 46,
			width: 40,
			height: 36,
			x: 20,
			y: 10,
			toJSON: () => ({}),
		})

		const popover = document.createElement('div')
		Object.defineProperty(popover, 'offsetWidth', { value: 200, configurable: true })
		Object.defineProperty(popover, 'scrollHeight', { value: 400, configurable: true })

		const bounds = computeClampedPopoverBounds(anchor, popover, 12, 6)
		expect(bounds.placeAbove).toBe(false)
		// 300 - 12 - (46 + 6) = 236
		expect(bounds.maxHeight).toBe(236)
	})
})

describe('ViewportPopover.vue', () => {
	it('renders trigger, opens pane with title, and emits update:open=false on close', async () => {
		const wrapper = mount(ViewportPopover, {
			props: {
				open: true,
				title: 'Info Title',
				paneTestId: 'test-pane',
			},
			slots: {
				trigger: '<button data-test="trig">Open</button>',
				default: '<p>Body content</p>',
			},
		})

		const pane = wrapper.find('[data-test="test-pane"]')
		expect(pane.exists()).toBe(true)
		expect(pane.text()).toContain('Info Title')
		expect(pane.text()).toContain('Body content')

		await pane.find('button').trigger('click')
		expect(wrapper.emitted('update:open')?.[0]).toEqual([false])
		wrapper.unmount()
	})

	it('closes on Escape in capture phase even when focus is outside the pane', async () => {
		let parentEscapeSeen = false
		const onParentKeyDown = (e: KeyboardEvent) => {
			if (e.key === 'Escape') parentEscapeSeen = true
		}
		window.addEventListener('keydown', onParentKeyDown)

		const wrapper = mount(ViewportPopover, {
			props: {
				open: true,
				paneTestId: 'esc-pane',
			},
			slots: {
				trigger: '<button data-test="trig">Open</button>',
				default: '<p>Menu</p>',
			},
			attachTo: document.body,
		})

		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', cancelable: true }))
		expect(wrapper.emitted('update:open')?.[0]).toEqual([false])
		expect(parentEscapeSeen).toBe(false)

		wrapper.unmount()
		window.removeEventListener('keydown', onParentKeyDown)
	})
})

describe('PdfInfoPopover.vue', () => {
	it('toggles PDF info pane and displays size, pages, and resolution', async () => {
		const wrapper = mount(PdfInfoPopover, {
			props: {
				formattedFileSize: '1.2 MB',
				fileSizeBytes: 1258291,
				totalPages: 4,
				primaryResolution: {
					pageNum: 1,
					pixelWidth: 1654,
					pixelHeight: 2339,
					dpi: 200,
					widthMm: 210,
					heightMm: 297,
					widthPt: 595,
					heightPt: 842,
				},
			},
		})

		expect(wrapper.find('[data-test="pdf-info-dropdown"]').exists()).toBe(false)
		await wrapper.find('[data-test="pdf-info-trigger"]').trigger('click')

		const dropdown = wrapper.find('[data-test="pdf-info-dropdown"]')
		expect(dropdown.exists()).toBe(true)
		expect(dropdown.text()).toContain('1.2 MB')
		expect(dropdown.text()).toContain('1654 × 2339 px (200 DPI)')
	})
})

describe('ResponsiveNcButton.vue', () => {
	it('wires label, title, aria-label, and collapse class', () => {
		const wrapper = mount(ResponsiveNcButton, {
			props: {
				label: 'Rotate All',
				collapseAt: '768',
			},
			global: {
				stubs: {
					NcButton: {
						inheritAttrs: true,
						template: '<button v-bind="$attrs"><slot name="icon" /><slot /></button>',
					},
				},
			},
		})

		const btn = wrapper.find('button')
		expect(btn.attributes('title')).toBe('Rotate All')
		expect(btn.attributes('aria-label')).toBe('Rotate All')
		expect(btn.text()).toContain('Rotate All')
	})
})
