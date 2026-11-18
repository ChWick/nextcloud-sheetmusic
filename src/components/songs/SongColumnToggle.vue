<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import ViewColumnIcon from 'vue-material-design-icons/ViewColumn.vue'
import CheckboxMarkedIcon from 'vue-material-design-icons/CheckboxMarked.vue'
import CheckboxBlankOutlineIcon from 'vue-material-design-icons/CheckboxBlankOutline.vue'
import type { SongColumn } from '../../types/song'
import { getSongColumnOptions } from './song-view-columns'

const props = defineProps<{
	visibleColumns: SongColumn[]
}>()

const emit = defineEmits<{
	'update:visibleColumns': [columns: SongColumn[]]
}>()

const columnOptions = getSongColumnOptions()

function isVisible(key: SongColumn): boolean {
	return props.visibleColumns.includes(key)
}

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
</script>

<template>
	<div :class="$style.toggleWrapper">
		<NcActions
			:aria-label="t('sheetmusic', 'Select visible columns')"
			:title="t('sheetmusic', 'Select visible columns')">
			<template #icon>
				<ViewColumnIcon :size="20" />
			</template>
			<NcActionButton
				v-for="option in columnOptions"
				:key="option.key"
				:active="isVisible(option.key)"
				@click="toggleColumn(option.key)">
				<template #icon>
					<component :is="isVisible(option.key) ? CheckboxMarkedIcon : CheckboxBlankOutlineIcon" :size="18" />
				</template>
				{{ option.label }}
			</NcActionButton>
		</NcActions>
	</div>
</template>

<style module>
.toggleWrapper {
	display: flex;
	align-items: center;
}
</style>
