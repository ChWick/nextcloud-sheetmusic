<script setup lang="ts">
import type { SongFile } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import { getFileName } from '../../utils/file'

defineProps<{
	file: SongFile
	disabled?: boolean
}>()

const emit = defineEmits<{
	openExternal: [file: SongFile]
	delete: [file: SongFile]
}>()
</script>

<template>
	<li :class="$style.fileItem">
		<div :class="$style.fileInfo">
			<span :class="$style.fileName">{{ getFileName(file.path) }}</span>
			<span :class="$style.filePathText">{{ file.path }}</span>
		</div>
		<div :class="$style.fileActions">
			<NcButton
				type="button"
				variant="secondary"
				:aria-label="t('mobilesheetsmanager', 'Open {file} in external viewer', { file: getFileName(file.path) })"
				:title="t('mobilesheetsmanager', 'Open in external viewer')"
				@click="emit('openExternal', file)">
				<template #icon>
					<OpenInNewIcon :size="20" />
				</template>
			</NcButton>
			<NcButton
				type="button"
				variant="error"
				:disabled="disabled"
				:aria-label="t('mobilesheetsmanager', 'Delete file {file}', { file: getFileName(file.path) })"
				:title="t('mobilesheetsmanager', 'Delete file')"
				@click="emit('delete', file)">
				<template #icon>
					<DeleteIcon :size="20" />
				</template>
			</NcButton>
		</div>
	</li>
</template>

<style module>
.fileItem {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 16px;
	padding: 8px 12px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	min-width: 0;
	box-sizing: border-box;
}

.fileInfo {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1 1 0;
	min-width: 0;
	overflow: hidden;
}

.fileName {
	font-weight: 600;
	font-size: 0.95rem;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.filePathText {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.fileActions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-shrink: 0;
}
</style>
