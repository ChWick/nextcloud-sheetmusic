<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import SongPageOrderCard from './SongPageOrderCard.vue'

withDefaults(
	defineProps<{
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
	zoom: [pageNum: number]
}>()
</script>

<template>
	<div :class="$style.gridContainer" role="region" aria-label="Page previews">
		<div
			:class="$style.grid"
			:style="{
				gridTemplateColumns: `repeat(auto-fill, minmax(${cardWidth}px, 1fr))`,
			}">
			<SongPageOrderCard
				v-for="p in totalPages"
				:key="`page-card-${p}`"
				:page-num="p"
				:thumbnail-url="pageThumbnails[p]"
				:occurrence-count="pageOccurrences[p] ?? 0"
				:card-width="cardWidth"
				:disabled="disabled"
				@append="emit('append', $event)"
				@zoom="emit('zoom', $event)" />
		</div>
	</div>
</template>

<style module>
.gridContainer {
	width: 100%;
	flex: 1 1 auto;
	min-height: 220px;
	overflow-y: auto;
	overflow-x: hidden;
	padding: 12px;
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
</style>
