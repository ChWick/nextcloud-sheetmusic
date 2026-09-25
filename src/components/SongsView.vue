<template>
  <ViewHeader
    :title="t('mobilesheetsmanager', 'Songs')"
    :create-label="t('mobilesheetsmanager', 'New song')"
    :filter-bar-visible="songIdInUrl === null ? filterBarVisible : undefined"
    @create="startNewSong"
    @toggle-filters="toggleFilterBar" />
  <!-- The URL opens one song full screen ("?song=12"); without it the list is shown. -->
  <div v-if="songIdInUrl !== null" :class="$style.fullscreenArea">
    <div :class="$style.fullscreenHeader">
      <NcButton @click="goBackToSongs">
        {{ t('mobilesheetsmanager', 'Back to songs') }}
      </NcButton>
    </div>
    <SongViewer
      :song="openSong"
      :library-id="selectedLibrary"
      :composers="composers"
      :books="books"
      :collections="collections"
      :artists="artists"
      @saved="handleSaved"
      @updated="handleLibraryDataUpdate"
      @deleted="handleDeleted" />
  </div>
  <div v-else :class="$style.songArea">
    <div v-if="filterBarVisible" :class="$style.filterBarWrapper"><SongFilterBar
      v-model:selected-composers="selectedComposers"
      v-model:selected-books="selectedBooks"
      v-model:selected-collections="selectedCollections"
      v-model:selected-artists="selectedArtists"
      :composers="composers"
      :books="books"
      :collections="collections"
      :artists="artists"
      @clear="clearFilters"/>
    </div>
    <div :class="$style.listColumn">
      <ul :class="$style.songList">
        <li v-for="song in filteredSongs" :key="song.id">
          <button type="button"
                  :class="$style.songButton"
                  @click="openSongView(song)">
            {{ song.title }}
          </button>
        </li>
        <li v-if="!isLoadingSongs && !fileError && songs.length === 0">
          {{ t('mobilesheetsmanager', 'No songs found in this library.') }}
        </li>
        <li v-else-if="!isLoadingSongs && !fileError && songs.length > 0 && filteredSongs.length === 0">
          {{ t('mobilesheetsmanager', 'No songs match the selected filters.') }}
        </li>
      </ul>
    </div>
    <div :class="$style.viewerArea">
      <SongEditor v-if="isCreatingSong"
                  :song="null"
                  :library-id="selectedLibrary"
                  :composers="composers"
                  :books="books"
                  :collections="collections"
                  :artists="artists"
                  @saved="handleSaved"
                  @cancel="isCreatingSong = false" />
      <SongViewer v-else
                  :song="null"
                  :library-id="selectedLibrary"
                  :composers="composers"
                  :books="books"
                  :collections="collections"
                  :artists="artists"
                  @saved="handleSaved"
                  @updated="handleLibraryDataUpdate"
                  @deleted="handleDeleted" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n';
import axios from '@nextcloud/axios';
import { generateOcsUrl } from '@nextcloud/router';
import { ref, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import NcButton from '@nextcloud/vue/components/NcButton';
import SongEditor from '../components/SongEditor.vue';
import SongFilterBar from '../components/SongFilterBar.vue';
import SongViewer from '../components/SongViewer.vue';
import ViewHeader from '../components/ViewHeader.vue';
import type { Artist, Song, Composer, Book, Collection, LibraryData } from '../types/song';
import { useSongFilters } from '../composables/useSongFilters';

// Props from parent (App.vue)
const props = defineProps<{ selectedLibrary: number }>();

// Local state for this view
const songs = ref<Song[]>([]);
const composers = ref<Composer[]>([]);
const books = ref<Book[]>([]);
const collections = ref<Collection[]>([]);
const artists = ref<Artist[]>([]);

// The open song lives in the URL query ("?song=12") like the filters do, so the
// browser history restores it and going back returns to the song list.
const route = useRoute();
const router = useRouter();

/** Id of the song the URL opens; null while the song list is shown. */
const songIdInUrl = computed(() => {
  const raw = route.query.song;
  const value = Array.isArray(raw) ? raw[0] : raw;
  const id = Number(value);
  return value === undefined || value === null || !Number.isInteger(id) || id <= 0 ? null : id;
});

/** The open song, resolved against the loaded library data. */
const openSong = computed(() => songs.value.find(song => song.id === songIdInUrl.value) ?? null);

// The filter bar reads and writes its selections in the URL query, so the browser
// history restores them when going back or forward.
const { selectedComposers, selectedBooks, selectedCollections, selectedArtists, filterSongs, clearFilters, filterBarVisible, toggleFilterBar } =
  useSongFilters(() => ({ composers: composers.value, books: books.value, collections: collections.value, artists: artists.value }));

const fileError = ref('');
const isLoadingSongs = ref(false);
/** The editor is open for a song that does not exist yet. */
const isCreatingSong = ref(false);

// Computed filtered list
const filteredSongs = computed(() => filterSongs(songs.value));

function handleLibraryDataUpdate(data: LibraryData) {
  songs.value = data.songs ?? [];
  composers.value = data.composers ?? [];
  books.value = data.books ?? [];
  collections.value = data.collections ?? [];
  artists.value = data.artists ?? [];
}

async function loadLibraryData(id: number) {
  isLoadingSongs.value = true;
  fileError.value = '';
  try {
    const response = await axios.get(generateOcsUrl('/apps/mobilesheetsmanager/api/songs'), { params: { id } });
    handleLibraryDataUpdate(response.data.ocs.data as LibraryData);
  } catch {
    fileError.value = t('mobilesheetsmanager', 'Could not load the songs in this library.');
  } finally {
    isLoadingSongs.value = false;
  }
}

// Watch for library prop changes
watch(() => props.selectedLibrary, (newLib) => {
  if (newLib) loadLibraryData(newLib);
}, { immediate: true });

/**
 * Open a song full screen. It becomes one history entry - the filters of the list
 * stay in the URL - so the browser back button returns to the song list as well.
 */
function openSongView(song: Song) {
  // Opening a song leaves the editor for a new song behind, like pressing Cancel.
  isCreatingSong.value = false;
  router.push({ query: { ...route.query, song: String(song.id) } });
}

function queryWithoutSong() {
  const query = { ...route.query };
  delete query.song;
  return query;
}

/** Return to the song list, which the URL keeps exactly as it was. */
function goBackToSongs() {
  if (window.history.state?.back) {
    router.back();
  } else {
    // A shared URL opened the song directly, so the list is entered anew.
    router.push({ query: queryWithoutSong() });
  }
}

/** Open the editor for a song that does not exist yet. Cancelling leaves no trace. */
function startNewSong() {
  isCreatingSong.value = true;
  // The editor is part of the list view; leave an open song for it.
  if (songIdInUrl.value !== null) {
    router.push({ query: queryWithoutSong() });
  }
}

/** The editor saved: refresh the library data and open the song that was saved. */
function handleSaved(data: LibraryData, songId?: number) {
  isCreatingSong.value = false;
  handleLibraryDataUpdate(data);

  if (songId === undefined) return;
  const saved = songs.value.find(song => song.id === songId);
  // Saving a song that is already open keeps its URL; the push is a no-op then.
  if (saved) openSongView(saved);
}

/**
 * The song was deleted. The refreshed data no longer contains it, so the URL has to
 * drop it explicitly: it would otherwise keep the removed song open.
 */
async function handleDeleted(data: LibraryData | undefined, songId: number) {
  if (data) {
    handleLibraryDataUpdate(data);
  } else {
    // The response carried no data, e.g. because it was empty; ask the server instead.
    await loadLibraryData(props.selectedLibrary);
  }
  if (songIdInUrl.value === songId) goBackToSongs();
}
</script>

<style module>
.songArea {
  margin: 16px;
  display: grid;
  grid-template-columns: minmax(220px, 320px) minmax(0, 1fr);
  grid-template-rows: auto 1fr;
  gap: 24px;
  align-items: start;
}
.songList {
  margin: 0;
  padding: 0;
  list-style: none;
}
.songList li {
  border-bottom: 1px solid var(--color-border);
}
.songButton {
  width: 100%;
  padding: 10px 0;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: start;
  cursor: pointer;
}
.songButton:hover {
  background: var(--color-background-hover);
}
.filterBarWrapper {
  grid-column: 1 / -1;
  margin-bottom: 12px;
}

.viewerArea {
  grid-column: 2;
  grid-row: 2;
}

.listColumn {
  grid-column: 1;
  grid-row: 2;
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-width: 0;
  overflow-y: auto;
}

.fullscreenArea {
  margin: 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.fullscreenHeader {
  display: flex;
}

.viewerArea {
  overflow-y: auto;
}

</style>
