<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import WrenchIcon from 'vue-material-design-icons/Wrench.vue'
import { apiGet, apiPost } from '../api/client'
import { describeRequestFailure } from '../utils/api'
import type { IntegrityIssue, LibraryData } from '../types/song'
import ViewHeader from './ViewHeader.vue'
import FilesIntegrityIssueItem from './files/FilesIntegrityIssueItem.vue'
import SongMergeDialog, { type ResolveBothSongData } from './song-editor/SongMergeDialog.vue'
import OrphanFileFixer from './OrphanFileFixer.vue'
import { keyForIssue } from '../utils/integrity'

const props = defineProps<{
	selectedLibrary: number
}>()

const router = useRouter()

// Integrity Audit state
const isCheckingIntegrity = ref(false)
const isBatchFixing = ref(false)
const fixingKey = ref<string | null>(null)
const integrityError = ref('')
const integrityIssues = ref<IntegrityIssue[]>([])
const collapsedGroups = ref<Record<string, boolean>>({})
const activeOrphanIssue = ref<IntegrityIssue | null>(null)

// Song conflict resolution state
const isMergeDialogOpen = ref(false)
const isMerging = ref(false)
const initialMergeMode = ref<'merge' | 'keepBoth'>('merge')
const mergeIssue = ref<IntegrityIssue | null>(null)
const libraryData = ref<LibraryData | null>(null)
const mergingKey = ref<string | null>(null)

function openOrphanFixer(issue: IntegrityIssue) {
	activeOrphanIssue.value = issue
}

function handleOrphanResolved() {
	if (activeOrphanIssue.value) {
		const key = keyForIssue(activeOrphanIssue.value)
		integrityIssues.value = integrityIssues.value.filter(i => keyForIssue(i) !== key)
		showSuccess(t('sheetmusic', 'Orphan file resolved successfully.'))
	}
	activeOrphanIssue.value = null
}

function toggleGroup(groupType: string) {
	collapsedGroups.value[groupType] = !collapsedGroups.value[groupType]
}

function isGroupCollapsed(groupType: string): boolean {
	return !!collapsedGroups.value[groupType]
}

const fixableCount = computed(() => integrityIssues.value.filter(i => i.fixable).length)

const groupedIssues = computed(() => {
	const groups: Record<string, { type: string; title: string; issues: IntegrityIssue[]; fixableCount: number }> = {}

	for (const issue of integrityIssues.value) {
		const type = issue.type
		if (!groups[type]) {
			let title = type.replace(/_/g, ' ')
			if (type === 'invalid_naming_rule') title = t('sheetmusic', 'Invalid Naming Rules')
			else if (type === 'duplicate_file_path') title = t('sheetmusic', 'Duplicate File Paths')
			else if (type === 'duplicate_entity_assignment') title = t('sheetmusic', 'Duplicate Song Assignments')
			else if (type === 'duplicate_entity') title = t('sheetmusic', 'Duplicate Entities')
			else if (type === 'missing_file') title = t('sheetmusic', 'Missing Files')
			else if (type === 'orphan_file') title = t('sheetmusic', 'Orphan Files')
			else if (type === 'identical_song') title = t('sheetmusic', 'Identical Songs (Duplicates)')
			else if (type === 'pdf_metadata_mismatch') title = t('sheetmusic', 'PDF Metadata Mismatches')
			else if (type === 'pdf_metadata_unreadable') title = t('sheetmusic', 'PDF Metadata Could Not Be Verified')
			else if (type === 'orphan_entity_assignment') title = t('sheetmusic', 'Orphan Entity Assignments')
			else if (type === 'orphan_song_file') title = t('sheetmusic', 'Orphan Song Files')
			else if (type === 'song_without_files') title = t('sheetmusic', 'Songs Without Files')
			else if (type === 'duplicate_client_id') title = t('sheetmusic', 'Duplicate Client IDs')
			else if (type === 'orphan_song_attribute') title = t('sheetmusic', 'Orphan Song Attributes')
			else if (type === 'orphan_misc_data') title = t('sheetmusic', 'Orphan Sync Data')

			groups[type] = {
				type,
				title,
				issues: [],
				fixableCount: 0,
			}
		}

		groups[type].issues.push(issue)
		if (issue.fixable) {
			groups[type].fixableCount++
		}
	}

	return Object.values(groups)
})

/** Run integrity audit check for the selected library */
async function auditIntegrity(keepCurrentIssues = false) {
	if (props.selectedLibrary < 0) return

	isCheckingIntegrity.value = true
	integrityError.value = ''
	if (!keepCurrentIssues) {
		integrityIssues.value = []
	}

	try {
		const response = await apiGet(`/api/libraries/${props.selectedLibrary}/integrity`)
		integrityIssues.value = response.data?.ocs?.data?.issues ?? []
	} catch (error) {
		console.error('Failed to audit library integrity:', error)
		const serverMessage = describeRequestFailure(error, t('sheetmusic', 'Failed to audit library integrity. Please try again.'))
		integrityError.value = serverMessage
		showError(serverMessage)
	} finally {
		isCheckingIntegrity.value = false
	}
}

watch(() => props.selectedLibrary, (newLib) => {
	if (newLib >= 0) {
		auditIntegrity()
	}
}, { immediate: true })

watch(isMergeDialogOpen, (isOpen) => {
	if (!isOpen && !isMerging.value) {
		mergingKey.value = null
		mergeIssue.value = null
	}
})

/** Automatically fix all fixable issues (optionally filtered by type) */
async function fixAllIssues(issueType?: string) {
	if (props.selectedLibrary < 0) return

	isBatchFixing.value = true
	integrityError.value = ''

	try {
		const response = await apiPost(`/api/libraries/${props.selectedLibrary}/integrity/fix-all`, {
			issueType,
		})
		const result = response.data?.ocs?.data
		if (result && !result.success) {
			const errorMsg = result.message ?? t('sheetmusic', 'Failed to fix integrity issues.')
			integrityError.value = errorMsg
			showError(errorMsg)
		} else {
			const fixedCount = result?.fixedCount ?? 0
			if (fixedCount > 0) {
				if (issueType) {
					integrityIssues.value = integrityIssues.value.filter(i => i.type !== issueType || !i.fixable)
				} else {
					integrityIssues.value = integrityIssues.value.filter(i => !i.fixable)
				}
			}
			if (result?.message && result.message.includes('failed:')) {
				showWarning(result.message)
			} else {
				showSuccess(t('sheetmusic', 'Fixed {count} integrity issue(s).', { count: fixedCount }))
			}
		}
	} catch (error) {
		console.error('Failed to batch fix integrity issues:', error)
		const serverMessage = describeRequestFailure(error, t('sheetmusic', 'Failed to batch fix integrity issues. Please try again.'))
		integrityError.value = serverMessage
		showError(serverMessage)
	} finally {
		isBatchFixing.value = false
	}
}

/** Automatically fix a specific fixable integrity issue */
async function fixIntegrityIssue(issue: IntegrityIssue) {
	const key = keyForIssue(issue)
	fixingKey.value = key
	integrityError.value = ''

	try {
		const response = await apiPost(`/api/libraries/${props.selectedLibrary}/integrity/fix`, {
			issueType: issue.type,
			songId: issue.songId,
			fileId: issue.fileId,
			entityType: issue.details?.entityType,
			entityId: issue.details?.keepId,
			path: issue.path,
		})
		const result = response.data?.ocs?.data
		if (result && !result.success) {
			const errorMsg = result.message ?? t('sheetmusic', 'Failed to fix integrity issue.')
			integrityError.value = errorMsg
			showError(errorMsg)
		} else {
			integrityIssues.value = integrityIssues.value.filter(i => keyForIssue(i) !== key)
			const songTitle = issue.songTitle
			if (issue.type === 'invalid_naming_rule' && songTitle) {
				showSuccess(t('sheetmusic', 'Naming rule applied for "{title}".', { title: songTitle }))
			} else if (issue.type === 'orphan_file' && songTitle) {
				showSuccess(t('sheetmusic', 'Linked orphan file to "{title}".', { title: songTitle }))
			} else if (songTitle) {
				showSuccess(t('sheetmusic', 'Fixed integrity issue for "{title}".', { title: songTitle }))
			} else {
				showSuccess(result?.message ?? t('sheetmusic', 'Integrity issue fixed successfully.'))
			}
		}
	} catch (error) {
		console.error('Failed to fix integrity issue:', error)
		const serverMessage = describeRequestFailure(error, t('sheetmusic', 'Failed to fix integrity issue. Please try again.'))
		integrityError.value = serverMessage
		showError(serverMessage)
	} finally {
		fixingKey.value = null
	}
}

/** Open the merge / conflict resolution dialog for duplicate-file-path issues */
async function openMergeDialog(issue: IntegrityIssue, mode: 'merge' | 'keepBoth' = 'merge') {
	mergeIssue.value = issue
	initialMergeMode.value = mode
	mergingKey.value = keyForIssue(issue)

	try {
		const response = await apiGet('/api/songs', { queryParams: { id: String(props.selectedLibrary) } })
		libraryData.value = response.data.ocs.data as LibraryData
	} catch {
		// fall back to showing the dialog without fresh data
	}
	isMergeDialogOpen.value = true
}

/** Execute merge (combine into 1 song) via API and re-audit */
async function executeMerge(payload: {
	keepId: number
	droppedId: number
	merged: Record<string, unknown>
}) {
	isMerging.value = true
	integrityError.value = ''
	try {
		await axios.post(
			`/ocs/v2.php/apps/sheetmusic/api/songs/merge`,
			{
				libraryId: props.selectedLibrary,
				keepId: payload.keepId,
				droppedId: payload.droppedId,
				merged: payload.merged,
			},
			{ headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' } },
		)
		isMergeDialogOpen.value = false
		const keepTitle = (payload.merged?.title as string | undefined) ?? mergeIssue.value?.songTitle
		if (mergingKey.value) {
			const mKey = mergingKey.value
			integrityIssues.value = integrityIssues.value.filter(i => keyForIssue(i) !== mKey)
		}
		mergeIssue.value = null
		if (keepTitle) {
			showSuccess(t('sheetmusic', 'Songs merged successfully into "{title}".', { title: keepTitle }))
		} else {
			showSuccess(t('sheetmusic', 'Songs merged successfully.'))
		}
	} catch (error) {
		console.error('Failed to merge songs:', error)
		const serverMessage = describeRequestFailure(error, t('sheetmusic', 'Failed to merge songs. Please try again.'))
		integrityError.value = serverMessage
		showError(serverMessage)
	} finally {
		isMerging.value = false
		mergingKey.value = null
	}
}

/** Execute resolveBoth (keep both songs as separate entities) and re-audit */
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
		if (mergingKey.value) {
			const mKey = mergingKey.value
			integrityIssues.value = integrityIssues.value.filter(i => keyForIssue(i) !== mKey)
		}
		mergeIssue.value = null
		showSuccess(t('sheetmusic', 'Both songs saved successfully.'))
	} catch (error: any) {
		console.error('Failed to resolve both songs:', error)
		const serverMessage = describeRequestFailure(error, t('sheetmusic', 'Failed to save updated songs. Please try again.'))
		integrityError.value = serverMessage
		showError(serverMessage)
	} finally {
		isMerging.value = false
		mergingKey.value = null
	}
}

/** Navigate to song editor */
function openSong(songId: number) {
	if (songId <= 0) return
	router.push({
		name: 'LibraryView',
		params: { library: String(props.selectedLibrary), view: 'songs' },
		query: { song: String(songId), edit: '1' },
	})
}
</script>

<template>
	<div :class="$style.container">
		<OrphanFileFixer
			v-if="activeOrphanIssue"
			:selected-library="selectedLibrary"
			:orphan-path="activeOrphanIssue.path"
			:issue="activeOrphanIssue"
			@back="activeOrphanIssue = null"
			@resolved="handleOrphanResolved" />

		<template v-else>
			<ViewHeader :title="t('sheetmusic', 'Integrity Audit')">
				<template #actions>
					<NcButton
						variant="secondary"
						:disabled="isCheckingIntegrity"
						@click="auditIntegrity(true)">
						<template #icon>
							<RefreshIcon :size="20" />
						</template>
						{{ t('sheetmusic', 'Re-audit Library') }}
					</NcButton>
				</template>
			</ViewHeader>

			<div :class="$style.content">
				<div v-if="isCheckingIntegrity && integrityIssues.length === 0" :class="$style.loading">
					<NcLoadingIcon :size="36" />
					<span>{{ t('sheetmusic', 'Auditing library files and database integrity…') }}</span>
				</div>

				<template v-else>
					<div v-if="isCheckingIntegrity && integrityIssues.length > 0" :class="$style.reloadingNotice">
						<NcLoadingIcon :size="20" />
						<span>{{ t('sheetmusic', 'Auditing library files and database integrity…') }}</span>
					</div>

					<div v-if="integrityIssues.length === 0" :class="$style.cleanNotice">
						<h2>{{ t('sheetmusic', 'All System Checks Passed!') }}</h2>
						<p>{{ t('sheetmusic', 'No missing files, orphan database records, or duplicate path conflicts were detected in this MobileSheets library.') }}</p>
					</div>

					<div v-else-if="integrityIssues.length > 0" :class="$style.issueContainer">
						<div :class="$style.summaryHeader">
							<span :class="$style.issueCount">
								{{ t('sheetmusic', '{count} issue(s) detected requiring attention', { count: integrityIssues.length }) }}
							</span>
							<NcButton
								v-if="fixableCount > 0"
								variant="primary"
								:disabled="isBatchFixing"
								@click="fixAllIssues()">
								<template #icon>
									<WrenchIcon :size="18" />
								</template>
								{{ isBatchFixing ? t('sheetmusic', 'Fixing All…') : t('sheetmusic', 'Fix All Fixable Issues ({count})', { count: fixableCount }) }}
							</NcButton>
						</div>

						<div v-for="group in groupedIssues" :key="group.type" :class="$style.groupCard">
							<div :class="$style.groupHeader">
								<div :class="$style.groupTitleArea" @click="toggleGroup(group.type)">
									<NcButton
										variant="tertiary"
										size="small"
										:aria-label="isGroupCollapsed(group.type) ? t('sheetmusic', 'Expand group') : t('sheetmusic', 'Collapse group')">
										<template #icon>
											<ChevronRightIcon v-if="isGroupCollapsed(group.type)" :size="20" />
											<ChevronDownIcon v-else :size="20" />
										</template>
									</NcButton>
									<h3 :class="$style.groupTitle">{{ group.title }}</h3>
									<span :class="$style.groupBadge">{{ group.issues.length }}</span>
								</div>
								<NcButton
									v-if="group.fixableCount > 0"
									variant="secondary"
									size="small"
									:disabled="isBatchFixing"
									@click.stop="fixAllIssues(group.type)">
									<template #icon>
										<WrenchIcon :size="16" />
									</template>
									{{ t('sheetmusic', 'Fix All in Group ({count})', { count: group.fixableCount }) }}
								</NcButton>
							</div>

							<ul v-if="!isGroupCollapsed(group.type)" :class="$style.issueList">
								<FilesIntegrityIssueItem
									v-for="issue in group.issues"
									:key="keyForIssue(issue)"
									:issue="issue"
									:is-fixing="fixingKey === keyForIssue(issue) || mergingKey === keyForIssue(issue)"
									@open-song="openSong"
									@fix-issue="fixIntegrityIssue"
									@merge-songs="openMergeDialog"
									@fix-orphan-file="openOrphanFixer" />
							</ul>
						</div>
					</div>
				</template>
			</div>
		</template>

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

		<NcDialog
			v-if="isBatchFixing"
			:name="t('sheetmusic', 'Fixing issues…')"
			size="small">
			<div :class="$style.dialogLoading">
				<NcLoadingIcon :size="36" />
				<span>{{ t('sheetmusic', 'Fixing integrity issues in library…') }}</span>
			</div>
		</NcDialog>
	</div>
</template>

<style module>
.dialogLoading {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 16px;
	padding: 24px;
	text-align: center;
}

.reloadingNotice {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 16px;
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	color: var(--color-text-maxcontrast);
	font-size: 0.95rem;
}

.container {
	display: flex;
	flex-direction: column;
	width: 100%;
	min-height: 100%;
}

.content {
	padding: 16px 24px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 16px;
	padding: 64px 16px;
	color: var(--color-text-maxcontrast);
	font-size: 1.1rem;
}

.error {
	padding: 16px;
	background-color: var(--color-error-background, rgba(229, 57, 53, 0.1));
	border: 1px solid var(--color-error);
	border-radius: var(--border-radius-large, 8px);
	color: var(--color-error);
	font-weight: 500;
}

.cleanNotice {
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
	padding: 64px 24px;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);

	h2 {
		margin: 0 0 12px;
		color: var(--color-success);
	}

	p {
		margin: 0;
		max-width: 540px;
		color: var(--color-text-maxcontrast);
		line-height: 1.5;
	}
}

.issueContainer {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.summaryHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.groupCard {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.groupHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.groupTitleArea {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
	user-select: none;
}

.groupTitle {
	margin: 0;
	font-size: 1.05rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.groupBadge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 2px 8px;
	border-radius: 12px;
	background-color: var(--color-background-hover);
	font-size: 0.85rem;
	font-weight: 600;
}

.issueList {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
}
</style>
