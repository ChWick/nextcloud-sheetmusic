<script setup lang="ts">
import type { HandwritingSwapState } from '../../composables/useHandwritingInput'
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import { getMusicStampFontFamily, getStampDefinition } from '../../utils/musicSymbols'

const props = defineProps<{
	swapState: HandwritingSwapState | null
}>()

const emit = defineEmits<{
	select: [symbol: string]
	'keep-ink': []
	dismiss: []
}>()

const positionStyle = computed(() => {
	const s = props.swapState
	if (!s) return {}
	const showBelow = s.y < 0.1
	return {
		left: `clamp(8px, ${s.x * 100}%, calc(100% - 170px))`,
		top: showBelow
			? `calc(${s.y * 100}% + 20px)`
			: `max(8px, calc(${s.y * 100}% - 48px))`,
	}
})
</script>

<template>
	<div
		v-if="swapState"
		:class="$style.pill"
		:style="positionStyle"
		role="toolbar"
		:aria-label="t('sheetmusic', 'Handwriting suggestions')"
		data-test="handwriting-swap-pill"
		@pointerdown.stop>
		<button
			v-for="cand in swapState.candidates"
			:key="cand.symbol"
			type="button"
			:class="[$style.candBtn, swapState.activeSymbol === cand.symbol && $style.active]"
			:title="cand.label"
			:aria-label="cand.label"
			:data-test="`handwriting-candidate-${cand.symbol}`"
			@click="emit('select', cand.symbol)">
			<svg
				v-if="getStampDefinition(cand.symbol)?.inkOffset"
				width="22"
				height="22"
				viewBox="0 0 22 22"
				aria-hidden="true">
				<text
					:x="11 + getStampDefinition(cand.symbol)!.inkOffset!.ox * 16"
					:y="11 + getStampDefinition(cand.symbol)!.inkOffset!.oy * 16"
					font-size="16"
					text-anchor="middle"
					dominant-baseline="alphabetic"
					fill="currentColor"
					:font-family="getMusicStampFontFamily(cand.symbol, getStampDefinition(cand.symbol)?.fontKind)">
					{{ cand.symbol }}
				</text>
			</svg>
			<span
				v-else
				:class="$style.fingeringGlyph"
				:style="{ fontFamily: getMusicStampFontFamily(cand.symbol, getStampDefinition(cand.symbol)?.fontKind) }">
				{{ cand.symbol }}
			</span>
		</button>

		<span :class="$style.divider" aria-hidden="true" />

		<button
			type="button"
			:class="$style.keepInkBtn"
			:title="t('sheetmusic', 'Keep ink as freehand drawing')"
			:aria-label="t('sheetmusic', 'Keep ink as freehand drawing')"
			data-test="handwriting-keep-ink"
			@click="emit('keep-ink')">
			<Pencil :size="15" />
		</button>
	</div>
</template>

<style module>
.pill {
	position: absolute;
	z-index: 25;
	display: inline-flex;
	align-items: center;
	gap: 3px;
	padding: 3px 5px;
	background: var(--color-main-background, #ffffff);
	color: var(--color-main-text, #222222);
	border: 1px solid var(--color-border-dark, #cccccc);
	border-radius: 999px;
	box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2);
	pointer-events: auto;
	transform: translateX(-50%);
}

button[type='button'].candBtn,
button[type='button'].keepInkBtn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	min-width: 32px;
	min-height: 32px;
	margin: 0;
	padding: 0;
	border: 1px solid transparent;
	border-radius: 999px;
	background: transparent;
	color: var(--color-main-text, #222222);
	cursor: pointer;
	transition: background-color 0.12s, border-color 0.12s;
}

button[type='button'].candBtn:hover,
button[type='button'].keepInkBtn:hover {
	background: var(--color-background-hover, #f0f0f0);
}

button[type='button'].candBtn.active {
	background: var(--color-primary-light, #e6f4fa);
	border-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element, #0082c9);
}

.fingeringGlyph {
	font-size: 16px;
	font-weight: 700;
	line-height: 1;
}

.divider {
	width: 1px;
	height: 18px;
	background: var(--color-border, #dddddd);
	margin: 0 2px;
}
</style>
