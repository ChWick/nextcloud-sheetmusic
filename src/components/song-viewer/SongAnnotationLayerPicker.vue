<script setup lang="ts">
import type { AnnotationLayer, SheetAnnotation } from '../../types/annotation'
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import Check from 'vue-material-design-icons/Check.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import EyeOffOutline from 'vue-material-design-icons/EyeOffOutline.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import LayersOutline from 'vue-material-design-icons/LayersOutline.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ViewportPopover from '../ViewportPopover.vue'
import {
	DEFAULT_LAYER_ID,
	getAnnotationLayerIds,
} from '../../utils/annotationLayers'

const props = withDefaults(
	defineProps<{
		layers: AnnotationLayer[]
		activeLayerId: number
		selectedAnnotation?: SheetAnnotation | null
	}>(),
	{
		selectedAnnotation: null,
	},
)

const emit = defineEmits<{
	'update:activeLayerId': [id: number]
	'add-layer': [name: string]
	'rename-layer': [id: number, name: string]
	'toggle-visibility': [id: number]
	'reorder-layer': [id: number, direction: 'up' | 'down']
	'delete-layer': [id: number]
	'update-selected-layers': [layerIds: number[]]
}>()

const open = ref(false)
const newLayerName = ref('')
const editingLayerId = ref<number | null>(null)
const editingLayerName = ref('')

const activeLayer = computed(() =>
	props.layers.find((l) => l.id === props.activeLayerId) ?? props.layers[0],
)

const visibleCount = computed(() => props.layers.filter((l) => l.visible).length)

const selectedLayerIds = computed<number[]>(() =>
	props.selectedAnnotation ? getAnnotationLayerIds(props.selectedAnnotation) : [],
)

function startRename(layer: AnnotationLayer) {
	editingLayerId.value = layer.id
	editingLayerName.value = layer.name
}

function commitRename() {
	if (editingLayerId.value === null) return
	const trimmed = editingLayerName.value.trim()
	if (trimmed) {
		emit('rename-layer', editingLayerId.value, trimmed)
	}
	editingLayerId.value = null
	editingLayerName.value = ''
}

function cancelRename() {
	editingLayerId.value = null
	editingLayerName.value = ''
}

function submitNewLayer() {
	const name = newLayerName.value.trim()
	emit('add-layer', name)
	newLayerName.value = ''
}

function toggleSelectedLayerMembership(layerId: number) {
	if (!props.selectedAnnotation) return
	const current = selectedLayerIds.value
	const has = current.includes(layerId)
	if (has) {
		if (current.length <= 1) return
		emit('update-selected-layers', current.filter((id) => id !== layerId))
	} else {
		emit('update-selected-layers', [...current, layerId])
	}
}
</script>

<template>
	<ViewportPopover
		v-model:open="open"
		pane-test-id="annotation-layer-popover"
		:pane-class="$style.popoverPane"
		:gap="4">
		<template #trigger="{ toggle }">
			<button
				type="button"
				:class="$style.triggerBtn"
				:title="t('sheetmusic', 'Annotation layers')"
				:aria-label="t('sheetmusic', 'Annotation layers')"
				:aria-expanded="open"
				data-test="annotation-layers-trigger"
				@click="toggle">
				<LayersOutline :size="18" />
				<span :class="$style.triggerName">{{ activeLayer?.name ?? t('sheetmusic', 'Default') }}</span>
				<span
					v-if="layers.length > 1"
					:class="$style.countBadge"
					data-test="annotation-layers-count">
					{{ visibleCount }}/{{ layers.length }}
				</span>
			</button>
		</template>

		<!-- Selected Annotation Layer Assignment (multi-layer membership) -->
		<div
			v-if="selectedAnnotation"
			:class="$style.section"
			data-test="selected-annotation-layers">
			<div :class="$style.sectionTitle">
				{{ t('sheetmusic', 'Selected annotation layers') }}
			</div>
			<div :class="$style.checkboxList">
				<label
					v-for="layer in layers"
					:key="`sel-${layer.id}`"
					:class="$style.checkboxRow">
					<input
						type="checkbox"
						:checked="selectedLayerIds.includes(layer.id)"
						:disabled="selectedLayerIds.includes(layer.id) && selectedLayerIds.length <= 1"
						:data-test="`selected-layer-checkbox-${layer.id}`"
						@change="toggleSelectedLayerMembership(layer.id)">
					<span :class="$style.checkboxLabel">{{ layer.name }}</span>
				</label>
			</div>
		</div>

		<!-- Layer Manager List -->
		<div :class="$style.section">
			<div :class="$style.sectionTitle">
				{{ t('sheetmusic', 'Layers') }}
			</div>
			<div :class="$style.layerList">
				<div
					v-for="(layer, idx) in layers"
					:key="layer.id"
					:class="[$style.layerRow, layer.id === activeLayerId && $style.layerRowActive]"
					:data-test="`layer-row-${layer.id}`">
					<button
						type="button"
						:class="[$style.iconBtn, !layer.visible && $style.dimmed]"
						:title="layer.visible ? t('sheetmusic', 'Hide layer') : t('sheetmusic', 'Show layer')"
						:aria-label="layer.visible ? t('sheetmusic', 'Hide layer') : t('sheetmusic', 'Show layer')"
						:data-test="`layer-toggle-visible-${layer.id}`"
						@click="emit('toggle-visibility', layer.id)">
						<EyeOutline v-if="layer.visible" :size="16" />
						<EyeOffOutline v-else :size="16" />
					</button>

					<template v-if="editingLayerId === layer.id">
						<input
							v-model="editingLayerName"
							type="text"
							:class="$style.renameInput"
							:aria-label="t('sheetmusic', 'Rename layer')"
							:data-test="`layer-rename-input-${layer.id}`"
							@keydown.enter.prevent="commitRename"
							@keydown.esc.prevent="cancelRename"
							@blur="commitRename">
						<button
							type="button"
							:class="$style.iconBtn"
							:title="t('sheetmusic', 'Save name')"
							:aria-label="t('sheetmusic', 'Save name')"
							@mousedown.prevent="commitRename">
							<Check :size="16" />
						</button>
					</template>
					<template v-else>
						<button
							type="button"
							:class="[$style.layerNameBtn, !layer.visible && $style.dimmed]"
							:title="t('sheetmusic', 'Draw on this layer')"
							:data-test="`layer-select-${layer.id}`"
							@click="emit('update:activeLayerId', layer.id)">
							{{ layer.name }}
						</button>

						<div :class="$style.rowActions">
							<button
								type="button"
								:class="$style.iconBtn"
								:disabled="idx === 0"
								:title="t('sheetmusic', 'Move layer up')"
								:aria-label="t('sheetmusic', 'Move layer up')"
								:data-test="`layer-move-up-${layer.id}`"
								@click="emit('reorder-layer', layer.id, 'up')">
								<ArrowUp :size="15" />
							</button>
							<button
								type="button"
								:class="$style.iconBtn"
								:disabled="idx === layers.length - 1"
								:title="t('sheetmusic', 'Move layer down')"
								:aria-label="t('sheetmusic', 'Move layer down')"
								:data-test="`layer-move-down-${layer.id}`"
								@click="emit('reorder-layer', layer.id, 'down')">
								<ArrowDown :size="15" />
							</button>
							<button
								type="button"
								:class="$style.iconBtn"
								:title="t('sheetmusic', 'Rename layer')"
								:aria-label="t('sheetmusic', 'Rename layer')"
								:data-test="`layer-rename-btn-${layer.id}`"
								@click="startRename(layer)">
								<PencilOutline :size="15" />
							</button>
							<button
								v-if="layer.id !== DEFAULT_LAYER_ID"
								type="button"
								:class="[$style.iconBtn, $style.deleteBtn]"
								:title="t('sheetmusic', 'Delete layer')"
								:aria-label="t('sheetmusic', 'Delete layer')"
								:data-test="`layer-delete-${layer.id}`"
								@click="emit('delete-layer', layer.id)">
								<DeleteOutline :size="15" />
							</button>
						</div>
					</template>
				</div>
			</div>
		</div>

		<!-- Add Layer Row -->
		<div :class="$style.addRow">
			<input
				v-model="newLayerName"
				type="text"
				:class="$style.addInput"
				:placeholder="t('sheetmusic', 'New layer name...')"
				:aria-label="t('sheetmusic', 'New layer name')"
				data-test="layer-add-input"
				@keydown.enter.prevent="submitNewLayer">
			<button
				type="button"
				:class="$style.addBtn"
				:title="t('sheetmusic', 'Add layer')"
				:aria-label="t('sheetmusic', 'Add layer')"
				data-test="layer-add-submit"
				@click="submitNewLayer">
				<Plus :size="16" />
			</button>
		</div>
	</ViewportPopover>
</template>

<style module>
button[type='button'].triggerBtn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 5px;
	width: auto;
	min-height: var(--default-clickable-area, 44px);
	margin: 0;
	padding: 4px 10px;
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.82rem;
	cursor: pointer;
	white-space: nowrap;
}

button[type='button'].triggerBtn:hover {
	background: var(--color-background-hover);
}

.triggerName {
	max-width: 110px;
	overflow: hidden;
	text-overflow: ellipsis;
}

.countBadge {
	padding: 1px 5px;
	border-radius: 999px;
	background: var(--color-primary-light, #e6f4fa);
	color: var(--color-primary-element, #0082c9);
	font-size: 0.72rem;
	font-weight: 600;
}

.popoverPane {
	min-width: 270px;
	max-width: min(340px, calc(100vw - 24px));
	padding: 8px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.section {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.sectionTitle {
	font-size: 0.75rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	padding: 0 4px;
}

.checkboxList {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	padding: 4px;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 2px;
}

.checkboxRow {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 0.82rem;
	cursor: pointer;
	padding: 2px 6px;
	border-radius: 4px;
	background: var(--color-background-hover);
}

.checkboxLabel {
	max-width: 120px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.layerList {
	display: flex;
	flex-direction: column;
	gap: 3px;
	max-height: 240px;
	overflow-y: auto;
}

.layerRow {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 3px 4px;
	border-radius: 4px;
	border: 1px solid transparent;
}

.layerRowActive {
	background: var(--color-primary-light, #e6f4fa);
	border-color: var(--color-primary-element, #0082c9);
}

button[type='button'].layerNameBtn {
	flex: 1;
	min-width: 0;
	text-align: left;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	padding: 4px 6px;
	margin: 0;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.84rem;
	cursor: pointer;
}

.layerRowActive .layerNameBtn {
	font-weight: 600;
	color: var(--color-primary-element, #0082c9);
}

.rowActions {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	flex-shrink: 0;
}

button[type='button'].iconBtn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 26px;
	height: 26px;
	min-width: 26px;
	min-height: 26px;
	margin: 0;
	padding: 0;
	border: none;
	border-radius: 4px;
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
}

button[type='button'].iconBtn:hover:not(:disabled) {
	background: var(--color-background-hover);
}

button[type='button'].iconBtn:disabled {
	opacity: 0.35;
	cursor: default;
}

button[type='button'].deleteBtn:hover {
	color: var(--color-error, #e53935);
}

.dimmed {
	opacity: 0.5;
}

.renameInput,
.addInput {
	flex: 1;
	min-width: 0;
	height: 28px;
	padding: 0 8px;
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.82rem;
}

.addRow {
	display: flex;
	align-items: center;
	gap: 4px;
	padding-top: 6px;
	border-top: 1px solid var(--color-border);
}

button[type='button'].addBtn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 30px;
	height: 28px;
	min-width: 30px;
	min-height: 28px;
	margin: 0;
	padding: 0;
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

button[type='button'].addBtn:hover {
	background: var(--color-background-hover);
}
</style>
