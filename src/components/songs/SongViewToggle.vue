<script setup lang="ts">
import type { Component } from 'vue'
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import TableIcon from 'vue-material-design-icons/Table.vue'
import ViewGridIcon from 'vue-material-design-icons/ViewGrid.vue'
import ViewListIcon from 'vue-material-design-icons/ViewList.vue'

export type ViewMode = 'table' | 'cards' | 'list'

const props = defineProps<{
	viewMode: ViewMode
}>()

const emit = defineEmits<{
	'update:viewMode': [mode: ViewMode]
}>()

const viewOptions: { value: ViewMode; label: string; icon: Component }[] = [
	{ value: 'table', label: t('mobilesheetsmanager', 'Table view'), icon: TableIcon },
	{ value: 'cards', label: t('mobilesheetsmanager', 'Card view'), icon: ViewGridIcon },
	{ value: 'list', label: t('mobilesheetsmanager', 'List view'), icon: ViewListIcon },
]

const activeOption = computed(() => {
	return viewOptions.find((opt) => opt.value === props.viewMode) ?? viewOptions[0]
})
</script>

<template>
	<div :class="$style.toggleWrapper">
		<NcActions
			:aria-label="activeOption.label"
			:title="activeOption.label">
			<template #icon>
				<component :is="activeOption.icon" :size="20" />
			</template>
			<NcActionButton
				v-for="option in viewOptions"
				:key="option.value"
				:active="viewMode === option.value"
				@click="emit('update:viewMode', option.value)">
				<template #icon>
					<component :is="option.icon" :size="20" />
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
	margin-bottom: 0;
}
</style>
