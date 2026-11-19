<script setup lang="ts">
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ViewportPopover from '../ViewportPopover.vue'
import SortAscendingIcon from 'vue-material-design-icons/SortAscending.vue'
import SortDescendingIcon from 'vue-material-design-icons/SortDescending.vue'
import TableIcon from 'vue-material-design-icons/Table.vue'
import TuneIcon from 'vue-material-design-icons/Tune.vue'
import ViewGridIcon from 'vue-material-design-icons/ViewGrid.vue'
import ViewListIcon from 'vue-material-design-icons/ViewList.vue'
import type { SongColumn } from '../../types/song'
import type { SortKey, SortOrder } from '../../utils/song-formatter'
import { getSongColumnOptions, getSongSortOptions, toggleVisibleColumn, type ViewMode } from './song-view-columns'

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

const viewOptions: { value: ViewMode; label: string; icon: typeof TableIcon }[] = [
	{ value: 'table', label: t('sheetmusic', 'Table'), icon: TableIcon },
	{ value: 'cards', label: t('sheetmusic', 'Cards'), icon: ViewGridIcon },
	{ value: 'list', label: t('sheetmusic', 'List'), icon: ViewListIcon },
]

const sortOptions = getSongSortOptions()
const columnOptions = getSongColumnOptions()

function toggleColumn(key: SongColumn) {
	emit('update:visibleColumns', toggleVisibleColumn(props.visibleColumns, key, columnOptions))
}
</script>

<template>
	<ViewportPopover
		v-model:open="isOpen"
		pane-test-id="song-list-display-popover"
		:pane-class="$style.popover">
		<template #trigger="{ toggle }">
			<NcButton
				:aria-expanded="isOpen"
				:title="t('sheetmusic', 'View options')"
				:aria-label="t('sheetmusic', 'View options')"
				data-test="song-list-display-trigger"
				@click="toggle">
				<template #icon>
					<TuneIcon :size="20" />
				</template>
			</NcButton>
		</template>
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
	</ViewportPopover>
</template>

<style module>
.popover {
	width: min(340px, calc(100vw - 24px));
	max-height: 75vh;
	overflow-y: auto;
	padding: 12px;
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
