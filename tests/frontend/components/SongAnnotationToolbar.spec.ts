/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SongAnnotationToolbar from '../../../src/components/song-viewer/SongAnnotationToolbar.vue'
import type { EditorToolStyle } from '../../../src/composables/useAnnotationEditor'

describe('SongAnnotationToolbar.vue', () => {
	const defaultStyle: EditorToolStyle = {
		color: '#e53935',
		highlightColor: '#ffeb3b',
		strokeWidth: 2.5,
		highlightWidth: 18,
		opacity: 1,
		highlightOpacity: 0.4,
		lineStyle: 'solid',
		fontSize: 22,
		fontFamily: 'sans-serif',
		textAlign: 'left',
		fill: 'transparent',
		shapeHeight: 18,
		stampSymbol: '𝆑𝆑',
		stampSize: 40,
	}

	it('emits tool changes and opens Unicode music stamp picker', async () => {
		const wrapper = mount(SongAnnotationToolbar, {
			props: {
				activeTool: 'drawing',
				toolStyle: defaultStyle,
			},
		})

		await wrapper.find('[data-test="tool-select"]').trigger('click')
		expect(wrapper.emitted('update:activeTool')?.[0]).toEqual(['select'])

		await wrapper.find('[data-test="tool-stamp"]').trigger('click')
		expect(wrapper.find('[data-test="stamp-picker"]').exists()).toBe(true)

		await wrapper.find('[data-test="stamp-option-𝆏𝆏"]').trigger('click')
		const styleUpdates = wrapper.emitted('update:toolStyle')
		expect(styleUpdates?.[0]?.[0]).toMatchObject({ stampSymbol: '𝆏𝆏' })
	})

	it('emits undo, redo, clear-page, and done actions', async () => {
		const wrapper = mount(SongAnnotationToolbar, {
			props: {
				activeTool: 'drawing',
				toolStyle: defaultStyle,
				canUndo: true,
				canRedo: true,
			},
		})

		await wrapper.find('[data-test="annotation-undo"]').trigger('click')
		expect(wrapper.emitted('undo')).toBeTruthy()

		await wrapper.find('[data-test="annotation-redo"]').trigger('click')
		expect(wrapper.emitted('redo')).toBeTruthy()

		await wrapper.find('[data-test="annotation-clear-page"]').trigger('click')
		expect(wrapper.emitted('clear-page')).toBeTruthy()

		await wrapper.find('[data-test="annotation-done"]').trigger('click')
		expect(wrapper.emitted('done')).toBeTruthy()
	})

	it('renders shape height, width, and line style dropdowns and updates values', async () => {
		const wrapper = mount(SongAnnotationToolbar, {
			attachTo: document.body,
			props: {
				activeTool: 'crescendo',
				toolStyle: defaultStyle,
			},
		})

		expect(wrapper.find('[data-test="shape-height-control"]').exists()).toBe(true)

		await wrapper.find('[data-test="annotation-width-select"]').trigger('click')
		await wrapper.find('[data-test="annotation-width-select-option-4"]').trigger('click')
		expect(wrapper.emitted('update:toolStyle')?.[0]?.[0]).toMatchObject({ strokeWidth: 4 })

		await wrapper.find('[data-test="annotation-line-style-select"]').trigger('click')
		await wrapper.find('[data-test="annotation-line-style-option-dashed"]').trigger('click')
		expect(wrapper.emitted('update:toolStyle')?.[1]?.[0]).toMatchObject({ lineStyle: 'dashed' })

		await wrapper.find('[data-test="shape-height-select"]').trigger('click')
		await wrapper.find('[data-test="shape-height-select-option-45"]').trigger('click')
		expect(wrapper.emitted('update:toolStyle')?.[2]?.[0]).toMatchObject({ shapeHeight: 45 })

		await wrapper.find('[data-test="annotation-width-select"]').trigger('click')
		const slider = wrapper.find('[data-test="annotation-width-slider"]')
		expect(slider.exists()).toBe(true)
		// Logarithmic slider: pos 3 -> 2^3 = 8, pos 6 -> 2^6 = 64
		await slider.setValue('3')
		expect(wrapper.emitted('update:toolStyle')?.[3]?.[0]).toMatchObject({ strokeWidth: 8 })
		await slider.setValue('6')
		expect(wrapper.emitted('update:toolStyle')?.[4]?.[0]).toMatchObject({ strokeWidth: 64 })
		wrapper.unmount()
	})

	it('opens color picker popover and updates color from swatch or custom input', async () => {
		const wrapper = mount(SongAnnotationToolbar, {
			props: {
				activeTool: 'drawing',
				toolStyle: defaultStyle,
			},
		})

		await wrapper.find('[data-test="annotation-color-trigger"]').trigger('click')
		expect(wrapper.find('[data-test="annotation-color-popover"]').exists()).toBe(true)

		await wrapper.find('[data-test="color-swatch-#1e88e5"]').trigger('click')
		expect(wrapper.emitted('update:toolStyle')?.[0]?.[0]).toMatchObject({ color: '#1e88e5' })
		expect(wrapper.find('[data-test="annotation-color-popover"]').exists()).toBe(false)

		await wrapper.find('[data-test="annotation-color-trigger"]').trigger('click')
		await wrapper.find('[data-test="annotation-custom-color-input"]').setValue('#123456')
		expect(wrapper.emitted('update:toolStyle')?.[1]?.[0]).toMatchObject({ color: '#123456' })

		await wrapper.find('[data-test="annotation-opacity-slider"]').setValue('0.45')
		expect(wrapper.emitted('update:toolStyle')?.[2]?.[0]).toMatchObject({ opacity: 0.45 })
	})

	it('renders size dropdown for text and stamp tools', async () => {
		const wrapper = mount(SongAnnotationToolbar, {
			props: {
				activeTool: 'stamp',
				toolStyle: defaultStyle,
			},
		})

		await wrapper.find('[data-test="annotation-size-select"]').trigger('click')
		await wrapper.find('[data-test="annotation-size-select-option-48"]').trigger('click')
		expect(wrapper.emitted('update:toolStyle')?.[0]?.[0]).toMatchObject({ stampSize: 48 })
	})

	it('clamps stamp and color popovers horizontally within the viewport', async () => {
		const { computeClampedPopoverLeft } = await import('../../../src/composables/useViewportPopoverClamp')
		const prevWidth = window.innerWidth
		Object.defineProperty(window, 'innerWidth', { configurable: true, value: 500 })

		const anchor = document.createElement('div')
		anchor.getBoundingClientRect = () => ({
			left: 320,
			right: 380,
			top: 50,
			bottom: 80,
			width: 60,
			height: 30,
			x: 320,
			y: 50,
			toJSON: () => ({}),
		})
		const popover = document.createElement('div')
		popover.getBoundingClientRect = () => ({
			left: 320,
			right: 660,
			top: 84,
			bottom: 284,
			width: 340,
			height: 200,
			x: 320,
			y: 84,
			toJSON: () => ({}),
		})

		// Viewport 500, margin 12, width 340 -> max screen left = 148; anchor at 320 -> shift = -172px
		expect(computeClampedPopoverLeft(anchor, popover)).toBe(-172)

		// When anchor is at x=20, no shift is needed (0px)
		anchor.getBoundingClientRect = () => ({
			left: 20,
			right: 80,
			top: 50,
			bottom: 80,
			width: 60,
			height: 30,
			x: 20,
			y: 50,
			toJSON: () => ({}),
		})
		expect(computeClampedPopoverLeft(anchor, popover)).toBe(0)

		Object.defineProperty(window, 'innerWidth', { configurable: true, value: prevWidth })
	})

	it('allows editing the text or label of a selected text or link annotation', async () => {
		const wrapper = mount(SongAnnotationToolbar, {
			props: {
				activeTool: 'select',
				toolStyle: defaultStyle,
				selectedAnnotation: {
					id: 1,
					uuid: 'link-1',
					songId: 10,
					fileId: 20,
					pageNumber: 0,
					layerIndex: 0,
					type: 'link',
					x: 0.1,
					y: 0.1,
					width: 0.1,
					height: 0.05,
					style: {},
					data: { targetPage: 2, label: 'Coda' },
				},
			},
		})

		const input = wrapper.find('[data-test="selected-annotation-text-input"]')
		expect(input.exists()).toBe(true)
		expect((input.element as HTMLInputElement).value).toBe('Coda')
		await input.setValue('Dal Segno')
		expect(wrapper.emitted('update-selected-data')?.at(-1)?.[0]).toEqual({ label: 'Dal Segno' })

		await wrapper.setProps({
			selectedAnnotation: {
				id: 2,
				uuid: 'text-1',
				songId: 10,
				fileId: 20,
				pageNumber: 0,
				layerIndex: 0,
				type: 'text',
				x: 0.1,
				y: 0.2,
				width: 0.2,
				height: 0.05,
				style: { color: '#000000', fontSize: 22 },
				data: { text: 'Allegro' },
			},
		})

		const textInput = wrapper.find('[data-test="selected-annotation-text-input"]')
		expect((textInput.element as HTMLInputElement).value).toBe('Allegro')
		await textInput.setValue('Andante')
		expect(wrapper.emitted('update-selected-data')?.at(-1)?.[0]).toEqual({ text: 'Andante' })
	})
})
