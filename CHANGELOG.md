# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.0.1] - 2026-11-17

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
