<script setup lang="ts">
import type { HandwritingSwapState } from '../../composables/useHandwritingInput'
import { computed, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import { getStampDefinition, isFingeringDigit } from '../../utils/musicSymbols'
import { getMusicFontFamily, type MusicFontId } from '../../utils/musicFont'

const props = withDefaults(defineProps<{
	swapState: HandwritingSwapState | null
	musicFont?: MusicFontId
}>(), {
	musicFont: 'bravura',
})

const emit = defineEmits<{
	select: [symbol: string]
	'keep-ink': []
	delete: []
	'extend-timeout': []
	dismiss: []
}>()

const showMore = ref(false)

watch(
	() => props.swapState?.uuid,
	() => {
		showMore.value = false
	},
)

const primaryCandidates = computed(() => props.swapState?.candidates.slice(0, 3) ?? [])
const overflowCandidates = computed(() => props.swapState?.candidates.slice(3, 7) ?? [])
const isOverflowActive = computed(() => {
	const active = props.swapState?.activeSymbol
	return Boolean(active && overflowCandidates.value.some((c) => c.symbol === active))
})

function pillFontSize(symbol: string): number {
	return isFingeringDigit(symbol) ? 30 : 18
}

function toggleMore() {
	showMore.value = !showMore.value
	emit('extend-timeout')
}

function selectSymbol(symbol: string) {
	emit('select', symbol)
}

const positionStyle = computed(() => {
	const s = props.swapState
	if (!s) return {}
	const showBelow = s.y < 0.14
	return {
		left: `clamp(120px, ${s.x * 100}%, calc(100% - 120px))`,
		top: showBelow
			? `calc(${s.y * 100}% + 20px)`
			: `max(8px, calc(${s.y * 100}% - 48px))`,
	}
})
</script>

<template>
	<div
		v-if="swapState"
		:class="$style.wrapper"
		:style="positionStyle"
		role="toolbar"
		:aria-label="t('sheetmusic', 'Handwriting suggestions')"
		data-test="handwriting-swap-pill"
		@pointerdown.stop>
		<div :class="$style.pill">
			<button
				v-for="cand in primaryCandidates"
				:key="cand.symbol"
				type="button"
				:class="[$style.candBtn, swapState.activeSymbol === cand.symbol && $style.active]"
				:title="cand.label"
				:aria-label="cand.label"
				:data-test="`handwriting-candidate-${cand.symbol}`"
				@click="selectSymbol(cand.symbol)">
				<svg
					v-if="getStampDefinition(cand.symbol)"
					width="24"
					height="24"
					viewBox="0 0 24 24"
					aria-hidden="true">
					<text
						:x="12 + getStampDefinition(cand.symbol)!.inkOffset.ox * pillFontSize(cand.symbol)"
						:y="12 + getStampDefinition(cand.symbol)!.inkOffset.oy * pillFontSize(cand.symbol)"
						:font-size="pillFontSize(cand.symbol)"
						text-anchor="middle"
						dominant-baseline="alphabetic"
						fill="currentColor"
						:font-family="getMusicFontFamily(props.musicFont)">
						{{ cand.symbol }}
					</text>
				</svg>
				<span
					v-else
					:class="$style.fingeringGlyph"
					:style="{ fontFamily: getMusicFontFamily(props.musicFont) }">
					{{ cand.symbol }}
				</span>
			</button>

			<button
				v-if="overflowCandidates.length > 0"
				type="button"
				:class="[$style.actionBtn, (showMore || isOverflowActive) && $style.active]"
				:title="t('sheetmusic', 'More symbol suggestions')"
				:aria-label="t('sheetmusic', 'More symbol suggestions')"
				:aria-expanded="showMore"
				data-test="handwriting-more"
				@click="toggleMore">
				<DotsHorizontal :size="16" />
			</button>

			<span :class="$style.divider" aria-hidden="true" />

			<button
				type="button"
				:class="$style.actionBtn"
				:title="t('sheetmusic', 'Keep as handwriting')"
				:aria-label="t('sheetmusic', 'Keep as handwriting')"
				data-test="handwriting-keep-ink"
				@click="emit('keep-ink')">
				<Pencil :size="15" />
			</button>

			<button
				type="button"
				:class="[$style.actionBtn, $style.deleteBtn]"
				:title="t('sheetmusic', 'Delete')"
				:aria-label="t('sheetmusic', 'Delete')"
				data-test="handwriting-delete"
				@click="emit('delete')">
				<DeleteOutline :size="16" />
			</button>
		</div>

		<div
			v-if="showMore && overflowCandidates.length > 0"
			:class="$style.moreRow"
			data-test="handwriting-more-menu">
			<button
				v-for="cand in overflowCandidates"
				:key="cand.symbol"
				type="button"
				:class="[$style.candBtn, swapState.activeSymbol === cand.symbol && $style.active]"
				:title="cand.label"
				:aria-label="cand.label"
				:data-test="`handwriting-candidate-${cand.symbol}`"
				@click="selectSymbol(cand.symbol)">
				<svg
					v-if="getStampDefinition(cand.symbol)"
					width="24"
					height="24"
					viewBox="0 0 24 24"
					aria-hidden="true">
					<text
						:x="12 + getStampDefinition(cand.symbol)!.inkOffset.ox * pillFontSize(cand.symbol)"
						:y="12 + getStampDefinition(cand.symbol)!.inkOffset.oy * pillFontSize(cand.symbol)"
						:font-size="pillFontSize(cand.symbol)"
						text-anchor="middle"
						dominant-baseline="alphabetic"
						fill="currentColor"
						:font-family="getMusicFontFamily(props.musicFont)">
						{{ cand.symbol }}
					</text>
				</svg>
				<span
					v-else
					:class="$style.fingeringGlyph"
					:style="{ fontFamily: getMusicFontFamily(props.musicFont) }">
					{{ cand.symbol }}
				</span>
			</button>
		</div>
	</div>
</template>

<style module>
.wrapper {
	position: absolute;
	z-index: 25;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 4px;
	pointer-events: auto;
	transform: translateX(-50%);
}

.pill,
.moreRow {
	display: inline-flex;
	align-items: center;
	gap: 3px;
	padding: 3px 5px;
	background: var(--color-main-background, #ffffff);
	color: var(--color-main-text, #222222);
	border: 1px solid var(--color-border-dark, #cccccc);
	border-radius: 999px;
	box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2);
}

button[type='button'].candBtn,
button[type='button'].actionBtn {
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
	transition: background-color 0.12s, border-color 0.12s, color 0.12s;
}

button[type='button'].candBtn:hover,
button[type='button'].actionBtn:hover {
	background: var(--color-background-hover, #f0f0f0);
}

button[type='button'].candBtn.active,
button[type='button'].actionBtn.active {
	background: var(--color-primary-light, #e6f4fa);
	border-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element, #0082c9);
}

button[type='button'].deleteBtn:hover {
	color: var(--color-error, #e53935);
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
