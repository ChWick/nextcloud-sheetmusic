# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.0.3-rc7] - 2026-11-22

### Added

- **Offline Setlists & Simulated Offline Mode**: Save setlists for offline use with per-setlist sync indicators, instant offline playback from Cache Storage, automatic re-sync on app open/reconnect, and a simulated offline mode toggle in the sidebar.
- **PWA Update Progress & App Version in Settings**: Automatic asset-hash cache versioning, streaming frontend update progress banner with a deferred non-interrupting **Reload** prompt, `/app-version` endpoint, `vite:preloadError` recovery, and an **App Version** section (version, release date, build hash, manual update check) in Settings.
- **Stylus Calibration, Pressure Sensitivity & Palm Rejection**: Added stylus offset calibration, pressure-sensitive annotation strokes, hardware eraser support, and automatic palm rejection.
- **Zoom, Panning & Single-Song Performance Mode**: Added touch pinch-to-zoom and panning (including in annotation mode), Ctrl+wheel zoom and mouse drag panning, and single-song playback via the performance viewer.
- **UI Refinements & Per-Library Sync Control**: Consolidated Songs view/sort/column controls, added progressive disclosure for secondary filters, reusable not-found states for missing routes/entities, and per-library MobileSheets WebDAV sync gating.

### Fixed

- **Double-Page PDF Layout**: Enforced strict 50/50 CSS grid columns and independent per-page sizing for mixed-size and cropped horizontal PDFs.
- **PWA Caching & Build Hygiene**: Fixed Service Worker entry revalidation, precached JS/CSS and PDF.js worker chunks, enabled production Vite minification, and cleaned stale generated chunks before builds.
- **Share Target & Viewer Fixes**: Refreshed active views after share imports with upload rollback on failure, kept stage click zones active when zoomed in, and improved HiDPI PDF rendering performance.

## [0.0.3-rc6] - 2026-11-17

### Fixed

- **Dynamic Manifest Endpoint**: Served the PWA web app manifest from `PageController::manifest()` (`/pwa-manifest`) and updated `<link rel="manifest">` at startup via `initPwaManifestLink()` so it works even on servers where static `.json` files in `custom_apps/` are blocked or rewritten.

## [0.0.3-rc5] - 2026-11-17

### Fixed

- **PWA Packaging & WebAPK Icons**: Included `resources/` (`resources/pwa-sw.js`) in the release archive (`release.yml`) and simplified `manifest.json` icons to PNG-only (`192x192`, `512x512`) for Android WebAPK compatibility.

## [0.0.3-rc4] - 2026-11-17

### Added

- **PWA App Manifest & Fullscreen Standalone Mode**: Added Sheet Music `manifest.json`, PWA icons (`pwa-icon.svg`, `pwa-icon-192.png`, `pwa-icon-512.png`, `favicon-touch.png`, `favicon-mask.svg`), and standalone layout styling that hides Nextcloud's global `#header` toolbar and expands the app to fill `100dvh`.
- **Web Share Target for PDF & DOCX**: Added `share_target` manifest support, a dedicated `/sw.js` Service Worker endpoint (`resources/pwa-sw.js`) with a server-side `POST` fallback in `PageController`, and a 3-step `ShareTargetDialog` wizard to import shared `.pdf` / `.docx` files as a **New song**, **Add to existing song**, **Annotation of setlist**, or **New setlist**.

## [0.0.3-rc3] - 2026-11-17

### Added

- **Setlist Performance Mini-Page-Preview**: Added a thumbnail strip in the setlist play bottom bar displaying all setlist songs and pages with mouse-wheel and drag-to-scroll support.
- **Setlist & File Annotations**: Added database migration (`Version1011Date20261117120000`), backend mapper/service/controller support, and a setlist file preview dialog (`SetlistFilePreviewDialog`) powered by a shared `AnnotatableFilePreview` component and `SongAnnotationLogSelect`.

### Changed

- **Setlist & Viewer UI**: Refined `SetlistPerformanceBottomBar` with icon-only buttons and tooltips, moved the setlist items/bookmarks drawer button to the bottom bar, and enabled auto-wrapping for `SetlistDetailHeader` actions.
- **App Store & CI**: Prioritized core Nextcloud features in `appinfo/info.xml` `<description>` and updated `shivammathur/setup-php` to `v2.40.0` (Node.js 24) in `release.yml`.

## [0.0.3-rc2] - 2026-11-17

### Added

- **Initial release of Sheet Music for Nextcloud**.
- **Transparent MobileSheets Synchronization**:
  - Virtual WebDAV synchronization dynamically serving and parsing `mobilesheets.db` and hash codes on the fly.
  - Sublibrary WebDAV endpoints allowing musicians to sync only charts relevant to their instrument or ensemble.
- **Comprehensive Score & Library Management**:
  - Song catalog with customizable layouts (Card, Table, and List views).
  - Rich musical metadata editing: title, composers, lyricists, artists, arrangers, books, collections, genres, keys, tempo, and time signatures.
  - Dedicated taxonomy management views for Composers, Artists, Books, Collections, and Genres with entity merging.
  - Smart responsive filtering by multiple metadata criteria with shareable, deep-linkable URLs.
  - Batch operations including bulk metadata editing, batch deletion, and duplicate song merging.
- **Built-in Score Viewer & Processing**:
  - Responsive in-browser PDF score viewer with external viewer support.
  - Non-destructive PDF processing: page reordering, rotation, extraction, margin cropping, and song splitting.
- **Library Maintenance & Automation**:
  - Configurable file naming rules and path synchronization to keep file storage organized.
  - Automated PDF document properties embedding (title, composers, collections) synced with Nextcloud facts.
  - Library maintenance tool to inspect, verify hash codes, and repair orphan files or database mismatches.
- **Sharing & Collaboration**:
  - Share complete sheet music libraries and song collections across Nextcloud users and groups.
