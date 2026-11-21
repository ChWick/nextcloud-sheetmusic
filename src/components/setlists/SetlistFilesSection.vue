<template>
	<div :class="$style.section">
		<div :class="$style.header">
			<div :class="$style.titleRow">
				<PaperclipIcon :size="16" :class="$style.headerIcon" />
				<span :class="$style.title">{{ t('sheetmusic', 'Supplemental Files / Notes') }}</span>
				<span v-if="files.length > 0" :class="$style.count">({{ files.length }})</span>
			</div>
			<div v-if="canWrite !== false" :class="$style.uploadActions">
				<input
					ref="fileInputRef"
					type="file"
					accept=".pdf,.docx,.xlsx"
					multiple
					:class="$style.hiddenInput"
					@change="onFileSelected">
				<ResponsiveNcButton
					variant="tertiary"
					collapse-at="container-560"
					:disabled="isUploading"
					:label="isUploading ? t('sheetmusic', 'Uploading…') : t('sheetmusic', 'Upload File')"
					@click="triggerFilePicker">
					<template #icon>
						<UploadIcon :size="18" />
					</template>
				</ResponsiveNcButton>
				<ResponsiveNcButton
					variant="tertiary"
					collapse-at="container-560"
					:disabled="isUploading"
					:label="t('sheetmusic', 'Choose from Nextcloud')"
					@click="openNextcloudPicker">
					<template #icon>
						<FolderOutlineIcon :size="18" />
					</template>
				</ResponsiveNcButton>
			</div>
		</div>

		<p v-if="uploadError" :class="$style.error" role="alert">
			{{ uploadError }}
		</p>

		<div v-if="files.length > 0" :class="$style.fileList">
			<div
				v-for="file in files"
				:key="file.id"
				:class="$style.fileChip">
				<button
					type="button"
					:class="$style.filePreviewBtn"
					:title="t('sheetmusic', 'Preview {name}', { name: file.filename })"
					@click="emit('preview', file)">
					<FileDocumentOutlineIcon :size="16" :class="$style.fileIcon" />
					<span :class="$style.fileName">{{ file.filename }}</span>
					<span v-if="file.fileSize > 0" :class="$style.fileSize">{{ formatFileSize(file.fileSize) }}</span>
				</button>
				<button
					v-if="canWrite !== false"
					type="button"
					:class="$style.deleteBtn"
					:aria-label="t('sheetmusic', 'Remove {name}', { name: file.filename })"
					:title="t('sheetmusic', 'Remove')"
					@click="emit('remove', file.id)">
					<CloseIcon :size="14" />
				</button>
			</div>
		</div>

		<NcDialog
			v-if="isAttachDialogOpen"
			:open="true"
			:name="t('sheetmusic', 'Attach File from Nextcloud')"
			@closing="closeAttachDialog">
			<div :class="$style.attachDialogBody">
				<p :class="$style.attachPrompt">
					{{ t('sheetmusic', 'How would you like to add "{file}" to this setlist?', { file: selectedNodeName }) }}
				</p>
				<div :class="$style.attachOptions">
					<NcCheckboxRadioSwitch
						v-model="attachAction"
						type="radio"
						name="setlist-attach-action"
						value="copy">
						{{ t('sheetmusic', 'Copy file into setlist folder (keep original)') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						v-model="attachAction"
						type="radio"
						name="setlist-attach-action"
						value="move">
						{{ t('sheetmusic', 'Move file into setlist folder') }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>
			<template #actions>
				<NcButton @click="closeAttachDialog">
					{{ t('sheetmusic', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" @click="confirmAttach">
					{{ t('sheetmusic', 'Attach') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { FilePickerClosed, getFilePickerBuilder, showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import FileDocumentOutlineIcon from 'vue-material-design-icons/FileDocumentOutline.vue'
import FolderOutlineIcon from 'vue-material-design-icons/FolderOutline.vue'
import PaperclipIcon from 'vue-material-design-icons/Paperclip.vue'
import UploadIcon from 'vue-material-design-icons/Upload.vue'
import ResponsiveNcButton from '../ResponsiveNcButton.vue'
import type { SetlistFile } from '../../types/song'
import { formatFileSize } from '../../utils/file'

const ALLOWED_EXTENSIONS = ['.pdf', '.docx', '.xlsx']

withDefaults(
	defineProps<{
		files: SetlistFile[]
		canWrite?: boolean
		isUploading?: boolean
		uploadError?: string
	}>(),
	{
		canWrite: true,
		isUploading: false,
		uploadError: '',
	},
)

const emit = defineEmits<{
	upload: [files: File[]]
	attach: [payload: { sourcePath: string; action: 'copy' | 'move' }]
	preview: [file: SetlistFile]
	remove: [fileId: number]
}>()

const fileInputRef = ref<HTMLInputElement | null>(null)
const isAttachDialogOpen = ref(false)
const selectedNodePath = ref('')
const selectedNodeName = ref('')
const attachAction = ref<'copy' | 'move'>('copy')

function triggerFilePicker() {
	fileInputRef.value?.click()
}

function isAllowedFilename(filename: string): boolean {
	const lower = filename.toLowerCase()
	return ALLOWED_EXTENSIONS.some((ext) => lower.endsWith(ext))
}

async function openNextcloudPicker() {
	const picker = getFilePickerBuilder(t('sheetmusic', 'Select a file from Nextcloud'))
		.setMultiSelect(false)
		.setCanPick((node) => node.type === 'file' && isAllowedFilename(node.basename))
		.setButtonFactory(([node]) => [{
			label: node
				? t('sheetmusic', 'Select {file}', { file: node.basename }, { escape: false })
				: t('sheetmusic', 'Select a file'),
			callback: () => {},
			disabled: !node || node.type !== 'file' || !isAllowedFilename(node.basename),
			variant: 'primary',
		}])
		.build()

	try {
		const [picked] = await picker.pickNodes()
		if (picked) {
			selectedNodePath.value = picked.path
			selectedNodeName.value = picked.basename
			attachAction.value = 'copy'
			isAttachDialogOpen.value = true
		}
	} catch (error) {
		if (!(error instanceof FilePickerClosed)) {
			showError(t('sheetmusic', 'Could not select a file from Nextcloud.'))
		}
	}
}

function closeAttachDialog() {
	isAttachDialogOpen.value = false
	selectedNodePath.value = ''
	selectedNodeName.value = ''
}

function confirmAttach() {
	if (!selectedNodePath.value) return
	emit('attach', {
		sourcePath: selectedNodePath.value,
		action: attachAction.value,
	})
	closeAttachDialog()
}

function onFileSelected(event: Event) {
	const input = event.target as HTMLInputElement
	const selected = input.files ? Array.from(input.files) : []
	if (selected.length > 0) {
		emit('upload', selected)
	}
	input.value = ''
}
</script>

<style module>
.section {
	container-type: inline-size;
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);
}

.header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.titleRow {
	display: flex;
	align-items: center;
	gap: 6px;
	min-width: 0;
	flex: 1 1 auto;
	font-size: 0.9rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.headerIcon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.title {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.count {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
	font-weight: 400;
}

.uploadActions {
	display: flex;
	align-items: center;
	flex-shrink: 0;
	gap: 8px;
}

.attachDialogBody {
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.attachPrompt {
	margin: 0;
}

.attachOptions {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.hiddenInput {
	display: none;
}

.error {
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-error);
}

.fileList {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.fileChip {
	display: inline-flex;
	align-items: center;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill);
	background-color: var(--color-main-background);
	overflow: hidden;
	max-width: 100%;
}

.filePreviewBtn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 5px 10px;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 0.85rem;
	min-width: 0;
}

.filePreviewBtn:hover {
	background-color: var(--color-background-dark);
}

.fileIcon {
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.fileName {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	max-width: 240px;
}

.fileSize {
	color: var(--color-text-maxcontrast);
	font-size: 0.75rem;
	flex-shrink: 0;
}

.deleteBtn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 5px 8px;
	border: none;
	border-left: 1px solid var(--color-border);
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}

.deleteBtn:hover {
	color: var(--color-error);
	background-color: var(--color-background-dark);
}
</style>
