<script setup lang="ts">
import { apiGet, apiPost } from '../api/client'
import type { FileLogEntry, FileLogLevel, IntegrityIssue, LibraryData } from '../types/song'
import type { ResolveBothSongData } from './song-editor/SongMergeDialog.vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import FilesIntegrityReportDialog from './files/FilesIntegrityReportDialog.vue'
import FilesSyncReportDialog from './files/FilesSyncReportDialog.vue'
import FilesSyncToolCard from './files/FilesSyncToolCard.vue'
import SongMergeDialog from './song-editor/SongMergeDialog.vue'
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

// Merge dialog state
const isMergeDialogOpen = ref(false)
const isMerging = ref(false)
const initialMergeMode = ref<'merge' | 'keepBoth'>('merge')
const mergeIssue = ref<IntegrityIssue | null>(null)
const libraryData = ref<LibraryData | null>(null)

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

/** Redirect to the full-page Integrity Audit view */
function auditIntegrity() {
	router.push({
		name: 'LibraryView',
		params: { library: String(props.selectedLibrary), view: 'integrity' },
	})
}

/** Automatically fix a specific integrity issue */
async function fixIntegrityIssue(issue: IntegrityIssue) {
	try {
		const response = await apiPost(`/api/libraries/${props.selectedLibrary}/integrity/fix`, {
			issueType: issue.type,
			songId: issue.songId,
			fileId: issue.fileId,
		})
		const result = response.data?.ocs?.data
		if (result && !result.success) {
			integrityError.value = result.message ?? t('mobilesheetsmanager', 'Failed to fix integrity issue.')
		} else {
			await auditIntegrity()
		}
	} catch (error) {
		console.error('Failed to fix integrity issue:', error)
		const serverMessage = axios.isAxiosError(error)
			? error.response?.data?.ocs?.data?.message
			: undefined
		integrityError.value = serverMessage
			?? t('mobilesheetsmanager', 'Failed to fix integrity issue. Please try again.')
	}
}

/** Open the merge dialog for duplicate-file-path issues that span distinct songs */
async function openMergeDialog(issue: IntegrityIssue, mode: 'merge' | 'keepBoth' = 'merge') {
	mergeIssue.value = issue
	initialMergeMode.value = mode
	try {
		const response = await apiGet('/api/songs', { queryParams: { id: String(props.selectedLibrary) } })
		libraryData.value = response.data.ocs.data as LibraryData
	} catch {
		// fall back to showing the dialog without fresh data; songs still visible
	}
	isMergeDialogOpen.value = true
}

/** Execute merge (1 song) via API and refresh integrity audit */
async function executeMerge(payload: {
	keepId: number
	droppedId: number
	merged: Record<string, unknown>
}) {
	isMerging.value = true
	try {
		await axios.post(
			`/ocs/v2.php/apps/mobilesheetsmanager/api/songs/merge`,
			{
				libraryId: props.selectedLibrary,
				keepId: payload.keepId,
				droppedId: payload.droppedId,
				merged: payload.merged,
			},
			{ headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' } },
		)
		isMergeDialogOpen.value = false
		mergeIssue.value = null
		await auditIntegrity()
	} catch (error) {
		console.error('Failed to merge songs:', error)
		const serverMessage = axios.isAxiosError(error)
			? error.response?.data?.ocs?.data?.message ?? error.response?.data?.message
			: undefined
		integrityError.value = serverMessage
			?? t('mobilesheetsmanager', 'Failed to merge songs. Please try again.')
	} finally {
		isMerging.value = false
	}
}

/** Execute resolveBoth (keep both songs as separate entities with adapted titles/metadata/files) */
async function executeResolveBoth(payload: {
	songA: ResolveBothSongData
	songB: ResolveBothSongData
}) {
	if (props.selectedLibrary < 0) return

	isMerging.value = true
	integrityError.value = ''
	try {
		await apiPost('/api/songs/resolve-both', {
			libraryId: props.selectedLibrary,
			songA: payload.songA,
			songB: payload.songB,
		})

		isMergeDialogOpen.value = false
		mergeIssue.value = null
		await auditIntegrity()
	} catch (error: any) {
		console.error('Failed to resolve both songs:', error)
		const serverMessage = error?.response?.data?.ocs?.data?.message
			?? error?.response?.data?.message
			?? error?.message
		integrityError.value = serverMessage
			?? t('mobilesheetsmanager', 'Failed to save updated songs. Please try again.')
	} finally {
		isMerging.value = false
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
			@merge-songs="openMergeDialog"
			@recheck="auditIntegrity" />

		<SongMergeDialog
			v-if="mergeIssue && libraryData"
			v-model:open="isMergeDialogOpen"
			:issue="mergeIssue"
			:library-data="libraryData"
			:is-merging="isMerging"
			:initial-mode="initialMergeMode"
			:error="integrityError"
			@merge="executeMerge"
			@resolve-both="executeResolveBoth" />
	</div>
</template>

<style module>
.filesArea {
	display: flex;
	justify-content: center;
	padding: 24px 16px;
}
</style>
