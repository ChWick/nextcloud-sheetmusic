<script setup lang="ts">
import type { EntityType, FilterEntity } from '../../types/song'
import type { EntityColumnDef } from './song-view-columns'

defineProps<{
	def: EntityColumnDef
	items: FilterEntity[]
	isFiltered?: (entityType: EntityType, id: number) => boolean
	showEmptyDash?: boolean
}>()

const emit = defineEmits<{
	'filter-entity': [entityType: EntityType, entity: FilterEntity]
}>()
</script>

<template>
	<template v-if="items.length > 0">
		<span v-for="(item, idx) in items" :key="item.id">
			<button
				type="button"
				:class="[$style.filterChip, isFiltered?.(def.entityType, item.id) && $style.activeChip]"
				:title="def.getFilterTitle(def.getItemLabel(item))"
				@click.stop="emit('filter-entity', def.entityType, item)">
				{{ def.getItemLabel(item) }}
			</button>
			<template v-if="idx < items.length - 1">, </template>
		</span>
	</template>
	<span v-else-if="showEmptyDash" :class="$style.emptyText">—</span>
</template>

<style module>
.filterChip {
	appearance: none;
	-webkit-appearance: none;
	background: transparent !important;
	border: none !important;
	border-radius: 0 !important;
	box-shadow: none !important;
	outline: none !important;
	padding: 0 !important;
	margin: 0 !important;
	font: inherit;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	text-align: left;
	display: inline;
	text-decoration: none;
	transition: color 0.15s ease;
}

.filterChip:hover,
.filterChip:focus,
.filterChip:active {
	background: transparent !important;
	border: none !important;
	box-shadow: none !important;
	color: var(--color-primary-element) !important;
	text-decoration: underline;
}

.activeChip {
	color: var(--color-primary-element) !important;
	font-weight: 600;
	text-decoration: underline;
}

.activeChip:hover,
.activeChip:focus,
.activeChip:active {
	background: transparent !important;
	border: none !important;
	box-shadow: none !important;
	color: var(--color-primary-element-hover, var(--color-primary-element)) !important;
}

.emptyText {
	color: var(--color-text-maxcontrast);
}
</style>
