<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import WrenchIcon from 'vue-material-design-icons/Wrench.vue'
import { apiGet, apiPost } from '../api/client'
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

function openOrphanFixer(issue: IntegrityIssue) {
	activeOrphanIssue.value = issue
}

function handleOrphanResolved() {
	activeOrphanIssue.value = null
	auditIntegrity()
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
			if (type === 'invalid_naming_rule') title = t('mobilesheetsmanager', 'Invalid Naming Rules')
			else if (type === 'duplicate_file_path') title = t('mobilesheetsmanager', 'Duplicate File Paths')
			else if (type === 'missing_file') title = t('mobilesheetsmanager', 'Missing Files')
			else if (type === 'orphan_file') title = t('mobilesheetsmanager', 'Orphan Files')
			else if (type === 'identical_song') title = t('mobilesheetsmanager', 'Identical Songs (Duplicates)')
			else if (type === 'pdf_metadata_mismatch') title = t('mobilesheetsmanager', 'PDF Metadata Mismatches')
			else if (type === 'pdf_metadata_unreadable') title = t('mobilesheetsmanager', 'PDF Metadata Could Not Be Verified')

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
async function auditIntegrity() {
	if (props.selectedLibrary < 0) return

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

watch(() => props.selectedLibrary, (newLib) => {
	if (newLib >= 0) {
		auditIntegrity()
	}
}, { immediate: true })

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
			integrityError.value = result.message ?? t('mobilesheetsmanager', 'Failed to fix integrity issues.')
		} else {
			await auditIntegrity()
		}
	} catch (error) {
		console.error('Failed to batch fix integrity issues:', error)
		const serverMessage = axios.isAxiosError(error)
			? error.response?.data?.ocs?.data?.message
			: undefined
		integrityError.value = serverMessage
			?? t('mobilesheetsmanager', 'Failed to batch fix integrity issues. Please try again.')
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
			<ViewHeader :title="t('mobilesheetsmanager', 'Integrity Audit')">
				<template #actions>
					<NcButton
						variant="secondary"
						:disabled="isCheckingIntegrity"
						@click="auditIntegrity">
						<template #icon>
							<RefreshIcon :size="20" />
						</template>
						{{ t('mobilesheetsmanager', 'Re-audit Library') }}
					</NcButton>
				</template>
			</ViewHeader>

			<div :class="$style.content">
				<div v-if="isCheckingIntegrity" :class="$style.loading">
					<NcLoadingIcon :size="36" />
					<span>{{ t('mobilesheetsmanager', 'Auditing library files and database integrity…') }}</span>
				</div>

				<template v-else>
					<div v-if="integrityError" :class="$style.error" role="alert">
						{{ integrityError }}
					</div>

					<div v-if="integrityIssues.length === 0 && !integrityError" :class="$style.cleanNotice">
						<h2>{{ t('mobilesheetsmanager', 'All System Checks Passed!') }}</h2>
						<p>{{ t('mobilesheetsmanager', 'No missing files, orphan database records, or duplicate path conflicts were detected in this MobileSheets library.') }}</p>
					</div>

					<div v-else-if="integrityIssues.length > 0" :class="$style.issueContainer">
						<div :class="$style.summaryHeader">
							<span :class="$style.issueCount">
								{{ t('mobilesheetsmanager', '{count} issue(s) detected requiring attention', { count: integrityIssues.length }) }}
							</span>
							<NcButton
								v-if="fixableCount > 0"
								variant="primary"
								:disabled="isBatchFixing"
								@click="fixAllIssues()">
								<template #icon>
									<WrenchIcon :size="18" />
								</template>
								{{ isBatchFixing ? t('mobilesheetsmanager', 'Fixing All…') : t('mobilesheetsmanager', 'Fix All Fixable Issues ({count})', { count: fixableCount }) }}
							</NcButton>
						</div>

						<div v-for="group in groupedIssues" :key="group.type" :class="$style.groupCard">
							<div :class="$style.groupHeader">
								<div :class="$style.groupTitleArea" @click="toggleGroup(group.type)">
									<NcButton
										variant="tertiary"
										size="small"
										:aria-label="isGroupCollapsed(group.type) ? t('mobilesheetsmanager', 'Expand group') : t('mobilesheetsmanager', 'Collapse group')">
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
									{{ t('mobilesheetsmanager', 'Fix All in Group ({count})', { count: group.fixableCount }) }}
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
	</div>
</template>

<style module>
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
