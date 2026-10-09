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
	{ key: 'title', label: t('sheetmusic', 'Title') },
	{ key: 'composer', label: t('sheetmusic', 'Composer') },
	{ key: 'book', label: t('sheetmusic', 'Book') },
	{ key: 'collection', label: t('sheetmusic', 'Collection') },
	{ key: 'artist', label: t('sheetmusic', 'Artist') },
	{ key: 'genre', label: t('sheetmusic', 'Genre') },
	{ key: 'key', label: t('sheetmusic', 'Key') },
	{ key: 'customGroup', label: t('sheetmusic', 'Custom Group') },
	{ key: 'year', label: t('sheetmusic', 'Year') },
	{ key: 'signature', label: t('sheetmusic', 'Time Signature') },
	{ key: 'sourceType', label: t('sheetmusic', 'Source Type') },
	{ key: 'stars', label: t('sheetmusic', 'Rating') },
	{ key: 'difficulty', label: t('sheetmusic', 'Difficulty') },
	{ key: 'duration', label: t('sheetmusic', 'Duration') },
	{ key: 'keywords', label: t('sheetmusic', 'Keywords') },
	{ key: 'custom', label: t('sheetmusic', 'Custom') },
	{ key: 'custom2', label: t('sheetmusic', 'Custom 2') },
	{ key: 'lastModified', label: t('sheetmusic', 'Last modified') },
	{ key: 'dateCreated', label: t('sheetmusic', 'Date created') },
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
