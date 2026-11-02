<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import TuneVariant from 'vue-material-design-icons/TuneVariant.vue'

defineProps<{
	totalFiles: number
	stuckCount: number
	customCount: number
	normalCount: number
}>()
</script>

<template>
	<div :class="$style.summaryGrid" data-test="page-order-summary">
		<div :class="[$style.card, $style.totalCard]">
			<div :class="$style.iconWrapper">
				<FileDocumentOutline :size="24" />
			</div>
			<div :class="$style.cardBody">
				<span :class="$style.cardValue" data-test="total-files-count">{{ totalFiles }}</span>
				<span :class="$style.cardLabel">{{ t('sheetmusic', 'Total PDF Files') }}</span>
			</div>
		</div>

		<div :class="[$style.card, stuckCount > 0 ? $style.stuckCardActive : $style.cardMuted]">
			<div :class="[$style.iconWrapper, stuckCount > 0 ? $style.iconStuck : '']">
				<AlertCircleOutline :size="24" />
			</div>
			<div :class="$style.cardBody">
				<span :class="$style.cardValue" data-test="stuck-count">{{ stuckCount }}</span>
				<span :class="$style.cardLabel">{{ t('sheetmusic', 'Stuck at 1-1 (Multi-page)') }}</span>
			</div>
		</div>

		<div :class="[$style.card, customCount > 0 ? $style.customCardActive : $style.cardMuted]">
			<div :class="[$style.iconWrapper, customCount > 0 ? $style.iconCustom : '']">
				<TuneVariant :size="24" />
			</div>
			<div :class="$style.cardBody">
				<span :class="$style.cardValue" data-test="custom-count">{{ customCount }}</span>
				<span :class="$style.cardLabel">{{ t('sheetmusic', 'Custom Page Order') }}</span>
			</div>
		</div>

		<div :class="[$style.card, $style.normalCard]">
			<div :class="[$style.iconWrapper, $style.iconNormal]">
				<CheckCircleOutline :size="24" />
			</div>
			<div :class="$style.cardBody">
				<span :class="$style.cardValue" data-test="normal-count">{{ normalCount }}</span>
				<span :class="$style.cardLabel">{{ t('sheetmusic', 'Normal (1-N)') }}</span>
			</div>
		</div>
	</div>
</template>

<style module>
.summaryGrid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 16px;
	margin-bottom: 20px;
}

.card {
	display: flex;
	align-items: center;
	gap: 14px;
	padding: 16px 20px;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #e0e0e0);
	transition: box-shadow 0.2s ease, border-color 0.2s ease;
}

.cardBody {
	display: flex;
	flex-direction: column;
}

.cardValue {
	font-size: 1.6rem;
	font-weight: 700;
	line-height: 1.2;
	color: var(--color-main-text, #222);
}

.cardLabel {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast, #777);
	margin-top: 2px;
}

.iconWrapper {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 44px;
	height: 44px;
	border-radius: 50%;
	background: var(--color-background-hover, rgba(0, 0, 0, 0.05));
	color: var(--color-text-maxcontrast, #555);
	flex-shrink: 0;
}

.stuckCardActive {
	border-color: var(--color-warning, #e67e22);
	background: var(--color-warning-background, rgba(230, 126, 34, 0.08));
}

.iconStuck {
	background: rgba(230, 126, 34, 0.18);
	color: var(--color-warning, #d35400);
}

.customCardActive {
	border-color: var(--color-primary-element, #0082c9);
}

.iconCustom {
	background: rgba(0, 130, 201, 0.15);
	color: var(--color-primary-element, #0082c9);
}

.iconNormal {
	background: rgba(46, 204, 113, 0.15);
	color: var(--color-success, #27ae60);
}

.cardMuted {
	opacity: 0.8;
}
</style>
