<script setup lang="ts">
import NcButton from '@nextcloud/vue/components/NcButton'
import AlertCircleOutlineIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'

withDefaults(defineProps<{
	title: string
	description?: string
	actionLabel?: string
	testId?: string
}>(), {
	description: '',
	actionLabel: '',
	testId: 'not-found-state',
})

const emit = defineEmits<{
	action: []
}>()
</script>

<template>
	<div :class="$style.container" role="status" :data-test="testId">
		<div :class="$style.card">
			<AlertCircleOutlineIcon :size="48" :class="$style.icon" aria-hidden="true" />
			<h2 :class="$style.title">{{ title }}</h2>
			<p v-if="description" :class="$style.description">{{ description }}</p>
			<NcButton
				v-if="actionLabel"
				variant="primary"
				:class="$style.actionButton"
				data-test="not-found-action"
				@click="emit('action')">
				<template #icon>
					<ArrowLeftIcon :size="20" />
				</template>
				{{ actionLabel }}
			</NcButton>
		</div>
	</div>
</template>

<style module>
.container {
	display: flex;
	align-items: center;
	justify-content: center;
	flex: 1;
	min-height: 280px;
	padding: 24px;
	box-sizing: border-box;
}

.card {
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
	max-width: 440px;
	padding: 28px 32px;
	gap: 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-background-hover);
}

.icon {
	color: var(--color-text-maxcontrast);
}

.title {
	margin: 4px 0 0;
	font-size: 1.25rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.description {
	margin: 0;
	font-size: 0.95rem;
	line-height: 1.45;
	color: var(--color-text-maxcontrast);
}

.actionButton {
	margin-top: 8px;
}
</style>
