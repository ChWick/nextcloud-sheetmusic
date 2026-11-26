<script setup lang="ts">
import { computed, onUnmounted, ref, shallowRef, watch } from 'vue'
import type { PDFDocumentProxy } from 'pdfjs-dist'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import type { Bookmark } from '../../types/song'
import {
	LINK_LABEL_PRESETS,
	resolveLinkLabel,
	type LinkMarkerStyle,
} from '../../utils/annotationLinks'
import { fetchCachedBinaryFile } from '../../utils/file-binary'
import { defaultFileBinaryCache } from '../../utils/file-cache'
import { destroyPdfJsDocument, loadPdfJsDocument } from '../../utils/pdfjs'
import SongLinkPickerPane from './SongLinkPickerPane.vue'

export interface SideBySideLinkResult {
	sourcePageIndex: number
	sourceX: number
	sourceY: number
	targetPageIndex: number
	targetX: number
	targetY: number
	label: string
	linkStyle: LinkMarkerStyle
	createReverse: boolean
}

const props = withDefaults(defineProps<{
	open: boolean
	filePath: string
	totalPages: number
	initialSourcePageIndex?: number
	initialSourceX?: number
	initialSourceY?: number
	initialTargetPageIndex?: number
	initialTargetX?: number
	initialTargetY?: number
	initialLabel?: string
	initialLinkStyle?: LinkMarkerStyle
	initialCreateReverse?: boolean
	showReverseOption?: boolean
	bookmarks?: Bookmark[]
}>(), {
	initialSourcePageIndex: 0,
	initialSourceX: 0.5,
	initialSourceY: 0.5,
	initialTargetPageIndex: 0,
	initialTargetX: 0.5,
	initialTargetY: 0.2,
	initialLabel: '',
	initialLinkStyle: 'box',
	initialCreateReverse: false,
	showReverseOption: true,
	bookmarks: () => [],
})

const emit = defineEmits<{
	close: []
	save: [result: SideBySideLinkResult]
}>()

const sourcePageIdx = ref(0)
const sourceX = ref(0.5)
const sourceY = ref(0.5)
const targetPageIdx = ref(0)
const targetX = ref(0.5)
const targetY = ref(0.2)
const label = ref('')
const linkStyle = ref<LinkMarkerStyle>('box')
const createReverse = ref(false)

const pdfDoc = shallowRef<PDFDocumentProxy | null>(null)
const effectiveTotalPages = computed(() => Math.max(1, pdfDoc.value?.numPages ?? props.totalPages))
const previewSourceLabel = computed(() => resolveLinkLabel(label.value, targetPageIdx.value, linkStyle.value))
const previewReverseStyle = computed<LinkMarkerStyle>(() =>
	createReverse.value && linkStyle.value === 'box' ? 'box' : 'circle',
)
const previewReverseLabel = computed(() =>
	createReverse.value && linkStyle.value === 'box' ? `← p. ${sourcePageIdx.value + 1}` : '',
)

async function ensurePdfLoaded() {
	if (!props.filePath || pdfDoc.value) return
	try {
		const bytes = await fetchCachedBinaryFile(props.filePath, defaultFileBinaryCache)
		if (!bytes) return
		pdfDoc.value = await loadPdfJsDocument(bytes.slice())
	} catch {
		// Fallback to blank clickable canvases if PDF fails to load
	}
}

watch(
	() => props.open,
	(isOpen) => {
		if (!isOpen) return
		sourcePageIdx.value = Math.max(0, props.initialSourcePageIndex)
		sourceX.value = props.initialSourceX
		sourceY.value = props.initialSourceY
		targetPageIdx.value = Math.max(0, props.initialTargetPageIndex)
		targetX.value = props.initialTargetX
		targetY.value = props.initialTargetY
		label.value = props.initialLabel
		linkStyle.value = props.initialLinkStyle
		createReverse.value = props.initialCreateReverse
		void ensurePdfLoaded()
	},
	{ immediate: true },
)

onUnmounted(() => {
	if (pdfDoc.value) {
		void destroyPdfJsDocument(pdfDoc.value)
		pdfDoc.value = null
	}
})

function applyBookmark(bm: Bookmark) {
	targetPageIdx.value = Math.max(0, Math.min(effectiveTotalPages.value - 1, bm.pageNum - 1))
	if (!label.value.trim()) {
		label.value = bm.name
	}
}

function handleSave() {
	emit('save', {
		sourcePageIndex: sourcePageIdx.value,
		sourceX: sourceX.value,
		sourceY: sourceY.value,
		targetPageIndex: targetPageIdx.value,
		targetX: targetX.value,
		targetY: targetY.value,
		label: label.value.trim(),
		linkStyle: linkStyle.value,
		createReverse: createReverse.value,
	})
}
</script>

<template>
	<Teleport to="body">
		<div
			v-if="open"
			:class="$style.backdrop"
			role="dialog"
			aria-modal="true"
			:aria-label="t('sheetmusic', 'Side-by-side link picker')"
			data-test="link-picker-modal"
			@keydown.esc.prevent="emit('close')">
			<div :class="$style.modal">
				<header :class="$style.header">
					<strong>{{ t('sheetmusic', 'Link Point Picker') }}</strong>
					<span :class="$style.hint">
						{{ t('sheetmusic', 'Click left page for source, right page for exact destination') }}
					</span>
					<button
						type="button"
						:class="$style.closeBtn"
						:aria-label="t('sheetmusic', 'Close')"
						@click="emit('close')">
						<CloseIcon :size="20" />
					</button>
				</header>

				<div :class="$style.panes">
					<SongLinkPickerPane
						role="source"
						:pdf-doc="pdfDoc"
						:page-index="sourcePageIdx"
						:total-pages="effectiveTotalPages"
						:pin-x="sourceX"
						:pin-y="sourceY"
						:pin-style="linkStyle"
						:pin-label="previewSourceLabel"
						@update:page-index="sourcePageIdx = $event"
						@pick-point="sourceX = $event.x; sourceY = $event.y" />

					<SongLinkPickerPane
						role="target"
						:pdf-doc="pdfDoc"
						:page-index="targetPageIdx"
						:total-pages="effectiveTotalPages"
						:pin-x="targetX"
						:pin-y="targetY"
						:pin-style="previewReverseStyle"
						:pin-label="previewReverseLabel"
						:bookmarks="bookmarks"
						@update:page-index="targetPageIdx = $event"
						@pick-point="targetX = $event.x; targetY = $event.y"
						@select-bookmark="applyBookmark" />
				</div>

				<footer :class="$style.footer">
					<div :class="$style.footerControls">
						<input
							v-model="label"
							type="text"
							:placeholder="t('sheetmusic', 'Button label (optional for circle)')"
							:aria-label="t('sheetmusic', 'Link label')"
							:class="$style.labelInput"
							data-test="link-picker-label-input">
						<div :class="$style.presetGroup">
							<button
								v-for="preset in LINK_LABEL_PRESETS"
								:key="preset"
								type="button"
								:class="[$style.chip, label === preset && $style.chipActive]"
								@click="label = preset">
								{{ preset }}
							</button>
						</div>
						<div :class="$style.styleToggle">
							<button
								type="button"
								:class="[$style.chip, linkStyle === 'box' && $style.chipActive]"
								data-test="link-picker-style-box"
								@click="linkStyle = 'box'">
								{{ t('sheetmusic', 'Box') }}
							</button>
							<button
								type="button"
								:class="[$style.chip, linkStyle === 'circle' && $style.chipActive]"
								data-test="link-picker-style-circle"
								@click="linkStyle = 'circle'">
								{{ t('sheetmusic', 'Circle') }}
							</button>
						</div>
						<label v-if="showReverseOption" :class="$style.reverseLabel">
							<input
								v-model="createReverse"
								type="checkbox"
								data-test="link-picker-reverse-checkbox">
							<span>{{ t('sheetmusic', 'Create reverse link') }}</span>
						</label>
					</div>

					<div :class="$style.footerActions">
						<NcButton variant="tertiary" @click="emit('close')">
							{{ t('sheetmusic', 'Cancel') }}
						</NcButton>
						<NcButton variant="primary" data-test="link-picker-save" @click="handleSave">
							{{ t('sheetmusic', 'Save Link') }}
						</NcButton>
					</div>
				</footer>
			</div>
		</div>
	</Teleport>
</template>

<style module>
.backdrop {
	position: fixed;
	inset: 0;
	z-index: 100020;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 16px;
	background: rgba(0, 0, 0, 0.55);
}

.modal {
	display: flex;
	flex-direction: column;
	width: min(920px, 96vw);
	max-height: 92vh;
	border-radius: 10px;
	background: var(--color-main-background, #ffffff);
	color: var(--color-main-text, #222222);
	box-shadow: 0 12px 36px rgba(0, 0, 0, 0.32);
	overflow: hidden;
}

.header {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 14px;
	border-bottom: 1px solid var(--color-border);
}

.hint {
	font-size: 0.82rem;
	color: var(--color-text-maxcontrast);
	margin-right: auto;
}

button[type='button'].closeBtn {
	margin: 0;
	padding: 4px;
	border: none;
	border-radius: 4px;
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
}

.panes {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12px;
	padding: 12px;
	overflow-y: auto;
	background: var(--color-background-hover);
}

.footer {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 10px;
	padding: 10px 14px;
	border-top: 1px solid var(--color-border);
}

.footerControls {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.labelInput {
	width: 170px;
	height: 30px;
	padding: 2px 8px;
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: 4px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.82rem;
}

.presetGroup,
.styleToggle {
	display: inline-flex;
	flex-wrap: wrap;
	gap: 4px;
}

button[type='button'].chip {
	margin: 0;
	padding: 3px 8px;
	min-height: 26px;
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: 999px;
	background: var(--color-background-hover);
	color: var(--color-main-text);
	font-size: 0.76rem;
	cursor: pointer;
}

button[type='button'].chipActive {
	border-color: var(--color-primary-element, #0082c9);
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #ffffff);
	font-weight: 600;
}

.reverseLabel {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	font-size: 0.82rem;
	cursor: pointer;
}

.footerActions {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
