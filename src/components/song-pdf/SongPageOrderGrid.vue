<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import FormatListNumbered from 'vue-material-design-icons/FormatListNumbered.vue'
import FileDocumentMultipleOutline from 'vue-material-design-icons/FileDocumentMultipleOutline.vue'
import ViewAgendaOutline from 'vue-material-design-icons/ViewAgendaOutline.vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import SongPageOrderCard from './SongPageOrderCard.vue'
import { usePageOrderDragDrop } from './usePageOrderDragDrop'

const props = withDefaults(
	defineProps<{
		pageSequence: number[]
		totalPages: number
		pageThumbnails?: Record<number, string>
		pageOccurrences?: Record<number, number>
		cardWidth?: number
		disabled?: boolean
	}>(),
	{
		pageThumbnails: () => ({}),
		pageOccurrences: () => ({}),
		cardWidth: 240,
		disabled: false,
	},
)

const emit = defineEmits<{
	append: [pageNum: number]
	'insert-at': [pageNum: number, targetIndex: number]
	'reorder-sequence': [fromIndex: number, toIndex: number]
	'remove-sequence-index': [index: number]
	zoom: [pageNum: number]
}>()

// Default to 'both' so both original PDF pages and resulting order views are visible simultaneously
const viewMode = ref<'both' | 'sequence' | 'all-pages'>('both')

const isBothMode = computed(() => viewMode.value === 'both')
const isSequenceMode = computed(() => viewMode.value === 'sequence')
const isSourceMode = computed(() => viewMode.value === 'all-pages')

const showSourceSection = computed(() => isBothMode.value || isSourceMode.value)
const showSequenceSection = computed(() => isBothMode.value || isSequenceMode.value)

// Drag and drop composable
const {
	draggedItem,
	dropTargetIndex,
	dropIndicatorSide,
	isOverEndZone,
	handleSourceDragStart,
	handleSequenceDragStart,
	handleCardDragOver,
	handleCardDragLeave,
	handleCardDrop,
	handleEndZoneDragOver,
	handleEndZoneDragLeave,
	handleEndZoneDrop,
	handleDragEnd,
} = usePageOrderDragDrop()

function onDropCard(event: DragEvent, index: number) {
	handleCardDrop(event, index, {
		onInsert: (pageNum, targetIdx) => emit('insert-at', pageNum, targetIdx),
		onReorder: (fromIdx, toIdx) => emit('reorder-sequence', fromIdx, toIdx),
	})
}

function onDropEndZone(event: DragEvent) {
	handleEndZoneDrop(event, props.pageSequence.length, {
		onInsert: (pageNum, targetIdx) => emit('insert-at', pageNum, targetIdx),
		onReorder: (fromIdx, toIdx) => emit('reorder-sequence', fromIdx, toIdx),
	})
}
</script>

<template>
	<div :class="$style.gridRoot" role="region" aria-label="Page previews" data-test="page-order-grid">
		<!-- Toolbar with View Mode switcher -->
		<div :class="$style.gridToolbar">
			<div :class="$style.modeSelector" role="tablist">
				<button
					type="button"
					role="tab"
					:aria-selected="isBothMode"
					:class="[$style.modeBtn, isBothMode && $style.modeBtnActive]"
					data-test="mode-both-btn"
					@click="viewMode = 'both'">
					<ViewAgendaOutline :size="16" />
					<span>{{ t('sheetmusic', 'Both Views (Stacked)') }}</span>
				</button>
				<button
					type="button"
					role="tab"
					:aria-selected="isSequenceMode"
					:class="[$style.modeBtn, isSequenceMode && $style.modeBtnActive]"
					data-test="mode-sequence-btn"
					@click="viewMode = 'sequence'">
					<FormatListNumbered :size="16" />
					<span>{{ t('sheetmusic', 'Resulting Order ({count})', { count: pageSequence.length }) }}</span>
				</button>
				<button
					type="button"
					role="tab"
					:aria-selected="isSourceMode"
					:class="[$style.modeBtn, isSourceMode && $style.modeBtnActive]"
					data-test="mode-source-btn"
					@click="viewMode = 'all-pages'">
					<FileDocumentMultipleOutline :size="16" />
					<span>{{ t('sheetmusic', 'All Source Pages ({count})', { count: totalPages }) }}</span>
				</button>
			</div>

			<div :class="$style.gridHint">
				<span v-if="isBothMode">
					{{ t('sheetmusic', 'Drag pages from original PDF down into resulting order, or drag cards below to reorder.') }}
				</span>
				<span v-else-if="isSequenceMode">
					{{ t('sheetmusic', 'Showing pages in resulting order. Drag cards to reorder or click magnifying glass to inspect.') }}
				</span>
				<span v-else>
					{{ t('sheetmusic', 'Showing all source pages in the document with usage counter. Click "+ Add to Order" to append.') }}
				</span>
			</div>
		</div>

		<!-- Container for stacked or individual views -->
		<div :class="$style.viewsContainer">
			<!-- View A: Original PDF Pages with usage counters -->
			<section
				v-if="showSourceSection"
				:class="$style.section"
				data-test="source-pages-section"
				aria-label="Original PDF Pages">
				<div :class="$style.sectionHeader">
					<div :class="$style.sectionTitle">
						<FileDocumentMultipleOutline :size="18" :class="$style.sectionIcon" />
						<span :class="$style.titleText">
							{{ t('sheetmusic', 'Original PDF Pages ({count})', { count: totalPages }) }}
						</span>
					</div>
					<span :class="$style.sectionDescription">
						{{ t('sheetmusic', 'Showing each original page with usage counter. Drag down or click "+ Add to Order".') }}
					</span>
				</div>

				<div
					:class="$style.shelfGrid"
					:style="{
						gridTemplateColumns: `repeat(auto-fill, minmax(${cardWidth}px, 1fr))`,
					}"
					data-test="source-pages-grid">
					<SongPageOrderCard
						v-for="p in totalPages"
						:key="`source-page-${p}`"
						:page-num="p"
						:thumbnail-url="pageThumbnails[p]"
						:occurrence-count="pageOccurrences[p] ?? 0"
						:card-width="cardWidth"
						:disabled="disabled"
						:draggable="!disabled"
						:is-dragging="draggedItem?.type === 'source-page' && draggedItem?.pageNum === p"
						@dragstart="handleSourceDragStart($event, p)"
						@dragend="handleDragEnd"
						@append="emit('append', $event)"
						@zoom="emit('zoom', $event)" />
				</div>
			</section>

			<!-- Directional connector when both views are visible -->
			<div v-if="isBothMode" :class="$style.flowDivider" aria-hidden="true">
				<div :class="$style.flowLine" />
				<span :class="$style.flowBadge">
					<ArrowDown :size="14" />
					{{ t('sheetmusic', 'Drag pages down to build order') }}
				</span>
				<div :class="$style.flowLine" />
			</div>

			<!-- View B: Resulting Order with repeated / reordered pages -->
			<section
				v-if="showSequenceSection"
				:class="$style.section"
				data-test="resulting-order-section"
				aria-label="Resulting Page Order">
				<div :class="$style.sectionHeader">
					<div :class="$style.sectionTitle">
						<FormatListNumbered :size="18" :class="$style.sectionIcon" />
						<span :class="$style.titleText">
							{{ t('sheetmusic', 'Resulting Order ({count})', { count: pageSequence.length }) }}
						</span>
					</div>
					<span :class="$style.sectionDescription">
						{{ t('sheetmusic', 'Pages will display in this sequence. Drag cards left/right to reorder.') }}
					</span>
				</div>

				<!-- Sequence cards grid -->
				<div
					v-if="pageSequence.length > 0"
					:class="$style.shelfGrid"
					:style="{
						gridTemplateColumns: `repeat(auto-fill, minmax(${cardWidth}px, 1fr))`,
					}"
					data-test="sequence-grid">
					<SongPageOrderCard
						v-for="(p, seqIdx) in pageSequence"
						:key="`seq-${seqIdx}-${p}`"
						:page-num="p"
						:sequence-index="seqIdx"
						:sequence-total="pageSequence.length"
						:thumbnail-url="pageThumbnails[p]"
						:occurrence-count="pageOccurrences[p] ?? 0"
						:card-width="cardWidth"
						:disabled="disabled"
						:show-remove="true"
						:draggable="!disabled"
						:is-dragging="draggedItem?.type === 'sequence-card' && draggedItem?.fromIndex === seqIdx"
						:drop-indicator="dropTargetIndex === seqIdx ? dropIndicatorSide : null"
						@dragstart="handleSequenceDragStart($event, seqIdx, p)"
						@dragend="handleDragEnd"
						@dragover="handleCardDragOver($event, seqIdx)"
						@dragleave="handleCardDragLeave($event, seqIdx)"
						@drop="onDropCard($event, seqIdx)"
						@append="emit('append', $event)"
						@remove="emit('remove-sequence-index', $event)"
						@zoom="emit('zoom', $event)" />

					<!-- End drop slot to drop pages at the end of the sequence -->
					<div
						:class="[
							$style.endDropSlot,
							isOverEndZone && $style.endDropSlotActive,
							draggedItem && $style.endDropSlotVisible,
						]"
						:style="{ minWidth: `${Math.min(cardWidth, 140)}px`, maxWidth: `${cardWidth}px` }"
						:title="t('sheetmusic', 'Drop here to add to end of order')"
						data-test="sequence-end-drop-slot"
						@dragover="handleEndZoneDragOver"
						@dragleave="handleEndZoneDragLeave"
						@drop="onDropEndZone">
						<Plus :size="24" :class="$style.endDropIcon" />
						<span :class="$style.endDropText">{{ t('sheetmusic', 'Drop here for end') }}</span>
					</div>
				</div>

				<!-- Empty state if no pages in sequence -->
				<div
					v-else
					:class="[
						$style.emptySequenceZone,
						isOverEndZone && $style.emptySequenceZoneActive,
					]"
					data-test="empty-sequence"
					@dragover="handleEndZoneDragOver"
					@dragleave="handleEndZoneDragLeave"
					@drop="onDropEndZone">
					<p :class="$style.emptySequenceText">
						{{ t('sheetmusic', 'No pages currently in page order. Drag pages from the original PDF above, or click "+ Add to Order".') }}
					</p>
				</div>
			</section>
		</div>
	</div>
</template>

<style module>
.gridRoot {
	display: flex;
	flex-direction: column;
	width: 100%;
	flex: 1 1 auto;
	min-height: 260px;
	gap: 10px;
}

.gridToolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
	padding-bottom: 2px;
	border-bottom: 1px solid var(--color-border);
}

.modeSelector {
	display: inline-flex;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-pill, 20px);
	padding: 3px;
	gap: 2px;
}

.modeBtn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	border: none;
	background: transparent;
	color: var(--color-text-maxcontrast);
	padding: 5px 12px;
	border-radius: var(--border-radius-pill, 18px);
	font-size: 0.8rem;
	font-weight: 600;
	cursor: pointer;
	transition: background-color 0.15s ease, color 0.15s ease;
}

.modeBtn:hover:not(.modeBtnActive) {
	color: var(--color-main-text);
}

.modeBtnActive {
	background-color: var(--color-main-background, #fff);
	color: var(--color-primary-element);
	box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
}

.gridHint {
	font-size: 0.78rem;
	color: var(--color-text-maxcontrast);
}

.viewsContainer {
	display: flex;
	flex-direction: column;
	gap: 14px;
	width: 100%;
}

.section {
	display: flex;
	flex-direction: column;
	gap: 8px;
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 10px 12px;
}

.sectionHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	flex-wrap: wrap;
}

.sectionTitle {
	display: flex;
	align-items: center;
	gap: 6px;
}

.sectionIcon {
	color: var(--color-primary-element);
}

.titleText {
	font-weight: 700;
	font-size: 0.88rem;
	color: var(--color-main-text);
}

.sectionDescription {
	font-size: 0.78rem;
	color: var(--color-text-maxcontrast);
}

.shelfGrid {
	display: grid;
	gap: 12px;
	max-height: 340px;
	overflow-y: auto;
	padding: 6px 4px 10px 4px;
}

.flowDivider {
	display: flex;
	align-items: center;
	gap: 10px;
	margin: -4px 0;
	user-select: none;
}

.flowLine {
	flex: 1;
	height: 1px;
	background-color: var(--color-border);
}

.flowBadge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 10px;
	background-color: var(--color-background-hover);
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius-pill, 12px);
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.endDropSlot {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	aspect-ratio: 0.707;
	border: 2px dashed var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: rgba(0, 0, 0, 0.02);
	color: var(--color-text-maxcontrast);
	gap: 6px;
	transition: border-color 0.15s ease, background-color 0.15s ease, color 0.15s ease;
	cursor: pointer;
}

.endDropSlotVisible {
	border-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.4));
}

.endDropSlotActive {
	border-color: var(--color-primary-element, #0082c9);
	background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.15));
	color: var(--color-primary-element, #0082c9);
	transform: scale(1.02);
}

.endDropIcon {
	opacity: 0.7;
}

.endDropText {
	font-size: 0.78rem;
	font-weight: 600;
	text-align: center;
	padding: 0 8px;
}

.emptySequenceZone {
	display: flex;
	align-items: center;
	justify-content: center;
	min-height: 140px;
	border: 2px dashed var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: rgba(0, 0, 0, 0.02);
	padding: 24px;
	text-align: center;
	transition: border-color 0.15s ease, background-color 0.15s ease;
}

.emptySequenceZoneActive {
	border-color: var(--color-primary-element, #0082c9);
	background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.15));
}

.emptySequenceText {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
