<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'
import SortAscendingIcon from 'vue-material-design-icons/SortAscending.vue'
import SortDescendingIcon from 'vue-material-design-icons/SortDescending.vue'
import type { SortKey, SortOrder } from '../../utils/song-formatter'

const props = defineProps<{
	sortKey: SortKey
	sortOrder: SortOrder
}>()

const emit = defineEmits<{
	'update:sortKey': [key: SortKey]
	'update:sortOrder': [order: SortOrder]
}>()

const sortOptions: { value: SortKey; label: string }[] = [
	{ value: 'title', label: t('mobilesheetsmanager', 'Title') },
	{ value: 'composer', label: t('mobilesheetsmanager', 'Composer') },
	{ value: 'book', label: t('mobilesheetsmanager', 'Book') },
	{ value: 'collection', label: t('mobilesheetsmanager', 'Collection') },
	{ value: 'artist', label: t('mobilesheetsmanager', 'Artist') },
	{ value: 'lastModified', label: t('mobilesheetsmanager', 'Last modified') },
	{ value: 'dateCreated', label: t('mobilesheetsmanager', 'Date created') },
]

const currentOptionLabel = computed(() => {
	const opt = sortOptions.find(o => o.value === props.sortKey)
	return opt ? opt.label : t('mobilesheetsmanager', 'Sort')
})

function toggleOrder() {
	emit('update:sortOrder', props.sortOrder === 'asc' ? 'desc' : 'asc')
}
</script>

<template>
	<div :class="$style.sortControlWrapper">
		<NcActions
			:aria-label="t('mobilesheetsmanager', 'Sort by: {label}', { label: currentOptionLabel })"
			:title="t('mobilesheetsmanager', 'Sort by: {label}', { label: currentOptionLabel })">
			<template #icon>
				<component :is="sortOrder === 'asc' ? SortAscendingIcon : SortDescendingIcon" :size="20" />
			</template>
			<NcActionButton
				v-for="option in sortOptions"
				:key="option.value"
				:active="sortKey === option.value"
				@click="emit('update:sortKey', option.value)">
				{{ option.label }}
			</NcActionButton>
		</NcActions>
		<NcButton
			size="small"
			:title="sortOrder === 'asc' ? t('mobilesheetsmanager', 'Ascending') : t('mobilesheetsmanager', 'Descending')"
			:aria-label="sortOrder === 'asc' ? t('mobilesheetsmanager', 'Ascending') : t('mobilesheetsmanager', 'Descending')"
			@click="toggleOrder">
			<template #icon>
				<component :is="sortOrder === 'asc' ? SortAscendingIcon : SortDescendingIcon" :size="18" />
			</template>
		</NcButton>
	</div>
</template>

<style module>
.sortControlWrapper {
	display: flex;
	align-items: center;
	gap: 4px;
}
</style>
