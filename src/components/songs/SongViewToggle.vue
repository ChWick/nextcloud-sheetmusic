<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'

export type ViewMode = 'table' | 'cards' | 'list'

defineProps<{
	viewMode: ViewMode
}>()

const emit = defineEmits<{
	'update:viewMode': [mode: ViewMode]
}>()

const viewOptions: { value: ViewMode; label: string }[] = [
	{ value: 'table', label: t('mobilesheetsmanager', 'Table') },
	{ value: 'cards', label: t('mobilesheetsmanager', 'Cards') },
	{ value: 'list', label: t('mobilesheetsmanager', 'List') },
]
</script>

<template>
	<div :class="$style.toggleWrapper">
		<span :class="$style.label">{{ t('mobilesheetsmanager', 'View:') }}</span>
		<NcRadioGroup
			:model-value="viewMode"
			:label="t('mobilesheetsmanager', 'Songs view mode')"
			hide-label
			@update:model-value="emit('update:viewMode', $event as ViewMode)">
			<NcRadioGroupButton
				v-for="option in viewOptions"
				:key="option.value"
				:value="option.value"
				:label="option.label" />
		</NcRadioGroup>
	</div>
</template>

<style module>
.toggleWrapper {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
}

.label {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}
</style>
