<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'

defineProps<{
	open: boolean
	filename?: string
	action: 'copy' | 'move'
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	'update:action': [value: 'copy' | 'move']
	confirm: []
	cancel: []
}>()

function onActionChange(event: Event) {
	const target = event.target as HTMLInputElement
	emit('update:action', target.value as 'copy' | 'move')
}
</script>

<template>
	<NcDialog
		v-if="open"
		:name="t('mobilesheetsmanager', 'Attach Nextcloud PDF')"
		@close="emit('update:open', false); emit('cancel')">
		<p>
			{{ t('mobilesheetsmanager', 'Attach {file} to this song?', { file: filename }) }}
		</p>
		<div :class="$style.radioGroup">
			<label :class="$style.radioLabel">
				<input
					type="radio"
					name="attachMode"
					value="copy"
					:checked="action === 'copy'"
					@change="onActionChange">
				{{ t('mobilesheetsmanager', 'Copy file to library folder (default)') }}
			</label>
			<label :class="$style.radioLabel">
				<input
					type="radio"
					name="attachMode"
					value="move"
					:checked="action === 'move'"
					@change="onActionChange">
				{{ t('mobilesheetsmanager', 'Move file to library folder') }}
			</label>
		</div>
		<p>{{ t('mobilesheetsmanager', 'The file is added to the song when you save it.') }}</p>
		<div :class="$style.dialogActions">
			<NcButton @click="emit('update:open', false); emit('cancel')">
				{{ t('mobilesheetsmanager', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				@click="emit('confirm')">
				{{ t('mobilesheetsmanager', 'Attach File') }}
			</NcButton>
		</div>
	</NcDialog>
</template>

<style module>
.radioGroup {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin: 16px 0;
}

.radioLabel {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
	font-size: 0.95rem;
}

.dialogActions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 20px;
}
</style>
