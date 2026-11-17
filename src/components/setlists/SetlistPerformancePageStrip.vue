<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import CloseIcon from 'vue-material-design-icons/Close.vue'

const props = defineProps<{
	open: boolean
	totalPages: number
	currentPage: number
	renderThumbnail?: (page: number, canvas: HTMLCanvasElement) => Promise<void>
}>()

const emit = defineEmits<{
	select: [page: number]
	close: []
	pauseAutoHide: []
	resumeAutoHide: []
}>()

const canvasRefs = new Map<number, HTMLCanvasElement>()
const stripRef = ref<HTMLElement | null>(null)

function setCanvasRef(page: number, el: unknown) {
	if (el instanceof HTMLCanvasElement) {
		canvasRefs.set(page, el)
	} else {
		canvasRefs.delete(page)
	}
}

async function renderAllThumbnails() {
	if (!props.open || !props.renderThumbnail) return
	await nextTick()
	for (let p = 1; p <= props.totalPages; p++) {
		const canvas = canvasRefs.get(p)
		if (canvas) {
			await props.renderThumbnail(p, canvas)
		}
	}
	const activeEl = stripRef.value?.querySelector<HTMLElement>(`[data-page-thumb="${props.currentPage}"]`)
	if (activeEl && typeof activeEl.scrollIntoView === 'function') {
		activeEl.scrollIntoView({ inline: 'center', block: 'nearest' })
	}
}

watch(
	() => [props.open, props.totalPages],
	([isOpen]) => {
		if (isOpen) {
			renderAllThumbnails()
		}
	},
	{ immediate: true },
)
</script>

<template>
	<div
		v-if="open"
		:class="$style.stripContainer"
		role="dialog"
		:aria-label="t('sheetmusic', 'Jump to page')"
		@click.stop
		@mouseenter="emit('pauseAutoHide')"
		@mouseleave="emit('resumeAutoHide')">
		<div :class="$style.stripHeader">
			<span :class="$style.stripTitle">{{ t('sheetmusic', 'Pages ({total})', { total: totalPages }) }}</span>
			<button
				type="button"
				:class="$style.closeBtn"
				:aria-label="t('sheetmusic', 'Close')"
				@click="emit('close')">
				<CloseIcon :size="16" />
			</button>
		</div>
		<div ref="stripRef" :class="$style.stripScroll">
			<button
				v-for="page in totalPages"
				:key="page"
				type="button"
				:data-page-thumb="page"
				:class="[$style.thumbCard, page === currentPage && $style.thumbCardActive]"
				:aria-label="t('sheetmusic', 'Page {page}', { page })"
				@click="emit('select', page)">
				<canvas
					:ref="(el) => setCanvasRef(page, el)"
					:class="$style.thumbCanvas" />
				<span :class="$style.pageNum">{{ page }}</span>
			</button>
		</div>
	</div>
</template>

<style module>
.stripContainer {
	position: fixed;
	left: 12px;
	right: 12px;
	bottom: 60px;
	z-index: 100015;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.25);
	padding: 8px 12px 10px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.stripHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.stripTitle {
	font-size: 0.82rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.closeBtn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border: none;
	border-radius: var(--border-radius-pill);
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
}

.closeBtn:hover {
	background-color: var(--color-background-hover);
}

.stripScroll {
	display: flex;
	align-items: flex-end;
	gap: 10px;
	overflow-x: auto;
	padding: 4px 2px;
}

.thumbCard {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 4px;
	padding: 4px;
	border: 2px solid transparent;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
	cursor: pointer;
	flex-shrink: 0;
}

.thumbCard:hover {
	border-color: var(--color-border-dark);
}

.thumbCardActive {
	border-color: var(--color-primary-element);
	background-color: var(--color-primary-element-light);
}

.thumbCanvas {
	display: block;
	width: 88px;
	height: auto;
	max-height: 132px;
	background-color: #ffffff;
	box-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
}

.pageNum {
	font-size: 0.78rem;
	font-weight: 600;
	color: var(--color-main-text);
}
</style>
