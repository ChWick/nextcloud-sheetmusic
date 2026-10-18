<script setup lang="ts">
import type { IntegrityIssue } from '../../types/song'
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import AlertCircleIcon from 'vue-material-design-icons/AlertCircle.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import FileMultipleIcon from 'vue-material-design-icons/FileMultiple.vue'
import InformationIcon from 'vue-material-design-icons/Information.vue'
import MergeIcon from 'vue-material-design-icons/Merge.vue'
import WrenchIcon from 'vue-material-design-icons/Wrench.vue'

const props = defineProps<{
	issue: IntegrityIssue
	isFixing?: boolean
}>()

const emit = defineEmits<{
	openSong: [songId: number]
	fixIssue: [issue: IntegrityIssue]
	mergeSongs: [issue: IntegrityIssue, mode?: 'merge' | 'keepBoth']
	fixOrphanFile: [issue: IntegrityIssue]
}>()

/** True when this is a duplicate or identical song issue between distinct songs (needs merge dialog, not auto-fix) */
const isDuplicateBetweenSongs = computed(() =>
	(props.issue.type === 'duplicate_file_path' || props.issue.type === 'identical_song')
	&& (props.issue.details?.songIds?.length ?? 0) > 1,
)
</script>

<template>
	<li :class="[$style.item, $style[issue.severity]]">
		<div :class="$style.header">
			<span :class="$style.badge">
				<AlertCircleIcon v-if="issue.severity === 'error'" :size="18" />
				<AlertIcon v-else-if="issue.severity === 'warning'" :size="18" />
				<InformationIcon v-else :size="18" />
				{{ issue.type.replace(/_/g, ' ') }}
			</span>

			<span v-if="issue.songTitle" :class="$style.songTitle">
				{{ issue.songTitle }}
			</span>
		</div>

		<p :class="$style.message">{{ issue.message }}</p>

		<div v-if="issue.path || issue.suggestedPath || issue.type === 'invalid_naming_rule'" :class="$style.paths">
			<div v-if="issue.path" :class="$style.pathLine">
				<span :class="$style.pathLabel">{{ t('sheetmusic', 'Current Path:') }}</span>
				<code>{{ issue.path }}</code>
			</div>
			<div v-if="issue.type === 'orphan_file' && issue.songTitle" :class="$style.pathLine">
				<span :class="$style.pathLabel">{{ t('sheetmusic', 'Matched Song:') }}</span>
				<span>{{ issue.songTitle }}</span>
			</div>
			<div v-if="issue.suggestedPath" :class="$style.pathLine">
				<span :class="$style.pathLabel">{{ t('sheetmusic', 'Expected Path:') }}</span>
				<code>{{ issue.suggestedPath }}</code>
			</div>
			<div v-else-if="issue.type === 'invalid_naming_rule'" :class="$style.pathLine">
				<span :class="$style.pathLabel">{{ t('sheetmusic', 'Expected Path:') }}</span>
				<span :class="$style.pathNote">{{ t('sheetmusic', 'Cannot determine expected path: Song needs a Composer, Artist, or Collection.') }}</span>
			</div>
		</div>

		<div :class="$style.actions">
			<NcButton
				v-if="issue.songId && !isDuplicateBetweenSongs"
				variant="secondary"
				type="tertiary"
				@click="emit('openSong', issue.songId!)">
				{{ t('sheetmusic', 'Edit Song') }}
			</NcButton>

			<template v-if="issue.type === 'orphan_file'">
				<NcButton
					v-if="issue.fixable"
					variant="primary"
					:disabled="isFixing"
					@click="emit('fixIssue', issue)">
					<template #icon>
						<WrenchIcon :size="16" />
					</template>
					{{ isFixing ? t('sheetmusic', 'Fixing…') : t('sheetmusic', 'Auto-Fix Issue') }}
				</NcButton>

				<NcButton
					:variant="issue.fixable ? 'secondary' : 'primary'"
					@click="emit('fixOrphanFile', issue)">
					<template #icon>
						<WrenchIcon :size="16" />
					</template>
					{{ t('sheetmusic', 'Fix Orphan File') }}
				</NcButton>
			</template>

			<template v-else-if="isDuplicateBetweenSongs">
				<NcButton
					variant="secondary"
					:disabled="isFixing"
					@click="emit('mergeSongs', issue, 'keepBoth')">
					<template #icon>
						<FileMultipleIcon :size="16" />
					</template>
					{{ t('sheetmusic', 'Keep as Two') }}
				</NcButton>

				<NcButton
					variant="primary"
					:disabled="isFixing"
					@click="emit('mergeSongs', issue, 'merge')">
					<template #icon>
						<MergeIcon :size="16" />
					</template>
					{{ isFixing ? t('sheetmusic', 'Merging…') : t('sheetmusic', 'Merge Songs') }}
				</NcButton>
			</template>

			<NcButton
				v-else-if="issue.fixable"
				variant="primary"
				:disabled="isFixing"
				@click="emit('fixIssue', issue)">
				<template #icon>
					<WrenchIcon :size="16" />
				</template>
				{{ isFixing ? t('sheetmusic', 'Fixing…') : t('sheetmusic', 'Auto-Fix Issue') }}
			</NcButton>
		</div>
	</li>
</template>

<style module>
.item {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background);
}

.error {
	border-left: 4px solid var(--color-error);
}

.warning {
	border-left: 4px solid var(--color-warning);
}

.info {
	border-left: 4px solid var(--color-primary-element);
}

.header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.badge {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 0.85rem;
	font-weight: 600;
	text-transform: capitalize;
}

.songTitle {
	font-weight: 600;
	color: var(--color-main-text);
}

.message {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.paths {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 8px 12px;
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-background-hover);
	font-size: 0.85rem;
}

.pathLine {
	display: flex;
	gap: 8px;
	align-items: center;
}

.pathLabel {
	font-weight: 600;
	white-space: nowrap;
}

.pathNote {
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

.actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: 4px;
}
</style>
