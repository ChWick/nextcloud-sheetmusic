<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

withDefaults(
	defineProps<{
		progressText?: string
		progressPercent?: number
	}>(),
	{
		progressText: '',
		progressPercent: 0,
	},
)
</script>

<template>
	<div
		:class="$style.saveProgressBanner"
		data-test="pdf-save-progress">
		<div :class="$style.saveProgressHeader">
			<div :class="$style.saveProgressInfo">
				<NcLoadingIcon :size="18" />
				<span :class="$style.saveProgressText" data-test="pdf-save-progress-text">
					{{ progressText || t('sheetmusic', 'Saving PDF…') }}
				</span>
			</div>
			<span :class="$style.saveProgressPercent" data-test="pdf-save-progress-percent">
				{{ progressPercent }}%
			</span>
		</div>
		<div
			:class="$style.saveProgressTrack"
			role="progressbar"
			:aria-valuenow="progressPercent"
			aria-valuemin="0"
			aria-valuemax="100">
			<div
				:class="$style.saveProgressFill"
				data-test="pdf-save-progress-fill"
				:style="{ width: `${progressPercent}%` }" />
		</div>
	</div>
</template>

<style module>
.saveProgressBanner {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 10px 16px;
	background-color: var(--color-background-hover, #f3f4f6);
	border-bottom: 1px solid var(--color-border, #e5e7eb);
	flex-shrink: 0;
}

.saveProgressHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.saveProgressInfo {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}

.saveProgressText {
	font-size: 13px;
	font-weight: 500;
	color: var(--color-main-text, #111827);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.saveProgressPercent {
	font-size: 13px;
	font-weight: 700;
	color: var(--color-primary-element, #0082c9);
	flex-shrink: 0;
}

.saveProgressTrack {
	width: 100%;
	height: 8px;
	background-color: var(--color-border, #d1d5db);
	border-radius: 999px;
	overflow: hidden;
}

.saveProgressFill {
	height: 100%;
	background-color: var(--color-primary-element, #0082c9);
	border-radius: 999px;
	transition: width 0.15s ease-out;
}
</style>
