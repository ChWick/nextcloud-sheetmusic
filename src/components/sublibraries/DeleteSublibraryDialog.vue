<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import ConfirmDeleteDialog from '../ConfirmDeleteDialog.vue'
import type { Sublibrary } from '../../types/library'

defineProps<{
	isOpen: boolean
	sub: Sublibrary | null
	isDeleting: boolean
}>()

const emit = defineEmits<{
	confirm: []
	close: []
}>()
</script>

<template>
	<ConfirmDeleteDialog
		:open="isOpen && Boolean(sub)"
		:title="t('sheetmusic', 'Delete Sub-Library')"
		:confirm-label="t('sheetmusic', 'Delete Sub-Library')"
		:is-deleting="isDeleting"
		@close="emit('close')"
		@confirm="emit('confirm')">
		<div :class="$style.dialogBody">
			<p>
				{{ t('sheetmusic', 'Are you sure you want to delete the sub-library "{name}"?', { name: sub?.name ?? '' }, { escape: false }) }}
			</p>
			<p :class="$style.dangerNote">
				{{ t('sheetmusic', 'This only removes the virtual WebDAV folder definition. All score files in Nextcloud and master database songs remain untouched.') }}
			</p>
		</div>
		<template #confirm-icon>
			<TrashCanOutline :size="18" />
		</template>
	</ConfirmDeleteDialog>
</template>

<style module>
.dialogBody {
	padding: 8px 0;
}

.dangerNote {
	margin-top: 10px;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}
</style>
