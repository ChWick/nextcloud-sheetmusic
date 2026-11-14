<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
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
	<NcDialog
		v-if="isOpen && sub"
		:name="t('sheetmusic', 'Delete Sub-Library')"
		size="normal"
		@close="!isDeleting && emit('close')">
		<div :class="$style.dialogBody">
			<p>
				{{ t('sheetmusic', 'Are you sure you want to delete the sub-library "{name}"?', { name: sub.name }, { escape: false }) }}
			</p>
			<p :class="$style.dangerNote">
				{{ t('sheetmusic', 'This only removes the virtual WebDAV folder definition. All score files in Nextcloud and master database songs remain untouched.') }}
			</p>
		</div>

		<template #actions>
			<NcButton
				type="button"
				variant="error"
				:disabled="isDeleting"
				@click="emit('confirm')">
				<template #icon>
					<NcLoadingIcon v-if="isDeleting" :size="18" />
					<TrashCanOutline v-else :size="18" />
				</template>
				{{ t('sheetmusic', 'Delete Sub-Library') }}
			</NcButton>
			<NcButton
				type="button"
				:disabled="isDeleting"
				@click="emit('close')">
				{{ t('sheetmusic', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
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
