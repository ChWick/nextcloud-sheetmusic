import type { Ref } from 'vue'
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'

/**
 * Computes the horizontal `left` offset (in px, relative to `anchorEl`) needed
 * to keep `popoverEl` completely within the viewport with `margin` px padding.
 */
export function computeClampedPopoverLeft(
	anchorEl: HTMLElement | null,
	popoverEl: HTMLElement | null,
	margin = 12,
): number {
	if (!anchorEl || !popoverEl || typeof window === 'undefined') return 0
	const viewportW = window.innerWidth || document.documentElement.clientWidth
	if (viewportW <= 0) return 0

	const anchorRect = anchorEl.getBoundingClientRect()
	const popWidth = popoverEl.offsetWidth || popoverEl.getBoundingClientRect().width
	if (popWidth <= 0) return 0

	const maxScreenLeft = Math.max(margin, viewportW - margin - popWidth)
	const clampedScreenLeft = Math.max(margin, Math.min(anchorRect.left, maxScreenLeft))
	return Math.round(clampedScreenLeft - anchorRect.left)
}

/**
 * Keeps an absolutely-positioned popover element within the horizontal viewport bounds
 * whenever `isOpen` is true or the window resizes.
 */
export function useViewportPopoverClamp(
	anchorRef: Ref<HTMLElement | null>,
	popoverRef: Ref<HTMLElement | null>,
	isOpen: () => boolean,
	margin = 12,
) {
	const offsetX = ref(0)

	function updatePosition() {
		if (!isOpen()) {
			offsetX.value = 0
			return
		}
		offsetX.value = computeClampedPopoverLeft(anchorRef.value, popoverRef.value, margin)
	}

	watch(isOpen, (open) => {
		if (open) {
			offsetX.value = 0
			nextTick(updatePosition)
			window.addEventListener('resize', updatePosition)
		} else {
			window.removeEventListener('resize', updatePosition)
			offsetX.value = 0
		}
	}, { immediate: true })

	onBeforeUnmount(() => {
		window.removeEventListener('resize', updatePosition)
	})

	const popoverPositionStyle = computed(() => ({
		left: `${offsetX.value}px`,
	}))

	return {
		popoverPositionStyle,
		updatePosition,
	}
}

/**
 * Clamps an inline (`appendToBody=false`) NcSelect dropdown menu within the viewport.
 */
export function clampNcSelectDropdown(selectInstance: { $el?: HTMLElement } | null, margin = 12) {
	if (!selectInstance?.$el) return
	nextTick(() => {
		const root = selectInstance.$el
		if (!root) return
		const menu = root.querySelector('.vs__dropdown-menu') as HTMLElement | null
		if (!menu) return
		menu.style.left = '0px'
		const offset = computeClampedPopoverLeft(root, menu, margin)
		menu.style.left = `${offset}px`
	})
}
