<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import CoffeeOutlineIcon from 'vue-material-design-icons/CoffeeOutline.vue'
import type { SetlistItem } from '../../types/song'
import { formatDuration } from '../../utils/song-formatter'
import type { SetlistBreak } from '../../utils/setlist-timing'

const props = withDefaults(defineProps<{
	open: boolean
	setlistName: string
	items: SetlistItem[]
	breaks?: SetlistBreak[]
	currentIndex: number
}>(), {
	breaks: () => [],
})

const emit = defineEmits<{
	close: []
	select: [index: number]
}>()

const breakAfterIndex = computed(() => {
	const map = new Map<number, SetlistBreak>()
	for (const brk of props.breaks) {
		map.set(brk.afterIndex, brk)
	}
	return map
})
</script>

<template>
	<aside v-if="open" :class="$style.drawer" :aria-label="t('sheetmusic', 'Setlist items')">
		<div :class="$style.drawerHeader">
			<strong>{{ setlistName }}</strong>
			<NcButton variant="tertiary" size="small" @click="emit('close')">
				{{ t('sheetmusic', 'Close') }}
			</NcButton>
		</div>
		<ul :class="$style.drawerList">
			<template
				v-for="(item, idx) in items"
				:key="item.id ?? `${item.songId}-${idx}`">
				<li
					:class="[$style.drawerItem, idx === currentIndex && $style.drawerItemActive]"
					@click="emit('select', idx)">
					<span :class="$style.drawerIndex">{{ idx + 1 }}.</span>
					<div :class="$style.drawerInfo">
						<span :class="$style.drawerTitle">{{ item.songTitle || t('sheetmusic', 'Unknown Song') }}</span>
						<span v-if="item.bookmarkName" :class="$style.drawerBookmark">{{ item.bookmarkName }}</span>
					</div>
					<span v-if="item.duration" :class="$style.drawerDuration">{{ formatDuration(item.duration) }}</span>
				</li>
				<li
					v-if="breakAfterIndex.has(idx)"
					:class="$style.drawerBreak"
					:data-test="`drawer-break-${idx}`">
					<CoffeeOutlineIcon :size="14" />
					<span>{{ breakAfterIndex.get(idx)!.label }}</span>
					<span :class="$style.drawerBreakDur">{{ breakAfterIndex.get(idx)!.durationMinutes }} {{ t('sheetmusic', 'min') }}</span>
				</li>
			</template>
		</ul>
	</aside>
</template>

<style module>
.drawer {
	position: fixed;
	top: 0;
	right: 0;
	bottom: 0;
	width: min(340px, 85vw);
	z-index: 100001;
	display: flex;
	flex-direction: column;
	background-color: var(--color-main-background);
	border-left: 1px solid var(--color-border);
	box-shadow: -4px 0 16px rgba(0, 0, 0, 0.2);
}

.drawerHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
}

.drawerList {
	list-style: none;
	margin: 0;
	padding: 8px;
	overflow-y: auto;
	flex: 1;
}

.drawerItem {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 10px 12px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.drawerItem:hover {
	background-color: var(--color-background-hover);
}

.drawerItemActive {
	background-color: var(--color-primary-light);
	font-weight: 600;
}

.drawerIndex {
	color: var(--color-text-maxcontrast);
	min-width: 22px;
}

.drawerInfo {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.drawerTitle {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.drawerBookmark {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.drawerDuration {
	margin-left: auto;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
	flex-shrink: 0;
}

.drawerBreak {
	display: flex;
	align-items: center;
	gap: 6px;
	margin: 4px 8px;
	padding: 6px 10px;
	border: 1px dashed var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius, 6px);
	background: var(--color-background-hover);
	color: var(--color-primary-element);
	font-size: 0.82rem;
	font-weight: 600;
}

.drawerBreakDur {
	margin-left: auto;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
	font-variant-numeric: tabular-nums;
}
</style>
