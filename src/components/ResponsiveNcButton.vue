<script setup lang="ts">
import { computed, useCssModule } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'

export type ResponsiveCollapseAt = '480' | '600' | '768' | 'container-560' | 'none'

const props = withDefaults(
	defineProps<{
		/** Button label shown on wider viewports and used as fallback title/aria-label. */
		label: string
		/** Optional tooltip override; defaults to `label`. */
		title?: string
		/** Optional accessible name override; defaults to `label`. */
		ariaLabel?: string
		/** Breakpoint at which the label collapses to an icon-only centered button. */
		collapseAt?: ResponsiveCollapseAt
	}>(),
	{
		title: undefined,
		ariaLabel: undefined,
		collapseAt: '768',
	},
)

const style = useCssModule()

const collapseClass = computed(() => {
	switch (props.collapseAt) {
		case '480':
			return style.collapse480
		case '600':
			return style.collapse600
		case '768':
			return style.collapse768
		case 'container-560':
			return style.collapseContainer560
		default:
			return undefined
	}
})
</script>

<template>
	<NcButton
		:class="collapseClass"
		:title="title ?? label"
		:aria-label="ariaLabel ?? label">
		<template v-if="$slots.icon" #icon>
			<slot name="icon" />
		</template>
		<slot>{{ label }}</slot>
	</NcButton>
</template>

<style module>
@media (max-width: 768px) {
	.collapse768 :global(.button-vue__text) {
		display: none !important;
	}

	.collapse768:global(.button-vue) {
		--button-padding: 0;
		line-height: 1;
		width: var(--button-size, var(--default-clickable-area, 44px)) !important;
		min-width: var(--button-size, var(--default-clickable-area, 44px)) !important;
		min-height: var(--button-size, var(--default-clickable-area, 44px)) !important;
		padding: 0 !important;
		padding-inline: 0 !important;
		justify-content: center !important;
	}

	.collapse768 :global(.button-vue__wrapper) {
		justify-content: center !important;
		width: 100% !important;
	}

	.collapse768 :global(.button-vue__icon) {
		margin: 0 !important;
	}
}

@media (max-width: 600px) {
	.collapse600 :global(.button-vue__text) {
		display: none !important;
	}

	.collapse600:global(.button-vue) {
		--button-padding: 0;
		line-height: 1;
		width: var(--button-size, var(--default-clickable-area, 44px)) !important;
		min-width: var(--button-size, var(--default-clickable-area, 44px)) !important;
		min-height: var(--button-size, var(--default-clickable-area, 44px)) !important;
		padding: 0 !important;
		padding-inline: 0 !important;
		justify-content: center !important;
	}

	.collapse600 :global(.button-vue__wrapper) {
		justify-content: center !important;
		width: 100% !important;
	}

	.collapse600 :global(.button-vue__icon) {
		margin: 0 !important;
	}
}

@media (max-width: 480px) {
	.collapse480 :global(.button-vue__text) {
		display: none !important;
	}

	.collapse480:global(.button-vue) {
		--button-padding: 0;
		line-height: 1;
		width: var(--button-size, var(--default-clickable-area, 44px)) !important;
		min-width: var(--button-size, var(--default-clickable-area, 44px)) !important;
		min-height: var(--button-size, var(--default-clickable-area, 44px)) !important;
		padding: 0 !important;
		padding-inline: 0 !important;
		justify-content: center !important;
	}

	.collapse480 :global(.button-vue__wrapper) {
		justify-content: center !important;
		width: 100% !important;
	}

	.collapse480 :global(.button-vue__icon) {
		margin: 0 !important;
	}
}

@container (max-width: 560px) {
	.collapseContainer560 :global(.button-vue__text) {
		display: none !important;
	}

	.collapseContainer560:global(.button-vue) {
		--button-padding: 0;
		line-height: 1;
		width: var(--button-size, var(--default-clickable-area, 44px)) !important;
		min-width: var(--button-size, var(--default-clickable-area, 44px)) !important;
		min-height: var(--button-size, var(--default-clickable-area, 44px)) !important;
		padding: 0 !important;
		padding-inline: 0 !important;
		justify-content: center !important;
	}

	.collapseContainer560 :global(.button-vue__wrapper) {
		justify-content: center !important;
		width: 100% !important;
	}

	.collapseContainer560 :global(.button-vue__icon) {
		margin: 0 !important;
	}
}
</style>
