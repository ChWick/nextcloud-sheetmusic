<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import WrenchIcon from 'vue-material-design-icons/Wrench.vue'
import FilesIntegrityIssueItem from './FilesIntegrityIssueItem.vue'
import { keyForIssue, type IntegrityIssueGroup } from '../../utils/integrity'
import type { IntegrityIssue } from '../../types/song'

defineProps<{
	group: IntegrityIssueGroup
	collapsed: boolean
	isBatchFixing: boolean
	fixingKey: string | null
	mergingKey: string | null
}>()

const emit = defineEmits<{
	toggle: [groupType: string]
	fixGroup: [groupType: string]
	openSong: [songId: number]
	fixIssue: [issue: IntegrityIssue]
	mergeSongs: [issue: IntegrityIssue, mode?: 'merge' | 'keepBoth']
	fixOrphanFile: [issue: IntegrityIssue]
	deleteMissingFile: [issue: IntegrityIssue]
}>()
</script>

<template>
	<div :class="$style.groupCard">
		<div :class="$style.groupHeader">
			<div :class="$style.groupTitleArea" @click="emit('toggle', group.type)">
				<NcButton
					variant="tertiary"
					size="small"
					:aria-label="collapsed ? t('sheetmusic', 'Expand group') : t('sheetmusic', 'Collapse group')">
					<template #icon>
						<ChevronRightIcon v-if="collapsed" :size="20" />
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
				@click.stop="emit('fixGroup', group.type)">
				<template #icon>
					<WrenchIcon :size="16" />
				</template>
				{{ t('sheetmusic', 'Fix All in Group ({count})', { count: group.fixableCount }, { escape: false }) }}
			</NcButton>
		</div>

		<ul v-if="!collapsed" :class="$style.issueList">
			<FilesIntegrityIssueItem
				v-for="issue in group.issues"
				:key="keyForIssue(issue)"
				:issue="issue"
				:is-fixing="fixingKey === keyForIssue(issue) || mergingKey === keyForIssue(issue)"
				@open-song="emit('openSong', $event)"
				@fix-issue="emit('fixIssue', $event)"
				@merge-songs="(iss, mode) => emit('mergeSongs', iss, mode)"
				@fix-orphan-file="emit('fixOrphanFile', $event)"
				@delete-missing-file="emit('deleteMissingFile', $event)" />
		</ul>
	</div>
</template>

<style module>
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
