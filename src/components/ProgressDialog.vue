<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'

withDefaults(defineProps<{
	open: boolean
	title: string
	percent: number
	message?: string
	fallbackMessage?: string
	error?: boolean
	testId?: string
}>(), {
	message: '',
	fallbackMessage: undefined,
	error: false,
	testId: undefined,
})
</script>

<template>
	<NcDialog
		v-if="open"
		:name="title"
		size="normal">
		<div :class="$style.progressContainer" :data-test="testId">
			<NcProgressBar
				:value="percent"
				size="medium"
				:show-value="true"
				:error="error" />
			<p :class="$style.progressMessage">
				{{ message || fallbackMessage || t('sheetmusic', 'Processing files…') }}
			</p>
		</div>
	</NcDialog>
</template>

<style module>
.progressContainer {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px 8px;
	width: 100%;
	box-sizing: border-box;
}

.progressMessage {
	margin: 0;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
	text-align: center;
}
</style>
