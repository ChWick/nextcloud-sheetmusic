<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import ViewAgendaOutline from 'vue-material-design-icons/ViewAgendaOutline.vue'
import ViewSplitVertical from 'vue-material-design-icons/ViewSplitVertical.vue'
import type { PageCrop } from './crop-types'
import type { JoinFitInfo, JoinLayout } from './pdf-join-engine'

const props = defineProps<{
	pageA: number
	pageB: number
	totalPages: number
	pageCrops: Record<number, PageCrop>
	layout: JoinLayout
	fitInfo: JoinFitInfo
	isSizeAligned: boolean
	scaledDimA: { width: number; height: number }
	scaledDimB: { width: number; height: number }
	scaleA: number
	scaleB: number
	fitToStandardPage: boolean
	scaleToFit: boolean
}>()

const emit = defineEmits<{
	'update:pageA': [value: number]
	'update:pageB': [value: number]
	'update:layout': [value: JoinLayout]
	'update:scaleA': [value: number]
	'update:scaleB': [value: number]
	'update:fitToStandardPage': [value: boolean]
	'update:scaleToFit': [value: boolean]
	'alignSizes': []
	'resetScales': []
	'matchPageA': []
	'matchPageB': []
}>()
</script>

<template>
	<div :class="$style.controlsColumn">
		<!-- Page Selection -->
		<div :class="$style.sectionGroup">
			<label :class="$style.sectionLabel">{{ t('sheetmusic', 'Select Pages to Join:') }}</label>
			<div :class="$style.pageSelectors">
				<div :class="$style.selectorItem">
					<span :class="$style.selectorItemLabel">{{ t('sheetmusic', 'First Page (Top / Left):') }}</span>
					<select
						:value="pageA"
						:class="$style.select"
						@change="emit('update:pageA', Number(($event.target as HTMLSelectElement).value))">
						<option v-for="p in totalPages" :key="p" :value="p" :disabled="p === pageB">
							{{ t('sheetmusic', 'Page {p}', { p }) }}
							{{ pageCrops[p] ? `(${t('sheetmusic', 'Cropped')})` : '' }}
						</option>
					</select>
				</div>

				<div :class="$style.selectorItem">
					<span :class="$style.selectorItemLabel">{{ t('sheetmusic', 'Second Page (Bottom / Right):') }}</span>
					<select
						:value="pageB"
						:class="$style.select"
						@change="emit('update:pageB', Number(($event.target as HTMLSelectElement).value))">
						<option v-for="p in totalPages" :key="p" :value="p" :disabled="p === pageA">
							{{ t('sheetmusic', 'Page {p}', { p }) }}
							{{ pageCrops[p] ? `(${t('sheetmusic', 'Cropped')})` : '' }}
						</option>
					</select>
				</div>
			</div>
		</div>

		<!-- Layout Orientation -->
		<div :class="$style.sectionGroup">
			<label :class="$style.sectionLabel">{{ t('sheetmusic', 'Layout Orientation:') }}</label>
			<div :class="$style.layoutToggle">
				<button
					type="button"
					:class="[$style.toggleBtn, layout === 'vertical' && $style.toggleBtnActive]"
					@click="emit('update:layout', 'vertical')">
					<ViewAgendaOutline :size="18" />
					<span>{{ t('sheetmusic', 'Vertical (Stacked Top / Bottom)') }}</span>
				</button>
				<button
					type="button"
					:class="[$style.toggleBtn, layout === 'horizontal' && $style.toggleBtnActive]"
					@click="emit('update:layout', 'horizontal')">
					<ViewSplitVertical :size="18" />
					<span>{{ t('sheetmusic', 'Horizontal (Side by Side)') }}</span>
				</button>
			</div>
		</div>

		<!-- Fit Status Banner -->
		<div :class="$style.sectionGroup">
			<div v-if="fitInfo.fitsOnStandardPage" :class="$style.fitBannerSuccess">
				<CheckCircle :size="20" />
				<div>
					<strong>{{ t('sheetmusic', 'Pages fit on a single page!') }}</strong>
					<div :class="$style.fitBannerSub">
						{{ t('sheetmusic', 'Combined {dir}: {pct}% of standard page size.', {
							dir: layout === 'vertical' ? t('sheetmusic', 'height') : t('sheetmusic', 'width'),
							pct: layout === 'vertical' ? fitInfo.heightPercentage : fitInfo.widthPercentage,
						}) }}
					</div>
				</div>
			</div>
			<div v-else :class="$style.fitBannerWarning">
				<AlertCircle :size="20" />
				<div>
					<strong>{{ t('sheetmusic', 'Content exceeds standard page size ({pct}%).', {
						pct: layout === 'vertical' ? fitInfo.heightPercentage : fitInfo.widthPercentage,
					}) }}</strong>
					<div :class="$style.fitBannerSub">
						{{ scaleToFit ? t('sheetmusic', 'Content will be scaled to fit within a single standard page.') : t('sheetmusic', 'Page canvas will expand to accommodate both pages.') }}
					</div>
				</div>
			</div>
		</div>

		<!-- Sizing Options & Alignment -->
		<div :class="$style.sectionGroup">
			<div :class="$style.sectionHeaderRow">
				<label :class="$style.sectionLabel">{{ t('sheetmusic', 'Page Sizing & Alignment:') }}</label>
				<div :class="$style.alignQuickButtons">
					<button
						type="button"
						:class="[$style.quickAlignBtn, isSizeAligned && $style.quickAlignBtnActive]"
						:title="layout === 'vertical' ? t('sheetmusic', 'Scale narrower page to match wider page width') : t('sheetmusic', 'Scale shorter page to match taller page height')"
						data-test="align-sizes-btn"
						@click="emit('alignSizes')">
						{{ layout === 'vertical' ? t('sheetmusic', 'Align Widths') : t('sheetmusic', 'Align Heights') }}
					</button>
					<button
						type="button"
						:class="$style.resetScaleBtn"
						:disabled="scaleA === 1.0 && scaleB === 1.0"
						:title="t('sheetmusic', 'Reset both pages to 100%')"
						data-test="reset-sizes-btn"
						@click="emit('resetScales')">
						{{ t('sheetmusic', 'Reset 100%') }}
					</button>
				</div>
			</div>

			<!-- Alignment Status Pill -->
			<div :class="$style.alignmentStatusRow">
				<span v-if="isSizeAligned" :class="$style.statusPillSuccess" data-test="aligned-status">
					<CheckCircle :size="14" />
					{{ layout === 'vertical' ? t('sheetmusic', 'Widths aligned ({w} pt)', { w: scaledDimA.width }) : t('sheetmusic', 'Heights aligned ({h} pt)', { h: scaledDimA.height }) }}
				</span>
				<span v-else :class="$style.statusPillDiff" data-test="diff-status">
					{{ layout === 'vertical'
						? t('sheetmusic', 'Width diff: Page {pA} is {wA} pt vs Page {pB} is {wB} pt', { pA: pageA, wA: scaledDimA.width, pB: pageB, wB: scaledDimB.width })
						: t('sheetmusic', 'Height diff: Page {pA} is {hA} pt vs Page {pB} is {hB} pt', { pA: pageA, hA: scaledDimA.height, pB: pageB, hB: scaledDimB.height }) }}
				</span>
			</div>

			<!-- Page A Scale Control -->
			<div :class="$style.scaleControlRow">
				<div :class="$style.scaleControlHeader">
					<span :class="$style.scalePageName">{{ t('sheetmusic', 'Page {p} Size:', { p: pageA }) }}</span>
					<span :class="$style.scaleDimInfo">{{ scaledDimA.width }} × {{ scaledDimA.height }} pt ({{ Math.round(scaleA * 100) }}%)</span>
				</div>
				<div :class="$style.sliderRow">
					<input
						:value="scaleA"
						type="range"
						min="0.5"
						max="2.0"
						step="0.01"
						:class="$style.scaleSlider"
						data-test="scale-a-slider"
						@input="emit('update:scaleA', Number(($event.target as HTMLInputElement).value))" />
					<div :class="$style.scaleInputGroup">
						<input
							:value="Math.round(scaleA * 100)"
							type="number"
							min="50"
							max="200"
							:class="$style.scaleNumberInput"
							data-test="scale-a-input"
							@input="emit('update:scaleA', Math.max(0.5, Math.min(2.0, (Number(($event.target as HTMLInputElement).value) || 100) / 100)))" />
						<span :class="$style.percentSign">%</span>
					</div>
					<button
						type="button"
						:class="$style.matchOtherBtn"
						:title="t('sheetmusic', 'Scale Page {pA} to match Page {pB}', { pA: pageA, pB: pageB })"
						data-test="match-b-btn"
						@click="emit('matchPageB')">
						{{ t('sheetmusic', 'Match p. {p}', { p: pageB }) }}
					</button>
				</div>
			</div>

			<!-- Page B Scale Control -->
			<div :class="$style.scaleControlRow">
				<div :class="$style.scaleControlHeader">
					<span :class="$style.scalePageName">{{ t('sheetmusic', 'Page {p} Size:', { p: pageB }) }}</span>
					<span :class="$style.scaleDimInfo">{{ scaledDimB.width }} × {{ scaledDimB.height }} pt ({{ Math.round(scaleB * 100) }}%)</span>
				</div>
				<div :class="$style.sliderRow">
					<input
						:value="scaleB"
						type="range"
						min="0.5"
						max="2.0"
						step="0.01"
						:class="$style.scaleSlider"
						data-test="scale-b-slider"
						@input="emit('update:scaleB', Number(($event.target as HTMLInputElement).value))" />
					<div :class="$style.scaleInputGroup">
						<input
							:value="Math.round(scaleB * 100)"
							type="number"
							min="50"
							max="200"
							:class="$style.scaleNumberInput"
							data-test="scale-b-input"
							@input="emit('update:scaleB', Math.max(0.5, Math.min(2.0, (Number(($event.target as HTMLInputElement).value) || 100) / 100)))" />
						<span :class="$style.percentSign">%</span>
					</div>
					<button
						type="button"
						:class="$style.matchOtherBtn"
						:title="t('sheetmusic', 'Scale Page {pB} to match Page {pA}', { pA: pageA, pB: pageB })"
						data-test="match-a-btn"
						@click="emit('matchPageA')">
						{{ t('sheetmusic', 'Match p. {p}', { p: pageA }) }}
					</button>
				</div>
			</div>

			<!-- Sizing Checkboxes -->
			<div :class="$style.checkboxRow">
				<label :class="$style.checkboxLabel">
					<input
						:checked="fitToStandardPage"
						type="checkbox"
						@change="emit('update:fitToStandardPage', ($event.target as HTMLInputElement).checked)" />
					<span>{{ t('sheetmusic', 'Fit onto standard page dimensions') }}</span>
				</label>
			</div>
			<div v-if="fitToStandardPage" :class="$style.checkboxRow">
				<label :class="$style.checkboxLabel">
					<input
						:checked="scaleToFit"
						type="checkbox"
						@change="emit('update:scaleToFit', ($event.target as HTMLInputElement).checked)" />
					<span>{{ t('sheetmusic', 'Scale down content if it exceeds the page') }}</span>
				</label>
			</div>
		</div>
	</div>
</template>

<style module>
.controlsColumn {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 16px;
	min-width: 320px;
}

.sectionGroup {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.sectionLabel {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text);
}

.pageSelectors {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.selectorItem {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.selectorItemLabel {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.select {
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
}

.layoutToggle {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.toggleBtn {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: 6px;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
	cursor: pointer;
	transition: all 0.15s ease;
	text-align: left;
}

.toggleBtnActive {
	border-color: var(--color-primary-element);
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
	font-weight: 600;
}

.fitBannerSuccess {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 10px 14px;
	background-color: #ecfdf5;
	border: 1px solid #10b981;
	border-radius: 6px;
	color: #065f46;
	font-size: 13px;
}

.fitBannerWarning {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 10px 14px;
	background-color: #fffbeb;
	border: 1px solid #f59e0b;
	border-radius: 6px;
	color: #92400e;
	font-size: 13px;
}

.fitBannerSub {
	font-size: 11px;
	margin-top: 2px;
	opacity: 0.9;
}

.checkboxRow {
	display: flex;
	align-items: center;
}

.checkboxLabel {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 13px;
	cursor: pointer;
}

.sectionHeaderRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.alignQuickButtons {
	display: flex;
	align-items: center;
	gap: 6px;
}

.quickAlignBtn {
	font-size: 11px;
	padding: 3px 8px;
	border: 1px solid var(--color-primary-element, #0082c9);
	border-radius: 4px;
	background-color: var(--color-main-background);
	color: var(--color-primary-element, #0082c9);
	cursor: pointer;
	font-weight: 600;
	transition: all 0.15s ease;
}

.quickAlignBtn:hover {
	background-color: var(--color-background-hover);
}

.quickAlignBtnActive {
	background-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #ffffff);
}

.resetScaleBtn {
	font-size: 11px;
	padding: 3px 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background-color: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	transition: all 0.15s ease;
}

.resetScaleBtn:hover:not(:disabled) {
	background-color: var(--color-background-hover);
}

.resetScaleBtn:disabled {
	opacity: 0.5;
	cursor: default;
}

.alignmentStatusRow {
	margin: 2px 0 4px;
}

.statusPillSuccess {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 11px;
	color: #047857;
	font-weight: 500;
}

.statusPillDiff {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.scaleControlRow {
	display: flex;
	flex-direction: column;
	gap: 4px;
	background-color: var(--color-background-hover);
	padding: 6px 10px;
	border-radius: 6px;
	margin-bottom: 4px;
}

.scaleControlHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
	font-size: 12px;
}

.scalePageName {
	font-weight: 600;
	color: var(--color-main-text);
}

.scaleDimInfo {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.sliderRow {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
}

.scaleSlider {
	flex: 1;
	cursor: pointer;
	height: 5px;
	accent-color: var(--color-primary-element, #0082c9);
}

.scaleInputGroup {
	display: flex;
	align-items: center;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 4px;
	padding: 2px 6px;
	width: 62px;
}

.scaleNumberInput {
	width: 34px;
	border: none;
	background: transparent;
	font-size: 12px;
	text-align: right;
	color: var(--color-main-text);
	outline: none;
}

.percentSign {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	margin-left: 2px;
}

.matchOtherBtn {
	font-size: 11px;
	padding: 3px 6px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	white-space: nowrap;
}

.matchOtherBtn:hover {
	background-color: var(--color-background-hover);
}
</style>
