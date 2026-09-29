<script setup lang="ts">
import type { FileLogEntry, FileLogLevel } from '../types/song'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { ref } from 'vue'
import { useRouter } from 'vue-router'
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

/** Run the sync tool and show its report. */
async function runSync() {
	isDialogOpen.value = true
	isRunning.value = true
	log.value = []
	runError.value = ''
	filter.value = 'info'

	try {
		const response = await axios.post(generateOcsUrl('/apps/mobilesheetsmanager/api/sync-library'), {
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

/** Open the song an entry belongs to in the editor, right where it can be fixed. */
function openSong(entry: FileLogEntry) {
	if (entry.songId <= 0) {
		return
	}

	isDialogOpen.value = false
	router.push({
		name: 'LibraryView',
		params: { library: String(props.selectedLibrary), view: 'songs' },
		query: { song: String(entry.songId), edit: '1' },
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
				@sync="runSync" />
		</div>

		<FilesSyncReportDialog
			v-model:open="isDialogOpen"
			v-model:filter="filter"
			:is-running="isRunning"
			:run-error="runError"
			:log="log"
			@open-song="openSong" />
	</div>
</template>

<style module>
.filesArea {
	display: flex;
	justify-content: center;
	padding: 24px 16px;
}
</style>
