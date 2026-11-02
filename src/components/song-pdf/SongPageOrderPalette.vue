<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'

withDefaults(
	defineProps<{
		totalPages?: number
		disabled?: boolean
	}>(),
	{
		totalPages: 1,
		disabled: false,
	},
)

const emit = defineEmits<{
	append: [pageNum: number]
}>()
</script>

<template>
	<div v-if="totalPages > 1" :class="$style.paletteSection">
		<span :class="$style.sectionLabel">{{ t('sheetmusic', 'Add Page to Sequence:') }}</span>
		<div :class="$style.paletteGrid">
			<button
				v-for="p in totalPages"
				:key="`palette-page-${p}`"
				type="button"
				:class="$style.paletteChip"
				:disabled="disabled"
				:title="t('sheetmusic', 'Append Page {page} to order', { page: p })"
				:aria-label="t('sheetmusic', 'Append Page {page} to order', { page: p })"
				@click="emit('append', p)">
				+ {{ t('sheetmusic', 'Page {p}', { p }) }}
			</button>
		</div>
	</div>
</template>

<style module>
.paletteSection {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.sectionLabel {
	font-weight: 600;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.paletteGrid {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.paletteChip {
	background: var(--color-background-hover);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 16px);
	padding: 4px 10px;
	font-size: 0.8rem;
	cursor: pointer;
	transition: background-color 0.15s ease, border-color 0.15s ease;
}

.paletteChip:hover:not(:disabled) {
	background-color: var(--color-primary-light);
	border-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.paletteChip:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}
</style>
