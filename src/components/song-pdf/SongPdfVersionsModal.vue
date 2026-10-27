<script lang="ts">
export interface FileVersionInfo {
	revision: number
	timestamp: number
	size: number
}

export function formatFileSize(bytes: number): string {
	if (bytes < 1024) return `${bytes} B`
	if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
	return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

export function formatTimestamp(timestamp: number): string {
	if (!timestamp) return ''
	const d = new Date(timestamp * 1000)
	return d.toLocaleString()
}
</script>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import History from 'vue-material-design-icons/History.vue'

withDefaults(
	defineProps<{
		open: boolean
		versions?: FileVersionInfo[]
		isLoading?: boolean
		isReverting?: boolean
		hasUnsavedChanges?: boolean
	}>(),
	{
		versions: () => [],
		isLoading: false,
		isReverting: false,
		hasUnsavedChanges: false,
	},
)

const emit = defineEmits<{
	'update:open': [value: boolean]
	revert: [revision: number]
	'discard-unsaved': []
}>()
</script>

<template>
	<NcDialog
		v-if="open"
		:open="open"
		:name="t('sheetmusic', 'PDF Version History')"
		size="normal"
		@update:open="emit('update:open', $event)">
		<template #actions>
			<NcButton @click="emit('update:open', false)">
				{{ t('sheetmusic', 'Close') }}
			</NcButton>
		</template>

		<div v-if="hasUnsavedChanges" :class="$style.unsavedNotice">
			<span :class="$style.unsavedText">
				{{ t('sheetmusic', 'You have unsaved changes in this editing session (e.g. page rotations or crops). Version history reflects saved file revisions.') }}
			</span>
			<NcButton
				size="small"
				variant="tertiary"
				@click="emit('discard-unsaved')">
				{{ t('sheetmusic', 'Discard unsaved changes') }}
			</NcButton>
		</div>

		<div v-if="isLoading" :class="$style.versionsLoading">
			<NcLoadingIcon :size="32" />
			<span>{{ t('sheetmusic', 'Loading version history…') }}</span>
		</div>
		<div v-else-if="versions.length === 0" :class="$style.noVersions">
			<History :size="40" />
			<p>{{ t('sheetmusic', 'No previous versions found for this file.') }}</p>
			<small>{{ t('sheetmusic', 'Previous versions are automatically created in Nextcloud each time you save changes to the PDF.') }}</small>
		</div>
		<div v-else :class="$style.versionsList">
			<div
				v-for="ver in versions"
				:key="ver.revision"
				:class="$style.versionRow">
				<div :class="$style.versionInfo">
					<span :class="$style.versionDate">{{ formatTimestamp(ver.timestamp) }}</span>
					<span :class="$style.versionMeta">{{ formatFileSize(ver.size) }}</span>
				</div>
				<NcButton
					size="small"
					variant="secondary"
					:disabled="isReverting"
					@click="emit('revert', ver.revision)">
					{{ isReverting ? t('sheetmusic', 'Reverting…') : t('sheetmusic', 'Revert to this version') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<style module>
.unsavedNotice {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 10px 14px;
	margin-bottom: 12px;
	background-color: var(--color-background-hover);
	border-left: 4px solid var(--color-warning);
	border-radius: 4px;
}

.unsavedText {
	font-size: 13px;
	color: var(--color-main-text);
	line-height: 1.4;
}

.versionsLoading {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 32px;
	gap: 12px;
	color: var(--color-text-maxcontrast);
}

.noVersions {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 32px 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	gap: 8px;
}

.noVersions p {
	margin: 0;
	font-weight: bold;
	font-size: 15px;
}

.noVersions small {
	font-size: 12px;
	max-width: 320px;
}

.versionsList {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-height: 400px;
	overflow-y: auto;
	padding: 4px;
}

.versionRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 14px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background-color: var(--color-main-background);
}

.versionInfo {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.versionDate {
	font-weight: 600;
	font-size: 13px;
	color: var(--color-main-text);
}

.versionMeta {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>

