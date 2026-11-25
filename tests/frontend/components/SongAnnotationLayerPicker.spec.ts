/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SongAnnotationLayerPicker from '../../../src/components/song-viewer/SongAnnotationLayerPicker.vue'
import type { AnnotationLayer, SheetAnnotation } from '../../../src/types/annotation'

const sampleLayers: AnnotationLayer[] = [
	{ id: 0, name: 'Default', visible: true, order: 0 },
	{ id: 1, name: 'Fingerings', visible: true, order: 1 },
	{ id: 2, name: 'Organ', visible: false, order: 2 },
]

const selectedAnn: SheetAnnotation = {
	uuid: 'ann-1',
	songId: 1,
	fileId: 10,
	pageNumber: 0,
	layerIndex: 0,
	type: 'stamp',
	x: 0.5,
	y: 0.5,
	width: 0,
	height: 0,
	style: { color: '#000', fontSize: 40 },
	data: { symbol: '\uE522', layerIds: [0] },
}

describe('SongAnnotationLayerPicker.vue', () => {
	it('manages adding, renaming, toggling visibility, reordering, and deleting non-default layers', async () => {
		const wrapper = mount(SongAnnotationLayerPicker, {
			props: {
				layers: sampleLayers,
				activeLayerId: 0,
			},
		})

		expect(wrapper.find('[data-test="annotation-layers-count"]').text()).toBe('2/3')

		await wrapper.find('[data-test="annotation-layers-trigger"]').trigger('click')
		expect(wrapper.find('[data-test="annotation-layer-popover"]').exists()).toBe(true)

		// Default layer has no delete button; custom layers do
		expect(wrapper.find('[data-test="layer-delete-0"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="layer-delete-1"]').exists()).toBe(true)

		// Toggle visibility
		await wrapper.find('[data-test="layer-toggle-visible-1"]').trigger('click')
		expect(wrapper.emitted('toggle-visibility')).toEqual([[1]])

		// Select active layer
		await wrapper.find('[data-test="layer-select-1"]').trigger('click')
		expect(wrapper.emitted('update:activeLayerId')).toEqual([[1]])

		// Reorder
		await wrapper.find('[data-test="layer-move-up-1"]').trigger('click')
		expect(wrapper.emitted('reorder-layer')).toEqual([[1, 'up']])

		// Rename
		await wrapper.find('[data-test="layer-rename-btn-1"]').trigger('click')
		const renameInput = wrapper.find('[data-test="layer-rename-input-1"]')
		await renameInput.setValue('Right Hand')
		await renameInput.trigger('keydown.enter')
		expect(wrapper.emitted('rename-layer')).toEqual([[1, 'Right Hand']])

		// Delete
		await wrapper.find('[data-test="layer-delete-2"]').trigger('click')
		expect(wrapper.emitted('delete-layer')).toEqual([[2]])

		// Add new layer
		await wrapper.find('[data-test="layer-add-input"]').setValue('Dynamics')
		await wrapper.find('[data-test="layer-add-submit"]').trigger('click')
		expect(wrapper.emitted('add-layer')).toEqual([['Dynamics']])
	})

	it('allows assigning a selected annotation to multiple layers while preventing empty membership', async () => {
		const wrapper = mount(SongAnnotationLayerPicker, {
			props: {
				layers: sampleLayers,
				activeLayerId: 0,
				selectedAnnotation: selectedAnn,
			},
		})

		await wrapper.find('[data-test="annotation-layers-trigger"]').trigger('click')
		expect(wrapper.find('[data-test="selected-annotation-layers"]').exists()).toBe(true)

		// Layer 0 is the only assigned layer -> checkbox is disabled to prevent 0-layer state
		const cb0 = wrapper.find('[data-test="selected-layer-checkbox-0"]')
		expect((cb0.element as HTMLInputElement).checked).toBe(true)
		expect((cb0.element as HTMLInputElement).disabled).toBe(true)

		// Adding layer 1 emits multi-layer membership [0, 1]
		const cb1 = wrapper.find('[data-test="selected-layer-checkbox-1"]')
		await cb1.trigger('change')
		expect(wrapper.emitted('update-selected-layers')).toEqual([[[0, 1]]])
	})

	it('emits toggle-selected-layer when multiple annotations are selected', async () => {
		const secondAnn: SheetAnnotation = {
			...selectedAnn,
			id: 2,
			uuid: 'a2',
			layerIndex: 1,
			data: { symbol: '\uED12', layerIds: [1] },
		}
		const wrapper = mount(SongAnnotationLayerPicker, {
			props: {
				layers: sampleLayers,
				activeLayerId: 0,
				selectedAnnotations: [selectedAnn, secondAnn],
			},
		})

		await wrapper.find('[data-test="annotation-layers-trigger"]').trigger('click')
		expect(wrapper.find('[data-test="selected-annotation-layers"]').exists()).toBe(true)

		// Layer 0 is on 1 of 2 selected -> indeterminate, not disabled
		const cb0 = wrapper.find('[data-test="selected-layer-checkbox-0"]')
		expect((cb0.element as HTMLInputElement).checked).toBe(false)
		expect((cb0.element as HTMLInputElement).indeterminate).toBe(true)
		expect((cb0.element as HTMLInputElement).disabled).toBe(false)

		await cb0.trigger('change')
		expect(wrapper.emitted('toggle-selected-layer')).toEqual([[0]])
	})
})
