<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import { useAppUpdate } from '../composables/useAppUpdate'

const {
	versionMetadata,
	updateProgress,
	isUpdateReady,
	statusLabel,
	isChecking,
	lastCheckResult,
	progressDetail,
	checkForUpdate,
	applyUpdate,
} = useAppUpdate()

const idleStatusMessage = computed(() => {
	if (lastCheckResult.value === 'up-to-date') {
		return t('sheetmusic', 'Sheet Music is up to date.')
	}
	if (lastCheckResult.value === 'offline') {
		return t('sheetmusic', 'Offline — cannot check for updates.')
	}
	if (lastCheckResult.value === 'error') {
		return t('sheetmusic', 'Could not check for updates.')
	}
	return ''
})
</script>

<template>
	<div :class="$style.versionSection" data-test="app-version-section">
		<h4 :class="$style.sectionTitle">
			{{ t('sheetmusic', 'App Version') }}
		</h4>
		<div :class="$style.versionRow">
			<div :class="$style.versionInfo">
				<span :class="$style.versionNumber" data-test="app-version-value">
					v{{ versionMetadata.appVersion }}
				</span>
				<span v-if="versionMetadata.releaseDate" :class="$style.versionMeta" data-test="app-release-date">
					{{ t('sheetmusic', 'Released {date}', { date: versionMetadata.releaseDate }) }}
				</span>
				<span :class="$style.versionHash" data-test="app-asset-version">
					{{ t('sheetmusic', 'Build {hash}', { hash: versionMetadata.assetVersion }) }}
				</span>
			</div>
			<NcButton
				v-if="isUpdateReady"
				type="button"
				variant="primary"
				size="small"
				data-test="apply-update-btn"
				@click="applyUpdate">
				<template #icon>
					<RefreshIcon :size="16" />
				</template>
				{{ t('sheetmusic', 'Reload to update') }}
			</NcButton>
			<NcButton
				v-else
				type="button"
				variant="secondary"
				size="small"
				:disabled="isChecking || updateProgress.active"
				data-test="check-updates-btn"
				@click="checkForUpdate">
				<template #icon>
					<RefreshIcon :size="16" />
				</template>
				{{ isChecking ? t('sheetmusic', 'Checking…') : t('sheetmusic', 'Check for updates') }}
			</NcButton>
		</div>

		<div v-if="updateProgress.active && !isUpdateReady" :class="$style.updateProgressBox" data-test="settings-update-progress">
			<div :class="$style.updateProgressHeader">
				<span>{{ statusLabel }}</span>
				<span :class="$style.updateProgressPercent">{{ updateProgress.percent }}%</span>
			</div>
			<NcProgressBar
				:value="updateProgress.percent"
				size="small"
				:show-value="false" />
			<p v-if="progressDetail" :class="$style.updateProgressDetail">
				{{ progressDetail }}
			</p>
		</div>
		<p v-else-if="isUpdateReady" :class="$style.updateStatusMessage" data-test="update-status-message">
			{{ t('sheetmusic', 'A new version has been downloaded and is ready to apply.') }}
		</p>
		<p v-else-if="idleStatusMessage" :class="$style.updateStatusMessage" data-test="update-status-message">
			{{ idleStatusMessage }}
		</p>
	</div>
</template>

<style module>
.versionSection {
	margin-top: 20px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.sectionTitle {
	margin: 0;
	font-size: 1rem;
	font-weight: 600;
}

.versionRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
}

.versionInfo {
	display: flex;
	align-items: baseline;
	flex-wrap: wrap;
	gap: 6px 10px;
}

.versionNumber {
	font-weight: 600;
	font-size: 0.95rem;
	color: var(--color-main-text);
}

.versionMeta {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.versionHash {
	font-size: 0.8rem;
	font-family: monospace;
	color: var(--color-text-maxcontrast);
}

.updateProgressBox {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-top: 4px;
}

.updateProgressHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	font-size: 0.85rem;
	color: var(--color-main-text);
}

.updateProgressPercent {
	font-weight: 600;
	font-variant-numeric: tabular-nums;
}

.updateProgressDetail,
.updateStatusMessage {
	margin: 0;
	font-size: 0.82rem;
	color: var(--color-text-maxcontrast);
}
</style>
