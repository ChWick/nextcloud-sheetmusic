<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import FormatListNumbered from 'vue-material-design-icons/FormatListNumbered.vue'
import Wrench from 'vue-material-design-icons/Wrench.vue'
import type { ToolDefinition } from '../types/tools'
import ViewHeader from './ViewHeader.vue'
import PageOrderResetTool from './tools/PageOrderResetTool.vue'

const props = defineProps<{
	selectedLibrary: number
}>()

const route = useRoute()
const router = useRouter()

const availableTools: ToolDefinition[] = [
	{
		id: 'page-order-reset',
		name: t('sheetmusic', 'Reset Page Ordering'),
		description: t('sheetmusic', 'Audit and reset page ordering for multi-page songs'),
		icon: FormatListNumbered,
	},
]

const activeToolId = ref<string>('page-order-reset')

// Sync with route query ?tool=
watch(
	() => route.query.tool,
	(toolQuery) => {
		if (typeof toolQuery === 'string' && availableTools.some(tool => tool.id === toolQuery)) {
			activeToolId.value = toolQuery
		}
	},
	{ immediate: true },
)

function selectTool(id: string) {
	activeToolId.value = id
	router.replace({
		query: {
			...route.query,
			tool: id,
		},
	})
}

const activeTool = computed(() => {
	return availableTools.find(t => t.id === activeToolId.value) ?? availableTools[0]
})
</script>

<template>
	<div :class="$style.container" data-test="tools-view">
		<ViewHeader :title="t('sheetmusic', 'Library Tools')">
			<div v-if="availableTools.length > 1" :class="$style.toolSelector">
				<button
					v-for="tool in availableTools"
					:key="tool.id"
					type="button"
					:class="[$style.toolTab, activeToolId === tool.id && $style.toolTabActive]"
					@click="selectTool(tool.id)">
					<component :is="tool.icon" :size="18" />
					<span>{{ tool.name }}</span>
				</button>
			</div>
		</ViewHeader>

		<div :class="$style.content">
			<PageOrderResetTool
				v-if="activeToolId === 'page-order-reset'"
				:selected-library="selectedLibrary" />
		</div>
	</div>
</template>

<style module>
.container {
	display: flex;
	flex-direction: column;
	height: 100%;
	width: 100%;
	overflow-y: auto;
}

.content {
	padding: 24px 32px;
	max-width: 1400px;
	width: 100%;
	box-sizing: border-box;
	margin: 0 auto;
}

.toolSelector {
	display: flex;
	align-items: center;
	gap: 8px;
}

.toolTab {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	border-radius: var(--border-radius-pill, 18px);
	border: 1px solid var(--color-border, #ccc);
	background: transparent;
	color: var(--color-main-text, #333);
	cursor: pointer;
	font-size: 0.85rem;
}

.toolTabActive {
	background: var(--color-primary-element, #0082c9);
	border-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
}
</style>
