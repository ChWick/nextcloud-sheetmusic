<script setup lang="ts">
import type { FileLogEntry, FileLogLevel } from '../../types/song'
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { computeSyncStats, LEVEL_ORDER } from '../../utils/file-sync'
import FilesSyncLogItem from './FilesSyncLogItem.vue'
import FilesSyncStatsChips from './FilesSyncStatsChips.vue'

const props = defineProps<{
	open: boolean
	isRunning: boolean
	runError: string
	log: FileLogEntry[]
	filter: FileLogLevel
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	'update:filter': [value: FileLogLevel]
	openSong: [entry: FileLogEntry]
}>()

const visibleLog = computed(() =>
	props.log.filter((entry) => LEVEL_ORDER[entry.level] >= LEVEL_ORDER[props.filter]),
)

const stats = computed(() => computeSyncStats(props.log))
</script>

<template>
	<NcDialog
		v-if="open"
		:name="t('mobilesheetsmanager', 'Sync report')"
		size="large"
		@close="emit('update:open', false)">
		<div v-if="isRunning" :class="$style.running">
			{{ t('mobilesheetsmanager', 'Syncing file paths and metadata…') }}
		</div>
		<template v-else>
			<p v-if="runError" :class="$style.runError" role="alert">{{ runError }}</p>
			<template v-else>
				<FilesSyncStatsChips :stats="stats" />

				<div :class="$style.filter">
					<NcButton
						:variant="filter === 'info' ? 'primary' : 'secondary'"
						@click="emit('update:filter', 'info')">
						{{ t('mobilesheetsmanager', 'All') }}
					</NcButton>
					<NcButton
						:variant="filter === 'warning' ? 'primary' : 'secondary'"
						@click="emit('update:filter', 'warning')">
						{{ t('mobilesheetsmanager', 'Warnings and higher') }}
					</NcButton>
					<NcButton
						:variant="filter === 'error' ? 'primary' : 'secondary'"
						@click="emit('update:filter', 'error')">
						{{ t('mobilesheetsmanager', 'Errors only') }}
					</NcButton>
				</div>

				<ul :class="$style.log">
					<FilesSyncLogItem
						v-for="(entry, index) in visibleLog"
						:key="index"
						:entry="entry"
						@open-song="emit('openSong', entry)" />
				</ul>
				<p v-if="visibleLog.length === 0">{{ t('mobilesheetsmanager', 'No matching entries.') }}</p>
			</template>
		</template>
		<div :class="$style.dialogActions">
			<NcButton @click="emit('update:open', false)">{{ t('mobilesheetsmanager', 'Close') }}</NcButton>
		</div>
	</NcDialog>
</template>

<style module>
.running {
	display: flex;
	align-items: center;
	justify-content: center;
	min-height: 120px;
	color: var(--color-text-maxcontrast);
}

.runError {
	color: var(--color-error);
}

.filter {
	display: flex;
	gap: 8px;
	margin-bottom: 12px;
}

.log {
	display: grid;
	gap: 8px;
	min-height: 200px;
	max-height: 60vh;
	margin: 0;
	padding: 0;
	overflow-y: auto;
	scrollbar-gutter: stable;
	list-style: none;
}

.dialogActions {
	display: flex;
	justify-content: flex-end;
	margin-top: 12px;
}
</style>
