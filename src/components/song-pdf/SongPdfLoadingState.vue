<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

withDefaults(
	defineProps<{
		loadingText?: string
	}>(),
	{
		loadingText: '',
	},
)
</script>

<template>
	<div :class="$style.loadingArea" data-test="pdf-loading-area">
		<NcLoadingIcon :size="48" />
		<span>{{ loadingText || t('sheetmusic', 'Loading PDF pages…') }}</span>
	</div>
</template>

<style module>
.loadingArea {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	height: 100%;
	gap: 12px;
	color: var(--color-text-maxcontrast);
}
</style>
