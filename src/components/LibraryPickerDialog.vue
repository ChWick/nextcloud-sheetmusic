<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'

export interface LibraryPickerProgress {
	percent: number
	message: string
	status?: string
}

const props = withDefaults(defineProps<{
	open: boolean
	title: string
	description: string
	selectedPath?: string | null
	pickerLabel: string
	submitLabel: string
	isSubmitting: boolean
	progress?: LibraryPickerProgress | null
	error?: string
}>(), {
	selectedPath: null,
	progress: null,
	error: '',
})

const emit = defineEmits<{
	close: []
	pick: []
	submit: []
}>()

function handleClose() {
	if (!props.isSubmitting) {
		emit('close')
	}
}
</script>

<template>
	<NcDialog
		v-if="open"
		:name="title"
		size="normal"
		@close="handleClose">
		<p>{{ description }}</p>
		<NcButton
			variant="primary"
			:disabled="isSubmitting"
			data-test="library-picker-button"
			@click="emit('pick')">
			{{ selectedPath || pickerLabel }}
		</NcButton>
		<slot />
		<div v-if="isSubmitting && progress" :class="$style.progressContainer">
			<NcProgressBar
				:value="progress.percent"
				size="medium"
				:show-value="true"
				:error="progress.status === 'error'" />
			<p :class="$style.progressMessage">{{ progress.message }}</p>
		</div>
		<p v-if="error" :class="$style.error" role="alert">{{ error }}</p>
		<template #actions>
			<NcButton :disabled="isSubmitting" @click="handleClose">
				{{ t('sheetmusic', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!selectedPath || isSubmitting"
				data-test="library-submit-button"
				@click="emit('submit')">
				{{ submitLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style module>
.progressContainer {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 16px;
}

.progressMessage {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.error {
	color: var(--color-error);
	font-size: 0.85rem;
	margin-top: 12px;
}
</style>
