<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import History from 'vue-material-design-icons/History.vue'
import BackupRestore from 'vue-material-design-icons/BackupRestore.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import type { LibraryData } from '../types/song'

interface HistoryEntry {
	id: number
	libraryId: number
	songId: number
	songUuid: string
	userId: string | null
	action: 'created' | 'updated' | 'synced' | 'deleted' | 'reverted' | 'restored'
	summary: string | null
	snapshot: Record<string, any>
	createdAt: number
}

const props = defineProps<{
	open: boolean
	songId: number
	songTitle?: string
}>()

const emit = defineEmits<{
	(e: 'update:open', val: boolean): void
	(e: 'reverted', data: LibraryData): void
}>()

const isLoading = ref(false)
const isReverting = ref(false)
const historyList = ref<HistoryEntry[]>([])
const expandedId = ref<number | null>(null)
const error = ref('')

async function fetchHistory() {
	if (!props.songId) return
	isLoading.value = true
	error.value = ''
	try {
		const url = generateOcsUrl('/apps/sheetmusic/api/songs/' + props.songId + '/history')
		const response = await axios.get(url)
		historyList.value = response.data?.ocs?.data ?? []
	} catch (e: any) {
		error.value = e?.message || t('sheetmusic', 'Failed to load song history')
	} finally {
		isLoading.value = false
	}
}

watch(
	() => props.open,
	(isOpen) => {
		if (isOpen) {
			fetchHistory()
		}
	},
	{ immediate: true },
)

function toggleExpand(id: number) {
	expandedId.value = expandedId.value === id ? null : id
}

function formatDate(sec: number): string {
	if (!sec) return ''
	const d = new Date(sec * 1000)
	return d.toLocaleString()
}

function getActionLabel(action: string): string {
	switch (action) {
	case 'created':
		return t('sheetmusic', 'Created')
	case 'updated':
		return t('sheetmusic', 'Modified')
	case 'synced':
		return t('sheetmusic', 'Synced')
	case 'reverted':
		return t('sheetmusic', 'Reverted')
	case 'restored':
		return t('sheetmusic', 'Restored')
	case 'deleted':
		return t('sheetmusic', 'Deleted')
	default:
		return action
	}
}

async function revertTo(entry: HistoryEntry) {
	if (!confirm(t('sheetmusic', 'Are you sure you want to revert this song to the version from {date}?', { date: formatDate(entry.createdAt) }))) {
		return
	}

	isReverting.value = true
	try {
		const url = generateOcsUrl('/apps/sheetmusic/api/songs/' + props.songId + '/revert/' + entry.id)
		const response = await axios.post(url)
		const data = response.data?.ocs?.data as LibraryData
		showSuccess(t('sheetmusic', 'Song reverted to revision from {date}', { date: formatDate(entry.createdAt) }))
		emit('reverted', data)
		emit('update:open', false)
	} catch (e: any) {
		showError(e?.response?.data?.ocs?.data?.error || t('sheetmusic', 'Failed to revert song'))
	} finally {
		isReverting.value = false
	}
}
</script>

<template>
	<NcDialog
		v-if="open"
		:name="t('sheetmusic', 'Song History & Revisions: {title}', { title: songTitle || t('sheetmusic', 'Song') })"
		size="large"
		@close="emit('update:open', false)">
		<div :class="$style.container">
			<div v-if="isLoading" :class="$style.loading">
				<NcLoadingIcon :size="32" />
				<p>{{ t('sheetmusic', 'Loading history...') }}</p>
			</div>

			<p v-else-if="error" :class="$style.error" role="alert">
				{{ error }}
			</p>

			<div v-else-if="historyList.length === 0" :class="$style.empty">
				<History :size="48" :class="$style.emptyIcon" />
				<p>{{ t('sheetmusic', 'No revisions recorded for this song yet.') }}</p>
			</div>

			<div v-else :class="$style.timeline">
				<div
					v-for="(entry, index) in historyList"
					:key="entry.id"
					:class="[$style.timelineItem, index === 0 ? $style.isLatest : '']">
					<div :class="$style.headerRow">
						<div :class="$style.badgeWrapper">
							<span :class="[$style.actionBadge, $style['badge_' + entry.action]]">
								<Plus v-if="entry.action === 'created'" :size="14" />
								<Sync v-else-if="entry.action === 'synced'" :size="14" />
								<History v-else-if="entry.action === 'reverted'" :size="14" />
								<Pencil v-else :size="14" />
								{{ getActionLabel(entry.action) }}
							</span>
							<span v-if="index === 0" :class="$style.currentBadge">
								{{ t('sheetmusic', 'Current') }}
							</span>
						</div>

						<div :class="$style.meta">
							<span :class="$style.date">{{ formatDate(entry.createdAt) }}</span>
							<span v-if="entry.userId" :class="$style.user">
								{{ t('sheetmusic', 'by {user}', { user: entry.userId }) }}
							</span>
						</div>

						<div :class="$style.actions">
							<NcButton
								type="button"
								variant="tertiary"
								:title="expandedId === entry.id ? t('sheetmusic', 'Hide details') : t('sheetmusic', 'Show details')"
								@click="toggleExpand(entry.id)">
								<template #icon>
									<ChevronUp v-if="expandedId === entry.id" :size="18" />
									<ChevronDown v-else :size="18" />
								</template>
							</NcButton>

							<NcButton
								v-if="index > 0"
								type="button"
								variant="primary"
								:disabled="isReverting"
								:title="t('sheetmusic', 'Revert to this version')"
								@click="revertTo(entry)">
								<template #icon>
									<BackupRestore :size="16" />
								</template>
								{{ t('sheetmusic', 'Revert') }}
							</NcButton>
						</div>
					</div>

					<div v-if="entry.summary" :class="$style.summary">
						{{ entry.summary }}
					</div>

					<!-- Expanded Snapshot Details -->
					<div v-if="expandedId === entry.id" :class="$style.details">
						<div :class="$style.detailGrid">
							<div :class="$style.detailItem">
								<strong>{{ t('sheetmusic', 'Title') }}:</strong> {{ entry.snapshot.title }}
							</div>
							<div v-if="entry.snapshot.opus" :class="$style.detailItem">
								<strong>{{ t('sheetmusic', 'Opus') }}:</strong> {{ entry.snapshot.opus }}
							</div>
							<div v-if="entry.snapshot.catalogNumber" :class="$style.detailItem">
								<strong>{{ t('sheetmusic', 'Catalog #') }}:</strong> {{ entry.snapshot.catalogNumber }}
							</div>
							<div :class="$style.detailItem">
								<strong>{{ t('sheetmusic', 'Rating') }}:</strong> {{ entry.snapshot.rating }}★
							</div>
							<div :class="$style.detailItem">
								<strong>{{ t('sheetmusic', 'Difficulty') }}:</strong> {{ entry.snapshot.difficulty }}
							</div>
							<div v-if="entry.snapshot.duration" :class="$style.detailItem">
								<strong>{{ t('sheetmusic', 'Duration') }}:</strong> {{ entry.snapshot.duration }}s
							</div>
						</div>

						<!-- Entities in snapshot -->
						<div v-if="entry.snapshot.entities" :class="$style.entitiesSection">
							<div v-for="(ents, type) in entry.snapshot.entities" :key="type" :class="$style.entityRow">
								<span :class="$style.entityType">{{ type }}:</span>
								<span :class="$style.entityList">
									{{ ents.map((e: any) => e.name).join(', ') }}
								</span>
							</div>
						</div>

						<!-- Files in snapshot -->
						<div v-if="entry.snapshot.files && entry.snapshot.files.length" :class="$style.filesSection">
							<strong>{{ t('sheetmusic', 'Attached Files') }} ({{ entry.snapshot.files.length }}):</strong>
							<ul>
								<li v-for="f in entry.snapshot.files" :key="f.id || f.fileId">
									{{ t('sheetmusic', 'File ID {id}, Page Order: {order}', { id: f.fileId, order: f.pageOrder || '1' }) }}
								</li>
							</ul>
						</div>
					</div>
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<style module>
.container {
	min-width: 480px;
	max-width: 650px;
	max-height: 70vh;
	overflow-y: auto;
	padding: 8px 4px;
}

.loading,
.empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 40px 16px;
	color: var(--color-text-maxcontrast);
}

.emptyIcon {
	margin-bottom: 12px;
	opacity: 0.5;
}

.error {
	color: var(--color-error);
	padding: 16px;
}

.timeline {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.timelineItem {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 12px 16px;
	background-color: var(--color-main-background);
	transition: background-color 0.2s ease;
}

.isLatest {
	border-color: var(--color-primary-element);
	background-color: var(--color-background-hover);
}

.headerRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
}

.badgeWrapper {
	display: flex;
	align-items: center;
	gap: 8px;
}

.actionBadge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.8rem;
	font-weight: 600;
	text-transform: capitalize;
}

.badge_created {
	background-color: rgba(46, 204, 113, 0.2);
	color: #27ae60;
}

.badge_updated {
	background-color: rgba(52, 152, 219, 0.2);
	color: #2980b9;
}

.badge_synced {
	background-color: rgba(155, 89, 182, 0.2);
	color: #8e44ad;
}

.badge_reverted,
.badge_restored {
	background-color: rgba(243, 156, 18, 0.2);
	color: #d35400;
}

.badge_deleted {
	background-color: rgba(231, 76, 60, 0.2);
	color: #c0392b;
}

.currentBadge {
	font-size: 0.75rem;
	padding: 2px 6px;
	border-radius: 4px;
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: 600;
}

.meta {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	display: flex;
	gap: 8px;
}

.actions {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-left: auto;
}

.summary {
	margin-top: 8px;
	font-size: 0.9rem;
	color: var(--color-main-text);
	font-weight: 500;
}

.details {
	margin-top: 12px;
	padding-top: 12px;
	border-top: 1px dashed var(--color-border);
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.detailGrid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
	gap: 8px;
	margin-bottom: 8px;
}

.entitiesSection {
	margin-top: 8px;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.entityRow {
	display: flex;
	gap: 8px;
}

.entityType {
	text-transform: capitalize;
	font-weight: 600;
	min-width: 80px;
}

.filesSection {
	margin-top: 8px;
}

.filesSection ul {
	margin: 4px 0 0 16px;
	padding: 0;
}
</style>
