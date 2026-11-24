<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import MergeIcon from 'vue-material-design-icons/Merge.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'

defineProps<{
	selectedCount: number
	canWrite: boolean
}>()

const emit = defineEmits<{
	compareMerge: []
	editSelected: []
	deleteSelected: []
	clearSelection: []
}>()
</script>

<template>
	<div :class="$style.selectionBanner">
		<span :class="$style.selectionCount">
			{{ t('sheetmusic', '{count} songs selected', { count: selectedCount }, { escape: false }) }}
		</span>
		<NcButton
			v-if="canWrite && selectedCount === 2"
			size="small"
			variant="secondary"
			:title="t('sheetmusic', 'Compare & Merge selected 2 songs')"
			:aria-label="t('sheetmusic', 'Compare & Merge selected 2 songs')"
			@click="emit('compareMerge')">
			<template #icon>
				<MergeIcon :size="18" />
			</template>
			{{ t('sheetmusic', 'Compare / Merge') }}
		</NcButton>
		<NcButton
			v-if="canWrite"
			size="small"
			variant="primary"
			:title="t('sheetmusic', 'Edit selected ({count})', { count: selectedCount }, { escape: false })"
			:aria-label="t('sheetmusic', 'Edit selected ({count})', { count: selectedCount }, { escape: false })"
			@click="emit('editSelected')">
			<template #icon>
				<PencilIcon :size="18" />
			</template>
		</NcButton>
		<NcButton
			v-if="canWrite"
			size="small"
			variant="error"
			:title="t('sheetmusic', 'Delete selected ({count})', { count: selectedCount }, { escape: false })"
			:aria-label="t('sheetmusic', 'Delete selected ({count})', { count: selectedCount }, { escape: false })"
			@click="emit('deleteSelected')">
			<template #icon>
				<DeleteIcon :size="18" />
			</template>
		</NcButton>
		<NcButton
			size="small"
			:title="t('sheetmusic', 'Clear selection')"
			:aria-label="t('sheetmusic', 'Clear selection')"
			@click="emit('clearSelection')">
			<template #icon>
				<CloseIcon :size="18" />
			</template>
		</NcButton>
	</div>
</template>

<style module>
.selectionBanner {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 16px;
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-primary-element);
	border-radius: var(--border-radius-large, 8px);
}

.selectionCount {
	font-weight: 600;
	font-size: 0.9rem;
	color: var(--color-main-text);
	margin-right: auto;
}
</style>
