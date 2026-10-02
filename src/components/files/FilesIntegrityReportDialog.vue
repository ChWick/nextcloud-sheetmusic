<script setup lang="ts">
import type { IntegrityIssue } from '../../types/song'
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import FilesIntegrityIssueItem from './FilesIntegrityIssueItem.vue'

const props = defineProps<{
	open: boolean
	isChecking: boolean
	error: string
	issues: IntegrityIssue[]
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	openSong: [songId: number]
	fixIssue: [issue: IntegrityIssue]
	mergeSongs: [issue: IntegrityIssue]
	recheck: []
}>()

const fixingKey = ref<string | null>(null)
const mergingKey = ref<string | null>(null)

function keyForIssue(issue: IntegrityIssue): string {
	return `${issue.type}-${issue.songId ?? 0}-${issue.fileId ?? 0}-${issue.path ?? ''}`
}

function handleFix(issue: IntegrityIssue) {
	fixingKey.value = keyForIssue(issue)
	emit('fixIssue', issue)
}

function handleMerge(issue: IntegrityIssue) {
	mergingKey.value = keyForIssue(issue)
	emit('mergeSongs', issue)
}

/** Called by parent after merge dialog closes to clear the merging state. */
function clearMergingState() {
	mergingKey.value = null
}

defineExpose({ clearMergingState })
</script>

<template>
	<NcDialog
		v-if="open"
		:name="t('mobilesheetsmanager', 'Library Integrity & Health Check')"
		size="large"
		@close="emit('update:open', false)">
		<div v-if="isChecking" :class="$style.loading">
			<NcLoadingIcon :size="32" />
			<span>{{ t('mobilesheetsmanager', 'Auditing library files and database integrity…') }}</span>
		</div>

		<template v-else>
			<p v-if="error" :class="$style.error" role="alert">{{ error }}</p>

			<template v-else>
				<div :class="$style.summary">
					<div v-if="issues.length === 0" :class="$style.cleanNotice">
						<h3>{{ t('mobilesheetsmanager', 'All System Checks Passed!') }}</h3>
						<p>{{ t('mobilesheetsmanager', 'No missing files, orphan records, or filename conflicts were detected in this library.') }}</p>
					</div>

					<div v-else :class="$style.issueStats">
						<span :class="$style.statChip">
							<strong>{{ issues.length }}</strong> {{ t('mobilesheetsmanager', 'Issues Found') }}
						</span>
						<NcButton
							variant="secondary"
							@click="emit('recheck')">
							{{ t('mobilesheetsmanager', 'Re-audit Library') }}
						</NcButton>
					</div>
				</div>

				<ul v-if="issues.length > 0" :class="$style.issueList">
					<FilesIntegrityIssueItem
						v-for="issue in issues"
						:key="keyForIssue(issue)"
						:issue="issue"
						:is-fixing="fixingKey === keyForIssue(issue) || mergingKey === keyForIssue(issue)"
						@open-song="emit('openSong', $event)"
						@fix-issue="handleFix"
						@merge-songs="handleMerge" />
				</ul>
			</template>
		</template>

		<div :class="$style.dialogActions">
			<NcButton @click="emit('update:open', false)">
				{{ t('mobilesheetsmanager', 'Close') }}
			</NcButton>
		</div>
	</NcDialog>
</template>


<style module>
.loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 16px;
	min-height: 180px;
	color: var(--color-text-maxcontrast);
}

.error {
	color: var(--color-error);
	padding: 12px;
}

.summary {
	margin-bottom: 16px;
}

.cleanNotice {
	text-align: center;
	padding: 24px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
}

.cleanNotice h3 {
	margin: 0 0 8px;
	color: var(--color-success);
}

.cleanNotice p {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.issueStats {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.statChip {
	padding: 6px 12px;
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-background-dark);
	font-size: 0.9rem;
}

.issueList {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-height: 440px;
	overflow-y: auto;
	margin: 0;
	padding: 0;
	list-style: none;
}

.dialogActions {
	display: flex;
	justify-content: flex-end;
	margin-top: 16px;
}
</style>
