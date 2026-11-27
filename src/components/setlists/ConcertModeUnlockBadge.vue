<script setup lang="ts">
import { computed, onUnmounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import LockOutlineIcon from 'vue-material-design-icons/LockOutline.vue'

const props = withDefaults(defineProps<{
	/** Duration in milliseconds the user must press and hold to unlock Concert Mode. */
	holdMs?: number
}>(), {
	holdMs: 600,
})

const emit = defineEmits<{
	unlock: []
}>()

const effectiveHoldMs = computed(() => Math.max(50, props.holdMs))
const isHolding = ref(false)
const showHint = ref(false)
let holdTimer: ReturnType<typeof setTimeout> | null = null
let hintTimer: ReturnType<typeof setTimeout> | null = null
let unlockedOnThisPress = false

function clearHoldTimer() {
	if (holdTimer !== null) {
		clearTimeout(holdTimer)
		holdTimer = null
	}
}

function clearHintTimer() {
	if (hintTimer !== null) {
		clearTimeout(hintTimer)
		hintTimer = null
	}
}

function flashHint() {
	clearHintTimer()
	showHint.value = true
	hintTimer = setTimeout(() => {
		showHint.value = false
		hintTimer = null
	}, 1800)
}

function startHold() {
	unlockedOnThisPress = false
	isHolding.value = true
	clearHoldTimer()
	holdTimer = setTimeout(() => {
		holdTimer = null
		isHolding.value = false
		unlockedOnThisPress = true
		emit('unlock')
	}, effectiveHoldMs.value)
}

function endHold(wasRelease: boolean) {
	const wasHolding = isHolding.value
	clearHoldTimer()
	isHolding.value = false
	if (wasRelease && wasHolding && !unlockedOnThisPress) {
		flashHint()
	}
}

function onPointerDown(event: PointerEvent) {
	if (event.pointerType === 'mouse' && event.button !== 0) {
		return
	}
	startHold()
}

function onKeyDown(event: KeyboardEvent) {
	if (event.key !== 'Enter' && event.key !== ' ') {
		return
	}
	event.preventDefault()
	event.stopPropagation()
	if (!event.repeat && !isHolding.value) {
		startHold()
	}
}

function onKeyUp(event: KeyboardEvent) {
	if (event.key !== 'Enter' && event.key !== ' ') {
		return
	}
	event.preventDefault()
	event.stopPropagation()
	endHold(true)
}

onUnmounted(() => {
	clearHoldTimer()
	clearHintTimer()
})
</script>

<template>
	<div :class="$style.wrap">
		<span v-if="showHint" :class="$style.hint" role="status" data-test="concert-mode-hint">
			{{ t('sheetmusic', 'Hold to unlock Concert Mode') }}
		</span>
		<button
			type="button"
			:class="[$style.badge, isHolding && $style.holding]"
			:style="{ '--concert-hold-ms': `${effectiveHoldMs}ms` }"
			:aria-label="t('sheetmusic', 'Hold to unlock Concert Mode')"
			:title="t('sheetmusic', 'Hold to unlock Concert Mode')"
			data-test="concert-mode-unlock-button"
			@pointerdown.stop="onPointerDown"
			@pointerup.stop="endHold(true)"
			@pointercancel.stop="endHold(false)"
			@pointerleave.stop="endHold(false)"
			@keydown="onKeyDown"
			@keyup="onKeyUp"
			@blur="endHold(false)"
			@click.stop.prevent>
			<span :class="$style.fill" aria-hidden="true" />
			<LockOutlineIcon :size="18" :class="$style.icon" />
			<span :class="$style.label">{{ t('sheetmusic', 'Concert') }}</span>
		</button>
	</div>
</template>

<style module>
.wrap {
	position: absolute;
	top: 12px;
	right: 12px;
	z-index: 40;
	display: inline-flex;
	align-items: center;
	gap: 8px;
}

.hint {
	padding: 4px 10px;
	border-radius: 999px;
	background: var(--color-main-background, #ffffff);
	color: var(--color-main-text, #222222);
	border: 1px solid var(--color-border-dark, #cccccc);
	font-size: 0.78rem;
	font-weight: 600;
	box-shadow: 0 2px 10px rgba(0, 0, 0, 0.18);
	white-space: nowrap;
}

button[type='button'].badge {
	position: relative;
	display: inline-flex;
	align-items: center;
	gap: 5px;
	margin: 0;
	padding: 6px 12px;
	min-height: 36px;
	border: 1px solid var(--color-border-dark, rgba(128, 128, 128, 0.45));
	border-radius: 999px;
	background: var(--color-main-background, #ffffff);
	color: var(--color-main-text, #222222);
	font-size: 0.8rem;
	font-weight: 600;
	opacity: 0.55;
	overflow: hidden;
	cursor: pointer;
	user-select: none;
	touch-action: none;
	transition: opacity 0.15s ease;
}

button[type='button'].badge:hover,
button[type='button'].badge:focus-visible,
button[type='button'].holding {
	opacity: 0.96;
}

.fill {
	position: absolute;
	inset: 0;
	background: var(--color-primary-element, #0082c9);
	opacity: 0.28;
	transform: scaleX(0);
	transform-origin: left center;
	pointer-events: none;
}

.holding .fill {
	transform: scaleX(1);
	transition: transform var(--concert-hold-ms, 600ms) linear;
}

.icon,
.label {
	position: relative;
	z-index: 1;
}
</style>
