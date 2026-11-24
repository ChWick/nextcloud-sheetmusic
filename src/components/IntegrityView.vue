<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import WrenchIcon from 'vue-material-design-icons/Wrench.vue'
import { apiGet, apiPost } from '../api/client'
import { describeRequestFailure } from '../utils/api'
import type { IntegrityIssue, LibraryData } from '../types/song'
import ViewHeader from './ViewHeader.vue'
import ProgressDialog from './ProgressDialog.vue'
import IntegrityIssueGroupCard from './files/IntegrityIssueGroupCard.vue'
import SongMergeDialog, { type ResolveBothSongData } from './song-editor/SongMergeDialog.vue'
import OrphanFileFixer from './OrphanFileFixer.vue'
import { formatAuditDuration, groupIntegrityIssues, keyForIssue } from '../utils/integrity'
import { useProgressPolling } from '../composables/useProgressPolling'

const props = defineProps<{
	selectedLibrary: number
}>()

const router = useRouter()

// Integrity Audit state
const isCheckingIntegrity = ref(false)
const {
	progress: auditProgress,
	elapsedSeconds: auditElapsedTime,
	start: startAuditPolling,
	stop: stopAuditProgressPolling,
} = useProgressPolling({ trackElapsed: true })

const isBatchFixing = ref(false)
const {
	progress: fixProgress,
	start: startFixPolling,
	stop: stopProgressPolling,
} = useProgressPolling()

function startAuditProgressPolling(progressId: string) {
	startAuditPolling(progressId, t('sheetmusic', 'Auditing library files and database integrity…'))
}

function startProgressPolling(progressId: string) {
	startFixPolling(progressId, t('sheetmusic', 'Starting integrity fixes…'))
}

const fixingKey = ref<string | null>(null)
const integrityError = ref('')
const integrityIssues = ref<IntegrityIssue[]>([])
const collapsedGroups = ref<Record<string, boolean>>({})
const activeOrphanIssue = ref<IntegrityIssue | null>(null)
const autoMatchOrphans = ref(true)

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

const groupedIssues = computed(() => groupIntegrityIssues(integrityIssues.value))

/** Run integrity audit check for the selected library */
async function auditIntegrity(keepCurrentIssues = false) {
	if (props.selectedLibrary < 0) return

	isCheckingIntegrity.value = true
	integrityError.value = ''
	if (!keepCurrentIssues) {
		integrityIssues.value = []
	}

	const progressId = 'audit-' + Date.now() + '-' + Math.random().toString(36).substring(2, 9)
	startAuditProgressPolling(progressId)

	try {
		const queryParams: Record<string, string> = {
			progressId,
		}
		if (!autoMatchOrphans.value) {
			queryParams.autoMatchOrphans = '0'
		}
		const response = await apiGet(`/api/libraries/${props.selectedLibrary}/integrity` as any, { queryParams })
		stopAuditProgressPolling()
		integrityIssues.value = response.data?.ocs?.data?.issues ?? []
	} catch (error) {
		stopAuditProgressPolling()
		console.error('Failed to audit library integrity:', error)
		const serverMessage = describeRequestFailure(error, t('sheetmusic', 'Failed to audit library integrity. Please try again.'))
		integrityError.value = serverMessage
		showError(serverMessage)
	} finally {
		isCheckingIntegrity.value = false
		stopAuditProgressPolling()
		auditProgress.value = null
	}
}

watch(autoMatchOrphans, () => {
	auditIntegrity(true)
})

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
	const progressId = 'fix-' + Date.now() + '-' + Math.random().toString(36).substring(2, 9)
	startProgressPolling(progressId)

	try {
		const response = await apiPost(`/api/libraries/${props.selectedLibrary}/integrity/fix-all` as any, {
			issueType,
			progressId,
		} as any)
		stopProgressPolling()
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
				showSuccess(t('sheetmusic', 'Fixed {count} integrity issue(s).', { count: fixedCount }, { escape: false }))
			}
		}
	} catch (error) {
		stopProgressPolling()
		console.error('Failed to batch fix integrity issues:', error)
		const serverMessage = describeRequestFailure(error, t('sheetmusic', 'Failed to batch fix integrity issues. Please try again.'))
		integrityError.value = serverMessage
		showError(serverMessage)
	} finally {
		isBatchFixing.value = false
		stopProgressPolling()
		fixProgress.value = null
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
				showSuccess(t('sheetmusic', 'Naming rule applied for "{title}".', { title: songTitle }, { escape: false }))
			} else if (issue.type === 'orphan_file' && songTitle) {
				showSuccess(t('sheetmusic', 'Linked orphan file to "{title}".', { title: songTitle }, { escape: false }))
			} else if (issue.type === 'missing_file' && songTitle) {
				showSuccess(t('sheetmusic', 'Deleted missing file from "{title}".', { title: songTitle }, { escape: false }))
			} else if (songTitle) {
				showSuccess(t('sheetmusic', 'Fixed integrity issue for "{title}".', { title: songTitle }, { escape: false }))
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
		await apiPost('/api/songs/merge', {
			libraryId: props.selectedLibrary,
			keepId: payload.keepId,
			droppedId: payload.droppedId,
			merged: payload.merged,
		})
		isMergeDialogOpen.value = false
		const keepTitle = (payload.merged?.title as string | undefined) ?? mergeIssue.value?.songTitle
		if (mergingKey.value) {
			const mKey = mergingKey.value
			integrityIssues.value = integrityIssues.value.filter(i => keyForIssue(i) !== mKey)
		}
		mergeIssue.value = null
		if (keepTitle) {
			showSuccess(t('sheetmusic', 'Songs merged successfully into "{title}".', { title: keepTitle }, { escape: false }))
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
				<NcCheckboxRadioSwitch
					v-model="autoMatchOrphans"
					type="switch"
					:disabled="isCheckingIntegrity">
					{{ t('sheetmusic', 'Automatically match orphan files') }}
				</NcCheckboxRadioSwitch>
				<NcButton
					variant="secondary"
					:disabled="isCheckingIntegrity"
					@click="auditIntegrity(true)">
					<template #icon>
						<RefreshIcon :size="20" />
					</template>
					{{ t('sheetmusic', 'Re-audit Library') }}
				</NcButton>
			</ViewHeader>

			<div :class="$style.content">
				<div v-if="isCheckingIntegrity && integrityIssues.length === 0" :class="$style.auditProgressContainer" data-test="audit-progress-container">
					<div :class="$style.progressHeader">
						<span :class="$style.auditMessage" data-test="audit-progress-message">
							{{ auditProgress?.message || t('sheetmusic', 'Auditing library files and database integrity…') }}
						</span>
						<span v-if="auditElapsedTime > 0" :class="$style.elapsedBadge" data-test="audit-elapsed-time">
							{{ formatAuditDuration(auditElapsedTime) }}
						</span>
					</div>
					<NcProgressBar
						:value="auditProgress ? auditProgress.percent : 0"
						size="medium"
						:show-value="true"
						:error="auditProgress?.status === 'error'" />
				</div>

				<template v-else>
					<div v-if="isCheckingIntegrity && integrityIssues.length > 0" :class="$style.reloadingNotice" data-test="audit-reloading-notice">
						<div :class="$style.reloadingHeader">
							<NcLoadingIcon :size="18" />
							<span :class="$style.auditMessage">
								{{ auditProgress?.message || t('sheetmusic', 'Auditing library files and database integrity…') }}
							</span>
							<span v-if="auditElapsedTime > 0" :class="$style.elapsedBadge">
								{{ formatAuditDuration(auditElapsedTime) }}
							</span>
						</div>
						<NcProgressBar
							:value="auditProgress ? auditProgress.percent : 0"
							size="small"
							:show-value="true"
							:error="auditProgress?.status === 'error'" />
					</div>

					<div v-if="integrityIssues.length === 0" :class="$style.cleanNotice">
						<h2>{{ t('sheetmusic', 'All System Checks Passed!') }}</h2>
						<p>{{ t('sheetmusic', 'No missing files, orphan database records, or duplicate path conflicts were detected in this MobileSheets library.') }}</p>
					</div>

					<div v-else-if="integrityIssues.length > 0" :class="$style.issueContainer">
						<div :class="$style.summaryHeader">
							<span :class="$style.issueCount">
								{{ t('sheetmusic', '{count} issue(s) detected requiring attention', { count: integrityIssues.length }, { escape: false }) }}
							</span>
							<NcButton
								v-if="fixableCount > 0"
								variant="primary"
								:disabled="isBatchFixing"
								@click="fixAllIssues()">
								<template #icon>
									<WrenchIcon :size="18" />
								</template>
								{{ isBatchFixing ? t('sheetmusic', 'Fixing All…') : t('sheetmusic', 'Fix All Fixable Issues ({count})', { count: fixableCount }, { escape: false }) }}
							</NcButton>
						</div>

						<IntegrityIssueGroupCard
							v-for="group in groupedIssues"
							:key="group.type"
							:group="group"
							:collapsed="isGroupCollapsed(group.type)"
							:is-batch-fixing="isBatchFixing"
							:fixing-key="fixingKey"
							:merging-key="mergingKey"
							@toggle="toggleGroup"
							@fix-group="fixAllIssues"
							@open-song="openSong"
							@fix-issue="fixIntegrityIssue"
							@merge-songs="openMergeDialog"
							@fix-orphan-file="openOrphanFixer"
							@delete-missing-file="fixIntegrityIssue" />
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

		<ProgressDialog
			:open="isBatchFixing"
			:title="t('sheetmusic', 'Fixing issues…')"
			:percent="fixProgress ? fixProgress.percent : 0"
			:message="fixProgress?.message ?? ''"
			:fallback-message="t('sheetmusic', 'Fixing integrity issues in library…')"
			:error="fixProgress?.status === 'error'" />
	</div>
</template>

<style module>
.auditProgressContainer {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 16px;
	padding: 48px 24px;
	width: 100%;
	max-width: 560px;
	margin: 0 auto;
	box-sizing: border-box;
}

.progressHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	width: 100%;
	gap: 12px;
}

.auditMessage {
	font-size: 0.95rem;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.elapsedBadge {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	background-color: var(--color-background-hover);
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 12px);
	white-space: nowrap;
	font-variant-numeric: tabular-nums;
}

.reloadingNotice {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px 16px;
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	color: var(--color-text-maxcontrast);
	font-size: 0.95rem;
}

.reloadingHeader {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
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
		color: var(--color-text-success);
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
</style>
