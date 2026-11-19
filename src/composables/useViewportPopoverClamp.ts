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

export interface PopoverClampState {
	left: number
	placeAbove: boolean
	maxHeight: number | null
}

/**
 * Computes horizontal `left` offset plus vertical placement (`placeAbove` and `maxHeight`)
 * so `popoverEl` stays completely within the renderable viewport with `margin` px padding.
 */
export function computeClampedPopoverBounds(
	anchorEl: HTMLElement | null,
	popoverEl: HTMLElement | null,
	margin = 12,
	gap = 6,
): PopoverClampState {
	const left = computeClampedPopoverLeft(anchorEl, popoverEl, margin)
	if (!anchorEl || !popoverEl || typeof window === 'undefined') {
		return { left, placeAbove: false, maxHeight: null }
	}
	const viewportH = window.innerHeight || document.documentElement.clientHeight
	if (viewportH <= 0) {
		return { left, placeAbove: false, maxHeight: null }
	}

	const anchorRect = anchorEl.getBoundingClientRect()
	const popHeight = popoverEl.scrollHeight || popoverEl.getBoundingClientRect().height
	if (popHeight <= 0) {
		return { left, placeAbove: false, maxHeight: null }
	}

	const spaceBelow = viewportH - margin - (anchorRect.bottom + gap)
	const spaceAbove = anchorRect.top - gap - margin
	const placeAbove = popHeight > spaceBelow && spaceAbove > spaceBelow
	const available = placeAbove ? spaceAbove : spaceBelow
	const maxHeight = available > 0 && popHeight > available
		? Math.max(120, Math.floor(available))
		: null

	return { left, placeAbove, maxHeight }
}

/**
 * Keeps an absolutely-positioned popover element within the viewport bounds
 * whenever `isOpen` is true or the window resizes.
 */
export function useViewportPopoverClamp(
	anchorRef: Ref<HTMLElement | null>,
	popoverRef: Ref<HTMLElement | null>,
	isOpen: () => boolean,
	margin = 12,
	gap = 6,
) {
	const clampState = ref<PopoverClampState>({ left: 0, placeAbove: false, maxHeight: null })

	function updatePosition() {
		if (!isOpen()) {
			clampState.value = { left: 0, placeAbove: false, maxHeight: null }
			return
		}
		clampState.value = computeClampedPopoverBounds(anchorRef.value, popoverRef.value, margin, gap)
	}

	watch(isOpen, (open) => {
		if (open) {
			clampState.value = { left: 0, placeAbove: false, maxHeight: null }
			nextTick(updatePosition)
			window.addEventListener('resize', updatePosition)
		} else {
			window.removeEventListener('resize', updatePosition)
			clampState.value = { left: 0, placeAbove: false, maxHeight: null }
		}
	}, { immediate: true })

	onBeforeUnmount(() => {
		window.removeEventListener('resize', updatePosition)
	})

	const popoverPositionStyle = computed(() => {
		const style: Record<string, string> = {
			left: `${clampState.value.left}px`,
		}
		if (clampState.value.placeAbove) {
			style.top = 'auto'
			style.bottom = `calc(100% + ${gap}px)`
		}
		if (clampState.value.maxHeight !== null) {
			style.maxHeight = `${clampState.value.maxHeight}px`
			style.overflowY = 'auto'
		}
		return style
	})

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
