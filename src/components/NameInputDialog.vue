<template>
	<NcDialog
		v-if="open"
		:name="title"
		size="normal"
		@close="emit('close')">
		<p>{{ prompt }}</p>
		<NcTextField
			:model-value="name"
			:label="fieldLabel"
			:placeholder="placeholder"
			@update:model-value="emit('update:name', $event)"
			@keyup.enter="handleSubmit" />
		<p v-if="error" :class="$style.error" role="alert">{{ error }}</p>
		<template #actions>
			<NcButton @click="emit('close')">{{ t('sheetmusic', 'Cancel') }}</NcButton>
			<NcButton
				variant="primary"
				:disabled="isSubmitting || !name.trim()"
				@click="handleSubmit">
				{{ submitLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const props = withDefaults(defineProps<{
	open: boolean
	title: string
	prompt: string
	submitLabel: string
	name: string
	isSubmitting: boolean
	error: string
	placeholder?: string
	fieldLabel?: string
}>(), {
	placeholder: '',
	fieldLabel: '',
})

const emit = defineEmits<{
	close: []
	'update:name': [value: string]
	submit: []
}>()

function handleSubmit() {
	if (!props.isSubmitting && props.name.trim()) {
		emit('submit')
	}
}
</script>

<style module>
.error {
	color: var(--color-error);
	font-size: 0.85rem;
}
</style>
