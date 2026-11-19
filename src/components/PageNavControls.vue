<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import ResponsiveNcButton from './ResponsiveNcButton.vue'

const props = withDefaults(
	defineProps<{
		currentPage: number
		totalPages: number
		currentPageDisplay?: string
		prevDisabled?: boolean
		nextDisabled?: boolean
		iconStyle?: 'chevron' | 'arrow'
	}>(),
	{
		currentPageDisplay: undefined,
		prevDisabled: undefined,
		nextDisabled: undefined,
		iconStyle: 'chevron',
	},
)

const emit = defineEmits<{
	prev: []
	next: []
}>()

const PrevIcon = computed(() => (props.iconStyle === 'arrow' ? ArrowLeft : ChevronLeft))
const NextIcon = computed(() => (props.iconStyle === 'arrow' ? ArrowRight : ChevronRight))
const isPrevDisabled = computed(() => props.prevDisabled ?? props.currentPage <= 1)
const isNextDisabled = computed(() => props.nextDisabled ?? props.currentPage >= props.totalPages)
const pageText = computed(() =>
	t(
		'sheetmusic',
		'Page {current} of {total}',
		{ current: props.currentPageDisplay ?? props.currentPage, total: props.totalPages || 1 },
		{ escape: false },
	),
)
</script>

<template>
	<div :class="$style.pageNav">
		<ResponsiveNcButton
			variant="secondary"
			icon-only
			:disabled="isPrevDisabled"
			:label="t('sheetmusic', 'Previous page')"
			@click="emit('prev')">
			<template #icon>
				<component :is="PrevIcon" :size="20" />
			</template>
		</ResponsiveNcButton>
		<span :class="$style.pageIndicator">{{ pageText }}</span>
		<ResponsiveNcButton
			variant="secondary"
			icon-only
			:disabled="isNextDisabled"
			:label="t('sheetmusic', 'Next page')"
			@click="emit('next')">
			<template #icon>
				<component :is="NextIcon" :size="20" />
			</template>
		</ResponsiveNcButton>
		<slot />
	</div>
</template>

<style module>
.pageNav {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-shrink: 0;
}

.pageIndicator {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-main-text);
	padding: 0 4px;
	white-space: nowrap;
}
</style>
