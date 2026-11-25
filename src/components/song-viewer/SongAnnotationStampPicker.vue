<script setup lang="ts">
import type { StampCategory } from '../../types/annotation'
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import MusicNote from 'vue-material-design-icons/MusicNote.vue'
import ViewportPopover from '../ViewportPopover.vue'
import {
	isFingeringDigit,
	MUSIC_FONT_FAMILY,
	MUSIC_STAMPS,
	STAMP_CATEGORIES,
} from '../../utils/musicSymbols'

const props = defineProps<{
	active: boolean
	open: boolean
	stampSymbol: string
}>()

const emit = defineEmits<{
	toggle: []
	select: [symbol: string]
}>()

const activeStampCategory = ref<StampCategory>('dynamic')

const CATEGORY_EXAMPLE_SYMBOLS: Record<StampCategory, [string, string]> = {
	dynamic: ['\uE522', '\uE520'],
	accidental: ['\uE262', '\uE260'],
	ornament: ['\uE4C0', '\uE566'],
	articulation: ['\uE4A0', '\uE4A2'],
	navigation: ['\uE047', '\uE048'],
	fingering: ['\uED11', '\uED12'],
	rhythm: ['\uE1D5', '\uE4E5'],
}

function getStampDef(sym: string) {
	return MUSIC_STAMPS.find((s) => s.symbol === sym)
}

function pickerFontSize(symbol: string, base = 22): number {
	return isFingeringDigit(symbol) ? Math.round(base * 1.65) : base
}

const filteredStamps = computed(() =>
	MUSIC_STAMPS.filter((s) => s.category === activeStampCategory.value),
)
</script>

<template>
	<ViewportPopover
		:open="props.open"
		pane-test-id="stamp-picker"
		:pane-class="$style.stampPopover"
		:gap="4"
		@update:open="!$event && props.open && emit('toggle')">
		<template #trigger>
			<button
				type="button"
				:class="[$style.toolBtn, props.active && $style.active]"
				:title="t('sheetmusic', 'Music Symbols & Fingerings (M, 0–5, F, B, N, #)')"
				:aria-label="t('sheetmusic', 'Music Symbols & Fingerings (M, 0–5, F, B, N, #)')"
				data-test="tool-stamp"
				@click="emit('toggle')">
				<MusicNote :size="18" />
				<svg
					v-if="getStampDef(props.stampSymbol)"
					width="20"
					height="20"
					viewBox="0 0 20 20"
					aria-hidden="true">
					<text
						:x="10 + getStampDef(props.stampSymbol)!.inkOffset.ox * pickerFontSize(props.stampSymbol, 18)"
						:y="10 + getStampDef(props.stampSymbol)!.inkOffset.oy * pickerFontSize(props.stampSymbol, 18)"
						:font-size="pickerFontSize(props.stampSymbol, 18)"
						text-anchor="middle"
						dominant-baseline="alphabetic"
						fill="currentColor"
						:font-family="MUSIC_FONT_FAMILY">
						{{ props.stampSymbol }}
					</text>
				</svg>
				<span v-else :class="$style.stampPreview" :style="{ fontFamily: MUSIC_FONT_FAMILY }">
					{{ props.stampSymbol }}
				</span>
			</button>
		</template>
			<div :class="$style.stampTabs" role="tablist">
				<button
					v-for="cat in STAMP_CATEGORIES"
					:key="cat.id"
					type="button"
					role="tab"
					:aria-selected="activeStampCategory === cat.id"
					:title="cat.label"
					:aria-label="cat.label"
					:class="[$style.stampTab, activeStampCategory === cat.id && $style.active]"
					:data-test="`stamp-tab-${cat.id}`"
					@click="activeStampCategory = cat.id">
					<template v-for="sym in CATEGORY_EXAMPLE_SYMBOLS[cat.id]" :key="sym">
						<svg
							v-if="getStampDef(sym)"
							width="20"
							height="24"
							viewBox="0 0 20 24"
							aria-hidden="true">
							<text
								:x="10 + getStampDef(sym)!.inkOffset.ox * pickerFontSize(sym, 18)"
								:y="12 + getStampDef(sym)!.inkOffset.oy * pickerFontSize(sym, 18)"
								:font-size="pickerFontSize(sym, 18)"
								text-anchor="middle"
								dominant-baseline="alphabetic"
								fill="currentColor"
								:font-family="MUSIC_FONT_FAMILY">
								{{ sym }}
							</text>
						</svg>
					</template>
				</button>
			</div>

			<div :class="$style.stampGrid">
				<button
					v-for="st in filteredStamps"
					:key="st.symbol"
					type="button"
					:class="[$style.stampCell, props.stampSymbol === st.symbol && $style.active]"
					:title="st.shortcut ? `${st.label} (${st.shortcut})` : st.label"
					:data-test="`stamp-option-${st.symbol}`"
					@click="emit('select', st.symbol)">
					<svg
						width="28"
						height="28"
						viewBox="0 0 28 28"
						aria-hidden="true">
						<text
							:x="14 + st.inkOffset.ox * pickerFontSize(st.symbol, 22)"
							:y="14 + st.inkOffset.oy * pickerFontSize(st.symbol, 22)"
							:font-size="pickerFontSize(st.symbol, 22)"
							text-anchor="middle"
							dominant-baseline="alphabetic"
							fill="currentColor"
							:font-family="MUSIC_FONT_FAMILY">
							{{ st.symbol }}
						</text>
					</svg>
				</button>
			</div>
	</ViewportPopover>
</template>

<style module>
button[type='button'].toolBtn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 5px;
	width: auto;
	min-width: var(--default-clickable-area, 44px);
	min-height: var(--default-clickable-area, 44px);
	margin: 0;
	padding: 5px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.85rem;
	cursor: pointer;
	transition: background-color 0.12s, border-color 0.12s;
}

.toolBtn:hover {
	background: var(--color-background-hover);
}

.toolBtn.active {
	background: var(--color-primary-light, #e6f4fa);
	border-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element, #0082c9);
	font-weight: 600;
}

.stampPreview {
	font-size: 1.05rem;
	line-height: 1;
}

.stampPopover {
	width: max-content;
	min-width: min(340px, calc(100vw - 24px));
	padding: 8px;
}

.stampTabs {
	display: flex;
	flex-wrap: nowrap;
	align-items: stretch;
	gap: 2px;
	padding: 2px 2px 0;
	background: var(--color-background-hover);
	border-radius: 6px 6px 0 0;
	border-bottom: 2px solid var(--color-border-dark, var(--color-border));
	margin-bottom: 10px;
	overflow-x: auto;
}

.stampTab {
	flex: 0 0 auto;
	height: 36px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 3px;
	padding: 2px 10px;
	margin-bottom: -2px;
	border: 1px solid transparent;
	border-bottom: 2px solid transparent;
	border-radius: 6px 6px 0 0;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	transition: background-color 0.12s, color 0.12s, border-color 0.12s;
}

.stampTab:hover {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.stampTab.active {
	background: var(--color-main-background);
	color: var(--color-primary-element, #0082c9);
	border-color: var(--color-border-dark, var(--color-border));
	border-bottom-color: var(--color-main-background);
}

.stampGrid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(46px, 1fr));
	gap: 6px;
}

.stampCell {
	display: flex;
	align-items: center;
	justify-content: center;
	min-width: 0;
	height: 40px;
	padding: 0 4px;
	overflow: hidden;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.stampCell:hover {
	background: var(--color-background-hover);
}

.stampCell.active {
	border-color: var(--color-primary-element, #0082c9);
	background: var(--color-primary-light, #e6f4fa);
}
</style>
