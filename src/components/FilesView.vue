<script setup lang="ts">
import { apiGet, apiPost } from '../api/client'
import type { FileLogEntry, FileLogLevel, IntegrityIssue } from '../types/song'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import FilesIntegrityReportDialog from './files/FilesIntegrityReportDialog.vue'
import FilesSyncReportDialog from './files/FilesSyncReportDialog.vue'
import FilesSyncToolCard from './files/FilesSyncToolCard.vue'
import ViewHeader from './ViewHeader.vue'

const props = defineProps<{
	selectedLibrary: number
}>()

const router = useRouter()

const isDialogOpen = ref(false)
const isRunning = ref(false)
const log = ref<FileLogEntry[]>([])
const runError = ref('')
const filter = ref<FileLogLevel>('info')

// Integrity Audit State
const isIntegrityDialogOpen = ref(false)
const isCheckingIntegrity = ref(false)
const integrityError = ref('')
const integrityIssues = ref<IntegrityIssue[]>([])

/** Run the sync tool and show its report. */
async function runSync() {
	isDialogOpen.value = true
	isRunning.value = true
	log.value = []
	runError.value = ''
	filter.value = 'info'

	try {
		const response = await apiPost('/api/sync-library', {
			libraryId: props.selectedLibrary,
		})

		log.value = response.data.ocs.data.log ?? []
	} catch (error) {
		console.error('Failed to sync file paths and metadata:', error)
		const serverMessage = axios.isAxiosError(error)
			? error.response?.data?.ocs?.data?.message
			: undefined
		runError.value = serverMessage
			?? t('mobilesheetsmanager', 'The sync run failed. Please try again.')
	} finally {
		isRunning.value = false
	}
}

/** Run integrity check audit */
async function auditIntegrity() {
	isIntegrityDialogOpen.value = true
	isCheckingIntegrity.value = true
	integrityError.value = ''
	integrityIssues.value = []

	try {
		const response = await apiGet(`/api/libraries/${props.selectedLibrary}/integrity`)
		integrityIssues.value = response.data.ocs.data.issues ?? []
	} catch (error) {
		console.error('Failed to audit library integrity:', error)
		const serverMessage = axios.isAxiosError(error)
			? error.response?.data?.ocs?.data?.message
			: undefined
		integrityError.value = serverMessage
			?? t('mobilesheetsmanager', 'Failed to audit library integrity. Please try again.')
	} finally {
		isCheckingIntegrity.value = false
	}
}

/** Automatically fix a specific integrity issue */
async function fixIntegrityIssue(issue: IntegrityIssue) {
	try {
		await apiPost(`/api/libraries/${props.selectedLibrary}/integrity/fix`, {
			issueType: issue.type,
			songId: issue.songId,
			fileId: issue.fileId,
		})
		await auditIntegrity()
	} catch (error) {
		console.error('Failed to fix integrity issue:', error)
	}
}

/** Open the song an entry belongs to in the editor, right where it can be fixed. */
function openSong(entry: FileLogEntry | number) {
	const songId = typeof entry === 'number' ? entry : entry.songId
	if (songId <= 0) {
		return
	}

	isDialogOpen.value = false
	isIntegrityDialogOpen.value = false
	router.push({
		name: 'LibraryView',
		params: { library: String(props.selectedLibrary), view: 'songs' },
		query: { song: String(songId), edit: '1' },
	})
}
</script>

<template>
	<div>
		<ViewHeader :title="t('mobilesheetsmanager', 'Files')" />
		<div :class="$style.filesArea">
			<FilesSyncToolCard
				:is-running="isRunning"
				:disabled="selectedLibrary < 0"
				@sync="runSync"
				@audit-integrity="auditIntegrity" />
		</div>

		<FilesSyncReportDialog
			v-model:open="isDialogOpen"
			v-model:filter="filter"
			:is-running="isRunning"
			:run-error="runError"
			:log="log"
			@open-song="openSong" />

		<FilesIntegrityReportDialog
			v-model:open="isIntegrityDialogOpen"
			:is-checking="isCheckingIntegrity"
			:error="integrityError"
			:issues="integrityIssues"
			@open-song="openSong"
			@fix-issue="fixIntegrityIssue"
			@recheck="auditIntegrity" />
	</div>
</template>

<style module>
.filesArea {
	display: flex;
	justify-content: center;
	padding: 24px 16px;
}
</style>
