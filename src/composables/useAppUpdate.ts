import { computed, onUnmounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { formatFileSize } from '../utils/file'
import {
	applyPendingAppUpdate,
	checkForAppUpdate,
	dismissAppUpdateBanner,
	getAppUpdateProgress,
	getAppVersionMetadata,
	subscribeAppUpdateProgress,
	watchServiceWorkerProgress,
	type AppUpdateProgress,
	type AppVersionMetadata,
	type UpdateCheckResult,
} from '../utils/pwaVersion'

export function useAppUpdate() {
	watchServiceWorkerProgress()

	const versionMetadata = ref<AppVersionMetadata>(getAppVersionMetadata())
	const updateProgress = ref<AppUpdateProgress>(getAppUpdateProgress())
	const isChecking = ref(false)
	const lastCheckResult = ref<UpdateCheckResult | null>(null)

	const unsubscribe = subscribeAppUpdateProgress((progress) => {
		updateProgress.value = progress
	})

	onUnmounted(() => {
		unsubscribe()
	})

	const isUpdateReady = computed(() => updateProgress.value.active && updateProgress.value.stage === 'waiting')

	const statusLabel = computed(() => {
		switch (updateProgress.value.stage) {
			case 'waiting':
				return t('sheetmusic', 'Sheet Music update ready')
			case 'reloading':
				return t('sheetmusic', 'Applying Sheet Music update…')
			case 'installing':
				return t('sheetmusic', 'Finalizing Sheet Music update…')
			default:
				return t('sheetmusic', 'Downloading Sheet Music update…')
		}
	})

	const progressDetail = computed(() => {
		const { loadedBytes, totalBytes, completedFiles, totalFiles } = updateProgress.value
		const parts: string[] = []
		if (totalBytes > 0 && loadedBytes <= totalBytes) {
			parts.push(`${formatFileSize(loadedBytes)} / ${formatFileSize(totalBytes)}`)
		} else if (loadedBytes > 0) {
			parts.push(formatFileSize(loadedBytes))
		}
		if (totalFiles > 0) {
			parts.push(`${completedFiles}/${totalFiles}`)
		}
		return parts.join(' · ')
	})

	async function checkForUpdate(): Promise<UpdateCheckResult> {
		if (isChecking.value) {
			return lastCheckResult.value ?? 'up-to-date'
		}
		isChecking.value = true
		lastCheckResult.value = null
		try {
			const result = await checkForAppUpdate()
			lastCheckResult.value = result
			return result
		} finally {
			isChecking.value = false
		}
	}

	return {
		versionMetadata,
		updateProgress,
		isUpdateReady,
		statusLabel,
		isChecking,
		lastCheckResult,
		progressDetail,
		checkForUpdate,
		applyUpdate: applyPendingAppUpdate,
		dismissUpdate: dismissAppUpdateBanner,
	}
}
