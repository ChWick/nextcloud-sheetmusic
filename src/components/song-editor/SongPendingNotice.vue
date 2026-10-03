<script setup lang="ts">
import type { PendingAddition, SongFile } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { getFileName } from '../../utils/file'

defineProps<{
	pendingDeleteFiles: SongFile[]
	pendingDetachFiles?: SongFile[]
	pendingAdditions: PendingAddition[]
	saveActionLabel: string
}>()

const emit = defineEmits<{
	restoreDelete: [file: SongFile]
	restoreDetach: [file: SongFile]
	removeAddition: [addition: PendingAddition]
}>()
</script>

<template>
	<div v-if="pendingDeleteFiles.length > 0 || (pendingDetachFiles && pendingDetachFiles.length > 0) || pendingAdditions.length > 0">
		<!-- Staged deletions notice -->
		<div v-if="pendingDeleteFiles.length > 0" :class="$style.deletionWarning">
			<div :class="$style.warningText">
				<strong>{{ t('mobilesheetsmanager', 'Notice:') }}</strong>
				{{ t('mobilesheetsmanager', '{count} file(s) marked for removal will be permanently deleted from storage when you click "{action}".', { count: pendingDeleteFiles.length, action: saveActionLabel }) }}
			</div>
			<ul :class="$style.pendingList">
				<li v-for="file in pendingDeleteFiles" :key="file.id" :class="$style.pendingItem">
					<span :class="$style.pendingName">{{ getFileName(file.path) }}</span>
					<NcButton
						type="button"
						variant="tertiary"
						size="small"
						@click="emit('restoreDelete', file)">
						{{ t('mobilesheetsmanager', 'Restore') }}
					</NcButton>
				</li>
			</ul>
		</div>

		<!-- Staged detachments notice -->
		<div v-if="pendingDetachFiles && pendingDetachFiles.length > 0" :class="$style.detachNotice">
			<div :class="$style.warningText">
				<strong>{{ t('mobilesheetsmanager', 'Notice:') }}</strong>
				{{ t('mobilesheetsmanager', '{count} file(s) will be detached from the song (kept in storage) when you click "{action}".', { count: pendingDetachFiles.length, action: saveActionLabel }) }}
			</div>
			<ul :class="$style.pendingList">
				<li v-for="file in pendingDetachFiles" :key="file.id" :class="$style.pendingItem">
					<span :class="$style.pendingName">{{ getFileName(file.path) }}</span>
					<NcButton
						type="button"
						variant="tertiary"
						size="small"
						@click="emit('restoreDetach', file)">
						{{ t('mobilesheetsmanager', 'Restore') }}
					</NcButton>
				</li>
			</ul>
		</div>

		<!-- Staged additions notice -->
		<div v-if="pendingAdditions.length > 0" :class="$style.additionNotice">
			<div :class="$style.warningText">
				<strong>{{ t('mobilesheetsmanager', 'Notice:') }}</strong>
				{{ t('mobilesheetsmanager', '{count} file(s) will be added to the song when you click "{action}".', { count: pendingAdditions.length, action: saveActionLabel }) }}
			</div>
			<ul :class="$style.pendingList">
				<li v-for="addition in pendingAdditions" :key="addition.key" :class="$style.pendingAdditionItem">
					<span :class="$style.pendingAdditionText">
						{{ addition.name }}
						<span :class="$style.pendingAdditionHint">{{ addition.hint }}</span>
					</span>
					<NcButton
						type="button"
						variant="tertiary"
						size="small"
						@click="emit('removeAddition', addition)">
						{{ t('mobilesheetsmanager', 'Remove') }}
					</NcButton>
				</li>
			</ul>
		</div>
	</div>
</template>

<style module>
.deletionWarning {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	background: var(--color-background-dark);
	border-left: 4px solid var(--color-warning);
	border-radius: var(--border-radius, 4px);
	margin-bottom: 10px;
}

.detachNotice {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	background: var(--color-background-dark);
	border-left: 4px solid var(--color-primary-element);
	border-radius: var(--border-radius, 4px);
	margin-bottom: 10px;
}

.warningText {
	font-size: 0.9rem;
}

.additionNotice {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	background-color: var(--color-background-dark);
	border-left: 4px solid var(--color-primary);
	border-radius: var(--border-radius, 4px);
}

.pendingList {
	margin: 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.pendingItem {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
}

.pendingName {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.pendingAdditionItem {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	font-size: 0.85rem;
}

.pendingAdditionText {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
}

.pendingAdditionHint {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}
</style>
