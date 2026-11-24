<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Check from 'vue-material-design-icons/Check.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import type { LibraryShare } from '../types/library'

defineProps<{
	shares: LibraryShare[]
	emptyText: string
	copiedToken?: string | null
	deletingShareId?: string | number | null
}>()

const emit = defineEmits<{
	copy: [url: string, token: string]
	delete: [share: LibraryShare]
}>()
</script>

<template>
	<div v-if="shares.length === 0" :class="$style.emptyCardState">
		<p>{{ emptyText }}</p>
	</div>
	<ul v-else :class="$style.sharesList">
		<li
			v-for="share in shares"
			:key="share.id"
			:class="$style.shareRow">
			<div :class="$style.shareInfo">
				<component
					:is="share.shareType === 3 ? LinkVariant : (share.shareType === 1 ? AccountGroupOutline : AccountOutline)"
					:size="20"
					:class="$style.shareTypeIcon" />
				<div :class="$style.shareDetails">
					<span :class="$style.shareTarget">
						{{ share.shareType === 3 ? t('sheetmusic', 'Public Link (MobileSheets sync)') : (share.shareWithDisplayName || share.shareWith) }}
					</span>
					<div :class="$style.shareBadges">
						<span :class="[$style.badge, share.canWrite ? $style.badgeWrite : $style.badgeRead]">
							{{ share.canWrite ? t('sheetmusic', 'Read & Write') : t('sheetmusic', 'Read-only') }}
						</span>
						<span v-if="share.shareType === 1" :class="$style.badgeGroup">
							{{ t('sheetmusic', 'Group') }}
						</span>
					</div>
				</div>
			</div>

			<div :class="$style.shareActions">
				<NcButton
					v-if="share.publicUrl || share.webdavUrl"
					variant="secondary"
					size="small"
					:title="t('sheetmusic', 'Copy sync URL')"
					:aria-label="t('sheetmusic', 'Copy sync URL')"
					@click="emit('copy', share.webdavUrl || share.publicUrl || '', String(share.id))">
					<template #icon>
						<Check v-if="copiedToken === String(share.id)" :size="16" />
						<ContentCopy v-else :size="16" />
					</template>
					{{ copiedToken === String(share.id) ? t('sheetmusic', 'Copied') : t('sheetmusic', 'Copy link') }}
				</NcButton>

				<NcButton
					variant="error"
					size="small"
					:disabled="deletingShareId === share.id"
					:title="t('sheetmusic', 'Delete share')"
					:aria-label="t('sheetmusic', 'Delete share')"
					@click="emit('delete', share)">
					<template #icon>
						<DeleteOutline :size="18" />
					</template>
				</NcButton>
			</div>
		</li>
	</ul>
</template>

<style module>
.emptyCardState {
	padding: 12px 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	font-style: italic;
}

.emptyCardState p {
	margin: 0;
}

.sharesList {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.shareRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	gap: 12px;
}

.shareInfo {
	display: flex;
	align-items: center;
	gap: 12px;
	min-width: 0;
}

.shareTypeIcon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.shareDetails {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
}

.shareTarget {
	font-weight: 500;
	font-size: 14px;
	color: var(--color-main-text);
}

.shareBadges {
	display: flex;
	align-items: center;
	gap: 6px;
}

.badge {
	font-size: 11px;
	font-weight: 600;
	padding: 2px 8px;
	border-radius: 10px;
	text-transform: uppercase;
}

.badgeRead {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.badgeWrite {
	background-color: rgba(0, 130, 200, 0.15);
	color: var(--color-primary-element);
}

.badgeGroup {
	font-size: 11px;
	padding: 2px 6px;
	border-radius: 10px;
	background-color: var(--color-background-darker, rgba(0, 0, 0, 0.08));
	color: var(--color-text-maxcontrast);
}

.shareActions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-shrink: 0;
}
</style>
