<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

defineProps<{
	testId?: string
}>()
</script>

<template>
	<div :class="$style.wrap" :data-test="testId">
		<div :class="$style.card">
			<slot />
		</div>
	</div>
</template>

<style module>
.wrap {
	display: flex;
	justify-content: center;
	width: 100%;
}

.card {
	display: flex;
	flex-direction: column;
	gap: 12px;
	width: min(360px, 100%);
	padding: 18px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background);
	box-sizing: border-box;
}
</style>
