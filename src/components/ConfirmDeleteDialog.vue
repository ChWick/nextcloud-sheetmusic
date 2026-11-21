<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

const props = withDefaults(defineProps<{
	open: boolean
	title: string
	confirmLabel: string
	isDeleting?: boolean
	error?: string
	cancelLabel?: string
	showLoadingIcon?: boolean
}>(), {
	isDeleting: false,
	error: '',
	cancelLabel: '',
	showLoadingIcon: true,
})

const emit = defineEmits<{
	close: []
	confirm: []
}>()

function handleClose() {
	if (props.isDeleting) return
	emit('close')
}
</script>

<template>
	<NcDialog
		v-if="open"
		:name="title"
		size="normal"
		@close="handleClose">
		<slot />
		<p v-if="error" :class="$style.error" role="alert">{{ error }}</p>
		<template #actions>
			<NcButton
				type="button"
				:disabled="isDeleting"
				@click="handleClose">
				{{ cancelLabel || t('sheetmusic', 'Cancel') }}
			</NcButton>
			<NcButton
				type="button"
				variant="error"
				:disabled="isDeleting"
				@click="emit('confirm')">
				<template v-if="showLoadingIcon && isDeleting" #icon>
					<NcLoadingIcon :size="16" />
				</template>
				<template v-else-if="$slots.confirmIcon" #icon>
					<slot name="confirmIcon" />
				</template>
				{{ confirmLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style module>
.error {
	color: var(--color-error);
	margin: 8px 0;
}
</style>
