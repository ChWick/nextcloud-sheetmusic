<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'

withDefaults(
	defineProps<{
		pages: number[]
		disabled?: boolean
	}>(),
	{
		pages: () => [],
		disabled: false,
	},
)

const emit = defineEmits<{
	remove: [index: number]
}>()
</script>

<template>
	<div v-if="pages.length > 0" :class="$style.previewSection">
		<div :class="$style.previewHeader">
			<span :class="$style.sectionLabel">{{ t('sheetmusic', 'Resulting Page Order:') }}</span>
			<span :class="$style.previewCountBadge">
				{{ t('sheetmusic', '{count} page in sequence | {count} pages in sequence', { count: pages.length }, pages.length, { escape: false }) }}
			</span>
		</div>
		<div :class="$style.pageChipsList">
			<div
				v-for="(page, idx) in pages"
				:key="`seq-${idx}-${page}`"
				:class="$style.pageChip">
				<span :class="$style.chipIndex">{{ idx + 1 }}</span>
				<span :class="$style.chipLabel">{{ t('sheetmusic', 'Page {p}', { p: page }, { escape: false }) }}</span>
				<button
					type="button"
					:class="$style.chipRemoveBtn"
					:disabled="pages.length <= 1 || disabled"
					:title="t('sheetmusic', 'Remove from sequence')"
					:aria-label="t('sheetmusic', 'Remove position {pos}', { pos: idx + 1 }, { escape: false })"
					@click="emit('remove', idx)">
					&times;
				</button>
			</div>
		</div>
	</div>
</template>

<style module>
.previewSection {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.previewHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.sectionLabel {
	font-weight: 600;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.previewCountBadge {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-hover);
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 10px);
}

.pageChipsList {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	max-height: 140px;
	overflow-y: auto;
	padding: 4px;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
}

.pageChip {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-pill, 14px);
	padding: 2px 8px 2px 4px;
	font-size: 0.8rem;
}

.chipIndex {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 18px;
	height: 18px;
	border-radius: 50%;
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: 0.7rem;
	font-weight: 700;
}

.chipLabel {
	color: var(--color-main-text);
	font-weight: 500;
}

.chipRemoveBtn {
	background: transparent;
	border: none;
	color: var(--color-text-maxcontrast);
	font-size: 1.1rem;
	line-height: 1;
	cursor: pointer;
	padding: 0 2px;
	border-radius: 50%;
	transition: color 0.15s ease;
}

.chipRemoveBtn:hover:not(:disabled) {
	color: var(--color-error);
}

.chipRemoveBtn:disabled {
	opacity: 0.3;
	cursor: not-allowed;
}
</style>
