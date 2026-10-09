<script setup lang="ts">
import type { IntegrityIssue, LibraryData } from '../types/song'
import { computed, onMounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import ContentSaveOutlineIcon from 'vue-material-design-icons/ContentSaveOutline.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import MagnifyIcon from 'vue-material-design-icons/Magnify.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { showError } from '@nextcloud/dialogs'
import { apiGet, apiPost } from '../api/client'
import { describeRequestFailure } from '../utils/api'
import { getFileName } from '../utils/file'
import FilePreviewer from './FilePreviewer.vue'
import SongEditor from './SongEditor.vue'
import SelectSongDialog from './song-editor/SelectSongDialog.vue'
import ViewHeader from './ViewHeader.vue'

const props = defineProps<{
	selectedLibrary: number
	orphanPath: string
	issue?: IntegrityIssue | null
}>()

const emit = defineEmits<{
	back: []
	resolved: []
}>()

const songEditorRef = ref<InstanceType<typeof SongEditor> | null>(null)
const isCreatingSong = ref(false)
const isSelectDialogOpen = ref(false)
const isConfirmDeleteDialogOpen = ref(false)
const isAttaching = ref(false)
const isDeleting = ref(false)
const isLoadingLibraryData = ref(false)
const errorMessage = ref('')
const libraryData = ref<LibraryData | null>(null)

function handleSaveCreatedSong() {
	songEditorRef.value?.saveSong(true)
}

const filename = computed(() => {
	if (props.issue?.details?.filename) {
		return String(props.issue.details.filename)
	}
	return getFileName(props.orphanPath)
})

const previewFilePath = computed(() => {
	if (props.issue?.details?.webdavPath) {
		return String(props.issue.details.webdavPath)
	}
	return props.orphanPath
})

const userRelativePath = computed(() => {
	if (props.issue?.details?.userRelativePath) {
		return String(props.issue.details.userRelativePath)
	}
	return props.orphanPath
})

const suggestedTitle = computed(() => {
	const name = filename.value
	const lastDot = name.lastIndexOf('.')
	return lastDot > 0 ? name.substring(0, lastDot) : name
})

async function fetchLibraryData() {
	if (props.selectedLibrary < 0 || libraryData.value) return

	isLoadingLibraryData.value = true
	try {
		const response = await apiGet('/api/songs', { queryParams: { id: String(props.selectedLibrary) } })
		libraryData.value = response.data.ocs.data as LibraryData
	} catch (error) {
		console.error('Failed to load library data:', error)
	} finally {
		isLoadingLibraryData.value = false
	}
}

async function handleOpenSelectSongDialog() {
	errorMessage.value = ''
	await fetchLibraryData()
	isSelectDialogOpen.value = true
}

async function handleStartCreateSong() {
	errorMessage.value = ''
	await fetchLibraryData()
	isCreatingSong.value = true
}

async function handleAttachToSong(songId: number) {
	if (props.selectedLibrary < 0 || songId <= 0) return

	isAttaching.value = true
	errorMessage.value = ''

	try {
		await apiPost(`/api/songs/${songId}/file/attach`, {
			libraryId: props.selectedLibrary,
			sourcePath: userRelativePath.value,
			action: 'move',
		})

		isSelectDialogOpen.value = false
		emit('resolved')
	} catch (error: any) {
		console.error('Failed to attach orphan file to song:', error)
		const serverMsg = describeRequestFailure(error, t('mobilesheetsmanager', 'Could not link file to selected song.'))
		errorMessage.value = serverMsg
		showError(serverMsg)
	} finally {
		isAttaching.value = false
	}
}

async function handleConfirmDelete() {
	if (props.selectedLibrary < 0) return

	isDeleting.value = true
	errorMessage.value = ''

	try {
		const response = await apiPost(`/api/libraries/${props.selectedLibrary}/integrity/delete-orphan`, {
			orphanPath: userRelativePath.value,
		})

		const result = response.data?.ocs?.data
		if (result && !result.success) {
			errorMessage.value = result.message ?? t('mobilesheetsmanager', 'Could not delete orphan file.')
			showError(errorMessage.value)
		} else {
			isConfirmDeleteDialogOpen.value = false
			emit('resolved')
		}
	} catch (error: any) {
		console.error('Failed to delete orphan file:', error)
		const serverMsg = describeRequestFailure(error, t('mobilesheetsmanager', 'Could not delete orphan file.'))
		errorMessage.value = serverMsg
		showError(serverMsg)
	} finally {
		isDeleting.value = false
	}
}

function handleSongSaved() {
	isCreatingSong.value = false
	emit('resolved')
}

onMounted(() => {
	fetchLibraryData()
})
</script>

<template>
	<div :class="$style.container">
		<ViewHeader :title="isCreatingSong ? t('mobilesheetsmanager', 'Create Song for Orphan File') : t('mobilesheetsmanager', 'Fix Orphan File: {filename}', { filename })">
			<template v-if="isCreatingSong">
				<NcButton
					variant="secondary"
					:title="t('mobilesheetsmanager', 'Back to Orphan Fixer')"
					:aria-label="t('mobilesheetsmanager', 'Back to Orphan Fixer')"
					@click="isCreatingSong = false">
					<template #icon>
						<ArrowLeftIcon :size="20" />
					</template>
				</NcButton>
				<NcButton
					variant="primary"
					:title="t('mobilesheetsmanager', 'Create song')"
					:aria-label="t('mobilesheetsmanager', 'Create song')"
					@click="handleSaveCreatedSong">
					<template #icon>
						<PlusIcon :size="20" />
					</template>
				</NcButton>
			</template>
			<NcButton
				v-else
				variant="secondary"
				:title="t('mobilesheetsmanager', 'Back to Integrity Audit')"
				:aria-label="t('mobilesheetsmanager', 'Back to Integrity Audit')"
				@click="emit('back')">
				<template #icon>
					<ArrowLeftIcon :size="20" />
				</template>
			</NcButton>
		</ViewHeader>

		<div :class="$style.content">
			<div v-if="errorMessage" :class="$style.error" role="alert">
				{{ errorMessage }}
			</div>

			<!-- Full song creation editor with preattached orphan file -->
			<SongEditor
				v-if="isCreatingSong"
				ref="songEditorRef"
				:song="null"
				:library-id="selectedLibrary"
				:composers="libraryData?.composers ?? []"
				:books="libraryData?.books ?? []"
				:collections="libraryData?.collections ?? []"
				:artists="libraryData?.artists ?? []"
				:genres="libraryData?.genres ?? []"
				:keys="libraryData?.keys ?? []"
				:custom-groups="libraryData?.customGroups ?? []"
				:years="libraryData?.years ?? []"
				:signatures="libraryData?.signatures ?? []"
				:source-types="libraryData?.sourceTypes ?? []"
				:imported-nextcloud-node="{ path: userRelativePath, name: filename, action: 'move' }"
				:prefill="{ title: suggestedTitle }"
				@saved="handleSongSaved"
				@cancel="isCreatingSong = false" />

			<!-- Orphan file overview and actions -->
			<template v-else>
				<div :class="$style.actionCard">
					<div :class="$style.actionText">
						<h3>{{ t('mobilesheetsmanager', 'How would you like to fix this orphan file?') }}</h3>
						<p>{{ t('mobilesheetsmanager', 'This file exists in storage but is not connected to any song in your library. Choose an option below.') }}</p>
					</div>

					<div :class="$style.buttonGroup">
						<NcButton
							variant="secondary"
							:disabled="isLoadingLibraryData"
							@click="handleOpenSelectSongDialog">
							<template #icon>
								<MagnifyIcon :size="18" />
							</template>
							{{ t('mobilesheetsmanager', 'Find Existing Song') }}
						</NcButton>

						<NcButton
							variant="primary"
							:disabled="isLoadingLibraryData"
							@click="handleStartCreateSong">
							<template #icon>
								<PlusIcon :size="18" />
							</template>
							{{ t('mobilesheetsmanager', 'Create a New Song') }}
						</NcButton>

						<NcButton
							variant="error"
							:disabled="isLoadingLibraryData || isDeleting"
							@click="isConfirmDeleteDialogOpen = true">
							<template #icon>
								<DeleteIcon :size="18" />
							</template>
							{{ t('mobilesheetsmanager', 'Delete File') }}
						</NcButton>
					</div>
				</div>

				<div :class="$style.previewContainer">
					<FilePreviewer
						:file-path="previewFilePath"
						:show-external-button="true" />
				</div>
			</template>
		</div>

		<!-- Dialog for selecting an existing song -->
		<SelectSongDialog
			v-model:open="isSelectDialogOpen"
			:orphan-path="userRelativePath"
			:filename="filename"
			:library-id="selectedLibrary"
			:library-data="libraryData"
			:is-attaching="isAttaching"
			@select-song="handleAttachToSong" />

		<!-- Dialog for confirming orphan file deletion -->
		<NcDialog
			v-if="isConfirmDeleteDialogOpen"
			:name="t('mobilesheetsmanager', 'Delete Orphan File')"
			size="normal"
			@close="isConfirmDeleteDialogOpen = false">
			<p>{{ t('mobilesheetsmanager', 'Are you sure you want to permanently delete "{filename}" from library storage?', { filename }) }}</p>
			<p>{{ t('mobilesheetsmanager', 'This file is not linked to any song. Once deleted, it cannot be recovered.') }}</p>
			<template #actions>
				<NcButton @click="isConfirmDeleteDialogOpen = false">
					{{ t('mobilesheetsmanager', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="error"
					:disabled="isDeleting"
					@click="handleConfirmDelete">
					{{ isDeleting ? t('mobilesheetsmanager', 'Deleting…') : t('mobilesheetsmanager', 'Delete File') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<style module>
.container {
	display: flex;
	flex-direction: column;
	width: 100%;
	height: 100%;
	min-height: 100%;
}

.content {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px 24px;
	flex: 1;
	min-height: 0;
}

.error {
	padding: 12px 16px;
	background-color: var(--color-error-background, rgba(229, 57, 53, 0.1));
	border: 1px solid var(--color-error);
	border-radius: var(--border-radius-large, 8px);
	color: var(--color-error);
	font-weight: 500;
}

.actionCard {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 20px 24px;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	gap: 24px;
	flex-wrap: wrap;
}

.actionText h3 {
	margin: 0 0 6px;
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.actionText p {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.95rem;
}

.buttonGroup {
	display: flex;
	align-items: center;
	gap: 12px;
}

@media (max-width: 768px) {
	.content {
		padding: 12px;
	}

	.actionCard {
		align-items: stretch;
		padding: 16px;
		gap: 16px;
	}

	.buttonGroup {
		width: 100%;
		flex-direction: column;
		align-items: stretch;
		gap: 8px;
	}
}

.previewContainer {
	display: flex;
	flex-direction: column;
	flex: 1;
	min-height: 480px;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	overflow: hidden;
}
</style>
