<script setup lang="ts">
import type { StampCategory } from '../../types/annotation'
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import MusicNote from 'vue-material-design-icons/MusicNote.vue'
import { useViewportPopoverClamp } from '../../composables/useViewportPopoverClamp'
import {
	getMusicStampFontFamily,
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
const wrapRef = ref<HTMLElement | null>(null)
const popoverRef = ref<HTMLElement | null>(null)
const { popoverPositionStyle } = useViewportPopoverClamp(
	wrapRef,
	popoverRef,
	() => props.open,
)

const CATEGORY_EXAMPLE_SYMBOLS: Record<StampCategory, [string, string]> = {
	dynamic: ['𝆑', '𝆏'],
	accidental: ['♯', '♭'],
	ornament: ['𝄐', '𝆖'],
	articulation: ['𝅻', '𝅼'],
	navigation: ['𝄋', '𝄌'],
	fingering: ['1', '2'],
	rhythm: ['♩', '𝄽'],
}

function getStampDef(sym: string) {
	return MUSIC_STAMPS.find((s) => s.symbol === sym)
}

const filteredStamps = computed(() =>
	MUSIC_STAMPS.filter((s) => s.category === activeStampCategory.value),
)
</script>

<template>
	<div ref="wrapRef" :class="$style.popoverWrap">
		<button
			type="button"
			:class="[$style.toolBtn, props.active && $style.active]"
			:title="t('sheetmusic', 'Music Symbols & Fingerings (M, 0–5, F, B, N, #)')"
			:aria-label="t('sheetmusic', 'Music Symbols & Fingerings (M, 0–5, F, B, N, #)')"
			data-test="tool-stamp"
			@click="emit('toggle')">
			<MusicNote :size="18" />
			<span :class="$style.stampPreview" :style="{ fontFamily: getMusicStampFontFamily(props.stampSymbol) }">
				{{ props.stampSymbol }}
			</span>
		</button>

		<div
			v-if="props.open"
			ref="popoverRef"
			:class="$style.stampPopover"
			:style="popoverPositionStyle"
			data-test="stamp-picker">
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
							v-if="getStampDef(sym)?.inkOffset"
							width="20"
							height="24"
							viewBox="0 0 20 24"
							aria-hidden="true">
							<text
								:x="10 + getStampDef(sym)!.inkOffset!.ox * 18"
								:y="12 + getStampDef(sym)!.inkOffset!.oy * 18"
								font-size="18"
								text-anchor="middle"
								dominant-baseline="alphabetic"
								fill="currentColor"
								:font-family="getMusicStampFontFamily(sym, getStampDef(sym)?.fontKind)">
								{{ sym }}
							</text>
						</svg>
						<span
							v-else
							:class="[$style.tabGlyph, getStampDef(sym)?.fontKind === 'fingering' && $style.fingeringGlyph]"
							:style="{ fontFamily: getMusicStampFontFamily(sym, getStampDef(sym)?.fontKind) }">
							{{ sym }}
						</span>
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
						v-if="st.inkOffset"
						width="28"
						height="28"
						viewBox="0 0 28 28"
						aria-hidden="true">
						<text
							:x="14 + st.inkOffset.ox * 20"
							:y="14 + st.inkOffset.oy * 20"
							font-size="20"
							text-anchor="middle"
							dominant-baseline="alphabetic"
							fill="currentColor"
							:font-family="getMusicStampFontFamily(st.symbol, st.fontKind)">
							{{ st.symbol }}
						</text>
					</svg>
					<span
						v-else
						:class="[$style.stampGlyph, st.fontKind === 'fingering' && $style.fingeringGlyph]"
						:style="{ fontFamily: getMusicStampFontFamily(st.symbol, st.fontKind) }">
						{{ st.symbol }}
					</span>
				</button>
			</div>
		</div>
	</div>
</template>

<style module>
.popoverWrap {
	position: relative;
	display: inline-flex;
}

.toolBtn {
	display: inline-flex;
	align-items: center;
	gap: 5px;
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
	position: absolute;
	top: calc(100% + 4px);
	left: 0;
	z-index: 200;
	width: max-content;
	min-width: min(340px, calc(100vw - 24px));
	max-width: calc(100vw - 24px);
	box-sizing: border-box;
	background: var(--color-main-background);
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large, 8px);
	box-shadow: 0 8px 24px rgba(0, 0, 0, 0.22);
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

.tabGlyph {
	font-size: 18px;
	line-height: 1;
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

.stampGlyph {
	font-size: 1.45rem;
	line-height: 1;
}

.fingeringGlyph {
	font-style: italic;
	font-weight: 700;
	font-size: 18px;
}
</style>
