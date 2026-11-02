<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'

defineProps<{
	open: boolean
	percent: number
	message: string
	error?: boolean
}>()
</script>

<template>
	<NcDialog
		v-if="open"
		:name="t('sheetmusic', 'Resetting Page Ordering…')"
		size="normal">
		<div :class="$style.progressContainer" data-test="page-order-progress">
			<NcProgressBar
				:value="percent"
				size="medium"
				:show-value="true"
				:error="error" />
			<p :class="$style.progressMessage">
				{{ message || t('sheetmusic', 'Processing files…') }}
			</p>
		</div>
	</NcDialog>
</template>

<style module>
.progressContainer {
	display: flex;
	flex-direction: column;
	gap: 14px;
	padding: 10px 0;
}

.progressMessage {
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.9rem;
	margin: 0;
}
</style>
