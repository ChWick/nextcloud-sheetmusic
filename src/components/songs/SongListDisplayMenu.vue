<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import SortAscendingIcon from 'vue-material-design-icons/SortAscending.vue'
import SortDescendingIcon from 'vue-material-design-icons/SortDescending.vue'
import TableIcon from 'vue-material-design-icons/Table.vue'
import TuneIcon from 'vue-material-design-icons/Tune.vue'
import ViewGridIcon from 'vue-material-design-icons/ViewGrid.vue'
import ViewListIcon from 'vue-material-design-icons/ViewList.vue'
import type { SongColumn } from '../../types/song'
import type { SortKey, SortOrder } from '../../utils/song-formatter'
import type { ViewMode } from './SongViewToggle.vue'
import { getSongColumnOptions, getSongSortOptions } from './song-view-columns'

const props = defineProps<{
	viewMode: ViewMode
	sortKey: SortKey
	sortOrder: SortOrder
	visibleColumns: SongColumn[]
}>()

const emit = defineEmits<{
	'update:viewMode': [mode: ViewMode]
	'update:sortKey': [key: SortKey]
	'update:sortOrder': [order: SortOrder]
	'update:visibleColumns': [columns: SongColumn[]]
}>()

const isOpen = ref(false)
const rootRef = ref<HTMLElement | null>(null)

const viewOptions: { value: ViewMode; label: string; icon: typeof TableIcon }[] = [
	{ value: 'table', label: t('sheetmusic', 'Table'), icon: TableIcon },
	{ value: 'cards', label: t('sheetmusic', 'Cards'), icon: ViewGridIcon },
	{ value: 'list', label: t('sheetmusic', 'List'), icon: ViewListIcon },
]

const sortOptions = getSongSortOptions()
const columnOptions = getSongColumnOptions()

function toggleColumn(key: SongColumn) {
	const current = new Set(props.visibleColumns)
	if (current.has(key)) {
		if (current.size > 1) {
			current.delete(key)
		}
	} else {
		current.add(key)
	}
	emit('update:visibleColumns', columnOptions.map(c => c.key).filter(k => current.has(k)))
}

function handleDocumentClick(event: MouseEvent) {
	if (!isOpen.value || !rootRef.value) return
	if (!rootRef.value.contains(event.target as Node)) {
		isOpen.value = false
	}
}

onMounted(() => {
	document.addEventListener('click', handleDocumentClick)
})

onUnmounted(() => {
	document.removeEventListener('click', handleDocumentClick)
})
</script>

<template>
	<div ref="rootRef" :class="$style.wrapper">
		<NcButton
			:aria-expanded="isOpen"
			:title="t('sheetmusic', 'View options')"
			:aria-label="t('sheetmusic', 'View options')"
			data-test="song-list-display-trigger"
			@click="isOpen = !isOpen">
			<template #icon>
				<TuneIcon :size="20" />
			</template>
		</NcButton>

		<div v-if="isOpen" :class="$style.popover" data-test="song-list-display-popover">
			<div :class="$style.section">
				<div :class="$style.sectionLabel">{{ t('sheetmusic', 'Layout') }}</div>
				<div :class="$style.viewRow">
					<NcButton
						v-for="opt in viewOptions"
						:key="opt.value"
						size="small"
						:variant="viewMode === opt.value ? 'primary' : 'secondary'"
						@click="emit('update:viewMode', opt.value)">
						<template #icon>
							<component :is="opt.icon" :size="16" />
						</template>
						{{ opt.label }}
					</NcButton>
				</div>
			</div>

			<div :class="$style.section">
				<div :class="$style.sectionLabel">{{ t('sheetmusic', 'Sort') }}</div>
				<div :class="$style.sortRow">
					<select
						:value="sortKey"
						:class="$style.sortSelect"
						:aria-label="t('sheetmusic', 'Sort by')"
						@change="emit('update:sortKey', ($event.target as HTMLSelectElement).value as SortKey)">
						<option v-for="opt in sortOptions" :key="opt.value" :value="opt.value">
							{{ opt.label }}
						</option>
					</select>
					<NcButton
						size="small"
						variant="secondary"
						:title="sortOrder === 'asc' ? t('sheetmusic', 'Ascending') : t('sheetmusic', 'Descending')"
						:aria-label="sortOrder === 'asc' ? t('sheetmusic', 'Ascending') : t('sheetmusic', 'Descending')"
						@click="emit('update:sortOrder', sortOrder === 'asc' ? 'desc' : 'asc')">
						<template #icon>
							<component :is="sortOrder === 'asc' ? SortAscendingIcon : SortDescendingIcon" :size="18" />
						</template>
					</NcButton>
				</div>
			</div>

			<div :class="$style.section">
				<div :class="$style.sectionLabel">{{ t('sheetmusic', 'Columns') }}</div>
				<div :class="$style.columnsGrid">
					<label
						v-for="col in columnOptions"
						:key="col.key"
						:class="$style.columnOption">
						<input
							type="checkbox"
							:checked="visibleColumns.includes(col.key)"
							@change="toggleColumn(col.key)">
						<span>{{ col.label }}</span>
					</label>
				</div>
			</div>
		</div>
	</div>
</template>

<style module>
.wrapper {
	position: relative;
	display: inline-flex;
	align-items: center;
}

.popover {
	position: absolute;
	top: calc(100% + 6px);
	right: 0;
	z-index: 200;
	width: 340px;
	max-width: 90vw;
	max-height: 75vh;
	overflow-y: auto;
	padding: 12px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	box-shadow: 0 4px 16px var(--color-box-shadow, rgba(0, 0, 0, 0.16));
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.section {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.sectionLabel {
	font-size: 0.78rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.viewRow {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}

.sortRow {
	display: flex;
	align-items: center;
	gap: 6px;
}

.sortSelect {
	flex: 1;
	height: 34px;
	padding: 0 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.875rem;
}

.columnsGrid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 6px 10px;
}

.columnOption {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 0.85rem;
	cursor: pointer;
	user-select: none;
}
</style>
