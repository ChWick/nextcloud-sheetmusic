<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import ViewColumnIcon from 'vue-material-design-icons/ViewColumn.vue'
import CheckboxMarkedIcon from 'vue-material-design-icons/CheckboxMarked.vue'
import CheckboxBlankOutlineIcon from 'vue-material-design-icons/CheckboxBlankOutline.vue'
import type { SongColumn } from '../../types/song'

const props = defineProps<{
	visibleColumns: SongColumn[]
}>()

const emit = defineEmits<{
	'update:visibleColumns': [columns: SongColumn[]]
}>()

const columnOptions: { key: SongColumn; label: string }[] = [
	{ key: 'title', label: t('mobilesheetsmanager', 'Title') },
	{ key: 'composer', label: t('mobilesheetsmanager', 'Composer') },
	{ key: 'book', label: t('mobilesheetsmanager', 'Book') },
	{ key: 'collection', label: t('mobilesheetsmanager', 'Collection') },
	{ key: 'artist', label: t('mobilesheetsmanager', 'Artist') },
	{ key: 'genre', label: t('mobilesheetsmanager', 'Genre') },
	{ key: 'key', label: t('mobilesheetsmanager', 'Key') },
	{ key: 'customGroup', label: t('mobilesheetsmanager', 'Custom Group') },
	{ key: 'year', label: t('mobilesheetsmanager', 'Year') },
	{ key: 'signature', label: t('mobilesheetsmanager', 'Time Signature') },
	{ key: 'sourceType', label: t('mobilesheetsmanager', 'Source Type') },
	{ key: 'stars', label: t('mobilesheetsmanager', 'Rating') },
	{ key: 'difficulty', label: t('mobilesheetsmanager', 'Difficulty') },
	{ key: 'duration', label: t('mobilesheetsmanager', 'Duration') },
	{ key: 'keywords', label: t('mobilesheetsmanager', 'Keywords') },
	{ key: 'custom', label: t('mobilesheetsmanager', 'Custom') },
	{ key: 'custom2', label: t('mobilesheetsmanager', 'Custom 2') },
	{ key: 'lastModified', label: t('mobilesheetsmanager', 'Last modified') },
	{ key: 'dateCreated', label: t('mobilesheetsmanager', 'Date created') },
]

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
			:aria-label="t('mobilesheetsmanager', 'Select visible columns')"
			:title="t('mobilesheetsmanager', 'Select visible columns')">
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
