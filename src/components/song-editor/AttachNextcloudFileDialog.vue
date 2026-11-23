<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'

withDefaults(defineProps<{
	open: boolean
	filename?: string
	action: 'copy' | 'move'
	title?: string
	prompt?: string
	copyLabel?: string
	moveLabel?: string
	hint?: string | null
	confirmLabel?: string
}>(), {
	filename: '',
	title: undefined,
	prompt: undefined,
	copyLabel: undefined,
	moveLabel: undefined,
	hint: undefined,
	confirmLabel: undefined,
})

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
		:name="title || t('sheetmusic', 'Attach Nextcloud PDF')"
		@close="emit('update:open', false); emit('cancel')">
		<p>
			{{ prompt || t('sheetmusic', 'Attach {file} to this song?', { file: filename }, { escape: false }) }}
		</p>
		<div :class="$style.radioGroup">
			<label :class="$style.radioLabel">
				<input
					type="radio"
					name="attachMode"
					value="copy"
					:checked="action === 'copy'"
					@change="onActionChange">
				{{ copyLabel || t('sheetmusic', 'Copy file to library folder (default)') }}
			</label>
			<label :class="$style.radioLabel">
				<input
					type="radio"
					name="attachMode"
					value="move"
					:checked="action === 'move'"
					@change="onActionChange">
				{{ moveLabel || t('sheetmusic', 'Move file to library folder') }}
			</label>
		</div>
		<p v-if="hint !== null">{{ hint || t('sheetmusic', 'The file is added to the song when you save it.') }}</p>
		<div :class="$style.dialogActions">
			<NcButton @click="emit('update:open', false); emit('cancel')">
				{{ t('sheetmusic', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				@click="emit('confirm')">
				{{ confirmLabel || t('sheetmusic', 'Attach File') }}
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
