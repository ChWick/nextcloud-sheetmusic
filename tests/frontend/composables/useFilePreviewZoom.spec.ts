/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { useFilePreviewZoom } from '../../../src/composables/useFilePreviewZoom'

describe('useFilePreviewZoom', () => {
	it('steps PDF zoom in/out, detects custom scales, and resets pan on fit modes', () => {
		const container = ref<HTMLElement | undefined>({ scrollLeft: 50, scrollTop: 80 } as HTMLElement)
		const onResetPan = vi.fn()
		const onRerender = vi.fn()

		const zoom = useFilePreviewZoom({
			initialZoomMode: 'fit-width',
			pdfContainer: container,
			isPdfFile: computed(() => true),
			isImageFile: computed(() => false),
			onResetPan,
			onRerender,
		})

		expect(zoom.zoomSelectValue.value).toBe('fit-width')
		expect(zoom.isCustomZoom.value).toBe(false)

		zoom.effectiveScale.value = 1.0
		zoom.zoomIn()
		expect(zoom.zoomMode.value).toBe('custom')
		expect(zoom.customScale.value).toBe(1.25)
		expect(zoom.zoomSelectValue.value).toBe('1.25')
		expect(zoom.isCustomZoom.value).toBe(false)

		zoom.effectiveScale.value = 1.15
		zoom.customScale.value = 1.15
		expect(zoom.zoomSelectValue.value).toBe('custom')
		expect(zoom.isCustomZoom.value).toBe(true)

		zoom.setZoomMode('fit-page')
		expect(onResetPan).toHaveBeenCalledTimes(1)
		expect(container.value?.scrollLeft).toBe(0)
		expect(container.value?.scrollTop).toBe(0)
		expect(onRerender).toHaveBeenCalled()
	})

	it('scales and rotates image previews without triggering PDF rerenders', () => {
		const onResetPan = vi.fn()
		const onRerender = vi.fn()

		const zoom = useFilePreviewZoom({
			initialZoomMode: 'fit-width',
			pdfContainer: ref(undefined),
			isPdfFile: computed(() => false),
			isImageFile: computed(() => true),
			onResetPan,
			onRerender,
		})

		zoom.zoomIn()
		expect(zoom.imageScale.value).toBe(1.25)
		zoom.zoomOut()
		expect(zoom.imageScale.value).toBe(1.0)
		zoom.rotateClockwise()
		expect(zoom.rotation.value).toBe(90)
		expect(zoom.imageStyle.value.transform).toBe('rotate(90deg) scale(1)')
		expect(onRerender).not.toHaveBeenCalled()
	})
})
