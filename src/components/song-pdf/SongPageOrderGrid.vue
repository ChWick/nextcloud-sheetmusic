<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import FormatListNumbered from 'vue-material-design-icons/FormatListNumbered.vue'
import FileDocumentMultipleOutline from 'vue-material-design-icons/FileDocumentMultipleOutline.vue'
import SongPageOrderCard from './SongPageOrderCard.vue'

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
	'remove-sequence-index': [index: number]
	zoom: [pageNum: number]
}>()

// Default to 'sequence' mode so user sees resulting order (e.g. 1, 2, 1 -> page 1 twice)
const viewMode = ref<'sequence' | 'all-pages'>('sequence')

const isSequenceMode = computed(() => viewMode.value === 'sequence')
</script>

<template>
	<div :class="$style.gridRoot" role="region" aria-label="Page previews" data-test="page-order-grid">
		<!-- Sub-header with View Mode switcher -->
		<div :class="$style.gridToolbar">
			<div :class="$style.modeSelector" role="tablist">
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
					:aria-selected="!isSequenceMode"
					:class="[$style.modeBtn, !isSequenceMode && $style.modeBtnActive]"
					data-test="mode-source-btn"
					@click="viewMode = 'all-pages'">
					<FileDocumentMultipleOutline :size="16" />
					<span>{{ t('sheetmusic', 'All Source Pages ({count})', { count: totalPages }) }}</span>
				</button>
			</div>

			<div :class="$style.gridHint">
				<span v-if="isSequenceMode">
					{{ t('sheetmusic', 'Showing pages in resulting order. Click magnifying glass to inspect high resolution.') }}
				</span>
				<span v-else>
					{{ t('sheetmusic', 'Showing all source pages in the document. Click "+ Add to Order" to append.') }}
				</span>
			</div>
		</div>

		<!-- Grid of Cards -->
		<div :class="$style.gridContainer">
			<!-- Sequence Mode: renders resulting order (e.g. 1, 2, 1 renders page 1 twice) -->
			<div
				v-if="isSequenceMode && pageSequence.length > 0"
				:class="$style.grid"
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
					@append="emit('append', $event)"
					@remove="emit('remove-sequence-index', $event)"
					@zoom="emit('zoom', $event)" />
			</div>

			<!-- Sequence Mode: empty state -->
			<div
				v-else-if="isSequenceMode && pageSequence.length === 0"
				:class="$style.emptySequence"
				data-test="empty-sequence">
				<p>{{ t('sheetmusic', 'No pages currently in page order sequence.') }}</p>
				<button
					type="button"
					:class="$style.switchModeBtn"
					@click="viewMode = 'all-pages'">
					{{ t('sheetmusic', 'Browse source pages to add') }}
				</button>
			</div>

			<!-- All Pages Mode: renders all document pages 1..totalPages once -->
			<div
				v-else
				:class="$style.grid"
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
					@append="emit('append', $event)"
					@zoom="emit('zoom', $event)" />
			</div>
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
	gap: 8px;
}

.gridToolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: 12px;
	padding: 4px 2px;
}

.modeSelector {
	display: flex;
	align-items: center;
	gap: 6px;
	background: var(--color-background-hover);
	padding: 4px;
	border-radius: var(--border-radius-pill, 20px);
	border: 1px solid var(--color-border);
}

.modeBtn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 5px 12px;
	border: none;
	border-radius: var(--border-radius-pill, 16px);
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.82rem;
	font-weight: 600;
	cursor: pointer;
	transition: background-color 0.15s ease, color 0.15s ease;
}

.modeBtn:hover {
	background: rgba(0, 0, 0, 0.05);
}

.modeBtnActive {
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
}

.modeBtnActive:hover {
	background: var(--color-primary-element-hover, #0070ad);
}

.gridHint {
	font-size: 0.82rem;
	color: var(--color-text-maxcontrast, #777);
}

.gridContainer {
	width: 100%;
	flex: 1 1 auto;
	min-height: 220px;
	overflow-y: auto;
	overflow-x: hidden;
	padding: 14px;
	box-sizing: border-box;
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.grid {
	display: grid;
	gap: 16px;
	justify-content: center;
	align-content: start;
	width: 100%;
}

.emptySequence {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 48px 16px;
	gap: 12px;
	color: var(--color-text-maxcontrast);
	font-size: 0.95rem;
}

.switchModeBtn {
	padding: 8px 16px;
	border-radius: var(--border-radius-pill, 16px);
	border: 1px solid var(--color-primary-element);
	background: #fff;
	color: var(--color-primary-element);
	font-weight: 600;
	cursor: pointer;
}

.switchModeBtn:hover {
	background: var(--color-primary-element);
	color: #fff;
}
</style>
