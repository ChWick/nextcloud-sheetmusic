<script setup lang="ts">
import type { PendingAddition, SongFile } from '../../types/song'
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import UploadIcon from 'vue-material-design-icons/Upload.vue'
import SongFileListItem from './SongFileListItem.vue'
import SongPendingNotice from './SongPendingNotice.vue'

defineProps<{
	currentFiles: SongFile[]
	pendingDeleteFiles: SongFile[]
	pendingDetachFiles: SongFile[]
	pendingAdditions: PendingAddition[]
	saveActionLabel: string
	isSaving: boolean
}>()

const emit = defineEmits<{
	uploadFile: [file: File]
	chooseNextcloudFile: []
	openExternal: [file: SongFile]
	detach: [file: SongFile]
	delete: [file: SongFile]
	restoreDelete: [file: SongFile]
	restoreDetach: [file: SongFile]
	removeAddition: [addition: PendingAddition]
}>()

const fileInput = ref<HTMLInputElement>()

function openUploadPicker() {
	fileInput.value?.click()
}

function handleFileUpload(event: Event) {
	const input = event.target as HTMLInputElement
	const file = input.files?.[0]
	input.value = ''
	if (file) {
		emit('uploadFile', file)
	}
}

defineExpose({ openUploadPicker })
</script>

<template>
	<div :class="$style.fileSection">
		<div :class="$style.fileSectionHeader">
			<label :class="$style.label">{{ t('sheetmusic', 'Attached Files') }}</label>
			<div :class="$style.fileButtons">
				<input
					ref="fileInput"
					type="file"
					style="display: none;"
					@change="handleFileUpload">
				<button
					type="button"
					:class="$style.fileActionButton"
					:disabled="isSaving"
					:title="t('sheetmusic', 'Upload file from computer')"
					:aria-label="t('sheetmusic', 'Upload file from computer')"
					@click="openUploadPicker">
					<UploadIcon :size="20" aria-hidden="true" />
					{{ t('sheetmusic', 'Upload') }}
				</button>

				<button
					type="button"
					:class="$style.fileActionButton"
					:disabled="isSaving"
					:title="t('sheetmusic', 'Choose file from Nextcloud')"
					:aria-label="t('sheetmusic', 'Choose file from Nextcloud')"
					@click="emit('chooseNextcloudFile')">
					<FolderIcon :size="20" aria-hidden="true" />
					{{ t('sheetmusic', 'Choose from Nextcloud') }}
				</button>
			</div>
		</div>

		<ul v-if="currentFiles.length > 0" :class="$style.fileList">
			<SongFileListItem
				v-for="file in currentFiles"
				:key="file.id"
				:file="file"
				:disabled="isSaving"
				@open-external="emit('openExternal', $event)"
				@detach="emit('detach', $event)"
				@delete="emit('delete', $event)" />
		</ul>
		<p v-else :class="$style.noFiles">
			{{ t('sheetmusic', 'No files attached to this song.') }}
		</p>

		<SongPendingNotice
			:pending-delete-files="pendingDeleteFiles"
			:pending-detach-files="pendingDetachFiles"
			:pending-additions="pendingAdditions"
			:save-action-label="saveActionLabel"
			@restore-delete="emit('restoreDelete', $event)"
			@restore-detach="emit('restoreDetach', $event)"
			@remove-addition="emit('removeAddition', $event)" />
	</div>
</template>

<style module>
.fileSection {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 16px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius, 4px);
	margin-top: 8px;
	min-width: 0;
	box-sizing: border-box;
}

.fileSectionHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.label {
	font-size: 0.9rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.fileList {
	margin: 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-width: 0;
}

.noFiles {
	margin: 0;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.fileButtons {
	display: flex;
	gap: 4px;
}

.fileActionButton {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	min-height: var(--default-clickable-area, 44px);
	padding: 6px 12px;
	border: 1px solid var(--color-primary-element-hover, var(--color-primary-element));
	border-radius: var(--border-radius-element, var(--border-radius, 4px));
	background: var(--color-primary-element);
	color: var(--color-primary-element-text, #fff);
	font: inherit;
	font-weight: 600;
	cursor: pointer;
}

.fileActionButton:hover:not(:disabled) {
	background: var(--color-primary-element-hover, var(--color-primary-element));
}

.fileActionButton:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.fileActionButton:disabled {
	opacity: 0.6;
	cursor: default;
}
</style>
