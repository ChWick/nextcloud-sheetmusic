<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useClickOutside } from '../composables/useClickOutside'
import { useViewportPopoverClamp } from '../composables/useViewportPopoverClamp'

const props = withDefaults(
	defineProps<{
		/** Controlled open state. */
		open: boolean
		/** Optional header title; when provided, renders a header row with a close button. */
		title?: string
		/** Optional `data-test` attribute on the popup pane. */
		paneTestId?: string
		/** Optional extra CSS class(es) for the popup pane. */
		paneClass?: string | string[] | Record<string, boolean>
		/** Minimum viewport margin in px. */
		margin?: number
		/** Vertical gap between anchor and popup in px. */
		gap?: number
	}>(),
	{
		title: undefined,
		paneTestId: undefined,
		paneClass: undefined,
		margin: 12,
		gap: 6,
	},
)

const emit = defineEmits<{
	'update:open': [value: boolean]
}>()

const wrapRef = ref<HTMLElement | null>(null)
const paneRef = ref<HTMLElement | null>(null)

const isOpenModel = computed({
	get: () => props.open,
	set: (val: boolean) => emit('update:open', val),
})

useClickOutside(wrapRef, isOpenModel)

const { popoverPositionStyle } = useViewportPopoverClamp(
	wrapRef,
	paneRef,
	() => props.open,
	props.margin,
	props.gap,
)

function toggle() {
	emit('update:open', !props.open)
}

function close() {
	emit('update:open', false)
}

function onWindowKeyDown(e: KeyboardEvent) {
	if (e.key === 'Escape' && props.open) {
		e.stopPropagation()
		e.preventDefault()
		close()
	}
}

watch(
	() => props.open,
	(isOpen) => {
		if (typeof window === 'undefined') return
		if (isOpen) {
			window.addEventListener('keydown', onWindowKeyDown, true)
		} else {
			window.removeEventListener('keydown', onWindowKeyDown, true)
		}
	},
	{ immediate: true, flush: 'sync' },
)

onUnmounted(() => {
	if (typeof window !== 'undefined') {
		window.removeEventListener('keydown', onWindowKeyDown, true)
	}
})
</script>

<template>
	<div ref="wrapRef" :class="$style.popoverWrap">
		<slot name="trigger" :open="open" :toggle="toggle" :close="close" />
		<div
			v-if="open"
			ref="paneRef"
			:class="[$style.popoverPane, paneClass]"
			:style="popoverPositionStyle"
			:data-test="paneTestId">
			<div v-if="title" :class="$style.popoverHeader">
				<strong>{{ title }}</strong>
				<button
					type="button"
					:class="$style.closeBtn"
					:aria-label="t('sheetmusic', 'Close')"
					@click="close">
					×
				</button>
			</div>
			<slot :close="close" />
		</div>
	</div>
</template>

<style module>
.popoverWrap {
	position: relative;
	display: inline-flex;
	align-items: center;
}

.popoverPane {
	position: absolute;
	top: calc(100% + 6px);
	left: 0;
	z-index: 200;
	max-width: calc(100vw - 24px);
	box-sizing: border-box;
	background: var(--color-main-background);
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large, 8px);
	box-shadow: 0 6px 20px rgba(0, 0, 0, 0.18);
	padding: 10px 12px;
	font-size: 12px;
	color: var(--color-main-text);
	white-space: normal;
}

.popoverHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 6px;
	margin-bottom: 8px;
}

.closeBtn {
	border: none;
	background: transparent;
	cursor: pointer;
	font-size: 16px;
	line-height: 1;
	color: var(--color-text-maxcontrast);
	padding: 0 4px;
}
</style>
