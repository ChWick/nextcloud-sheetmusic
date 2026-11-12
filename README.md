# Sheet Music for Nextcloud

[![Nextcloud](https://img.shields.io/badge/Nextcloud-31%20--%2037-0082c9?logo=nextcloud&logoColor=white)](https://apps.nextcloud.com/)
[![License: AGPL-3.0-or-later](https://img.shields.io/badge/License-AGPL--3.0--or--later-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![Vue 3](https://img.shields.io/badge/Vue.js-3.5-4FC08D?logo=vuedotjs&logoColor=white)](https://vuejs.org/)
[![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![TypeScript](https://img.shields.io/badge/TypeScript-5.9-3178C6?logo=typescript&logoColor=white)](https://www.typescriptlang.org/)

**Sheet Music** is a comprehensive sheet music management, organization, and synchronization platform built natively for Nextcloud.

Whether you are a solo musician, choir director, orchestra member, or band leader, Sheet Music turns your Nextcloud instance into a centralized sheet music hub. It provides an intuitive web interface for browsing, editing, viewing, and processing scores, while serving as a seamless synchronization backend for digital sheet music readers—most notably **[MobileSheets](https://www.zubersoft.com/mobilesheets/)** across Android, iPadOS/iOS, and Windows.

---

## What This Project Is Really About

Managing digital sheet music across multiple devices, band members, or ensembles usually involves painful compromises:
- Sharing raw PDF files leaves libraries disorganized and strips essential metadata like tempo, key, composers, and collections.
- Syncing database files (like `mobilesheets.db`) directly over standard cloud storage often leads to file conflicts, locking issues, or overwrites.
- Different musicians in an ensemble (e.g., brass vs. woodwinds vs. rhythm section) need distinct subsets of scores rather than duplicating entire gigabytes of files.

**Sheet Music solves these problems at the architectural level:**
1. **Nextcloud as the Single Source of Truth**: Your songs, files, taxonomies, and setlists reside safely in Nextcloud's relational database and managed file storage.
2. **Virtual WebDAV Synchronization for MobileSheets**: Through custom Nextcloud SabreDAV integration, Sheet Music dynamically generates and serves virtual `mobilesheets.db` and `mobilesheets_hashcodes.txt` files on the fly. When MobileSheets syncs with your Nextcloud folder, Sheet Music imports and exports the SQLite database in real time—eliminating database file corruption and manual export/import steps.
3. **Sublibraries for Targeted Synchronization**: Define custom sublibraries (filtered by instrument, ensemble, or composer) that expose virtual WebDAV endpoints (e.g. `_SubLibraries_/Trumpet`). Musicians sync only the charts relevant to their part.
4. **All-in-One In-Browser Sheet Music Studio**: Edit metadata, assemble setlists, reorder pages, rotate or crop margins, and clean up scans directly in your browser without leaving Nextcloud.

---

## Key Features

### 📱 Transparent MobileSheets Sync & WebDAV Integration
- **Virtual SQLite Sync**: Serves dynamic `mobilesheets.db` and hash codes directly over WebDAV (`remote.php/dav/files/...`).
- **Live Bidirectional Updates**: Changes made in MobileSheets sync back to Nextcloud, and web edits are immediately available on tablets.
- **Sublibrary WebDAV Folders**: Expose filtered virtual directories (`.../<library>/_SubLibraries_/<name>`) for individual instruments or ensembles.

### 🎼 Comprehensive Score & Library Catalog
- **Multiple Layout Views**: Switch seamlessly between **Card View**, **Table View**, and **Simple List View**.
- **Rich Musical Metadata**:
  - Title & Alternative Titles
  - Composers, Lyricists, Artists, Arrangers
  - Books / Real Books / Anthologies
  - Collections, Genres, Custom Groups
  - Musical Key (with automated ♯/♭ normalization), Tempo (BPM), Time Signature
  - Difficulty, Star Rating, Duration, Years, Instruments, Notes
- **Smart Responsive Filter Bar**: Filter simultaneously by composers, books, collections, genres, keys, and ratings.
- **Deep Linking & Shareable URLs**: Filter states and active song selections sync with the browser address bar (`?composers=3&song=12`), enabling bookmarks, browser back/forward navigation, and instant sharing.
- **Inline Table Editing**: Edit metadata cells directly in the table view for rapid updates.
- **Batch Operations**: Bulk edit metadata, batch delete scores, and intelligently merge duplicate songs with interactive conflict resolution.
- **Taxonomy Management**: Dedicated management views for Composers, Artists, Books, Collections, Genres, and Keys with duplicate entity merging.
- **Multi-File Song Support**: Attach multiple scores, parts, lead sheets, or audio tracks to a single song with staged change staging.

### 📄 Full-Screen Score Viewer
- **Embedded PDF Score Viewer**: High-performance canvas-rendered viewer optimized for fast page flipping.
- **PDF Bookmarks & Movements Bar**: Quickly jump between score bookmarks and song movements.
- **External Viewer Option**: One-click opening into your browser's native viewer or desktop PDF application.

### 🛠️ In-Browser PDF Processing Tools
No need to install desktop PDF editors—Sheet Music includes built-in tools tailored for musicians:
- **Page Order Editor**: Easily configure complex repeat sequences (e.g., `1, 2, 3, 2, 4` for Codas and Da Capos) using visual drag-and-drop page cards, thumbnail previews, and sequence presets.
- **PDF Rotate**: Rotate individual pages or whole scores in 90° increments.
- **PDF Margin Cropping**: Crop whitespace margins interactively with visual guideline overlays to maximize score size on tablet and e-ink displays.
- **PDF Split & Join**: Extract individual songs from multi-page anthologies or join separate movement PDFs into a single piece.
- **Scan Enhancer**: Clean up scanned sheet music directly in the browser (contrast adjustment, grayscale conversion, adaptive binarization, deskewing) with an operation stack and undo/redo history.
- **Bookmarks & Movement Outlines**: Add, rename, and reorganize PDF document bookmarks.
- **File Version History**: Revert back to previous score revisions using Nextcloud's built-in versioning system.
- **Trash Bin Protection**: Deleted songs and associated files move to the Nextcloud Trash Bin where they can be restored safely.

### 📋 Setlist Management
- Create, rename, and manage setlists for rehearsals, gigs, and concerts.
- Add whole songs or specific bookmarked sections to setlists.
- Drag-and-drop reordering with persistent sorting modes.

### 🩺 Library Health & Maintenance Automation
- **Sync File Paths & Metadata**: Automatically organize physical files on disk according to customizable naming rules (`Composer/Title.pdf`), embed song metadata into standard PDF document properties (Title, Author, Subject, Keywords), and update MobileSheets hash codes.
- **Integrity Checker & Orphan Fixer**: Automated diagnostic tool that scans for missing files, duplicate file paths, naming rule mismatches, and orphan files in storage, offering one-click batch repairs.
- **Page Order Audit**: Audit and correct invalid or out-of-bounds page order definitions across your entire library.

---

## Architecture & How It Works

```
                     ┌─────────────────────────────────────────┐
                     │          Nextcloud Server               │
                     │                                         │
                     │   ┌─────────────────────────────────┐   │
                     │   │       Sheet Music App           │   │
                     │   │   (Vue 3 + PHP Backend)         │   │
                     │   └───────────────┬─────────────────┘   │
                     │                   │                     │
                     │   ┌───────────────┴─────────────────┐   │
                     │   │     Nextcloud Database          │   │
                     │   │   (Relational Data Store)       │   │
                     │   └───────────────┬─────────────────┘   │
                     │                   │                     │
                     │   ┌───────────────┴─────────────────┐   │
                     │   │    SabreDAV Plugin Layer        │   │
                     │   │  (Generates Virtual SQLite &    │   │
                     │   │   mobilesheets_hashcodes.txt)   │   │
                     │   └───────────────▲─────────────────┘   │
                     └───────────────────┼─────────────────────┘
                                         │ WebDAV Sync
                                         │
                         ┌───────────────┴───────────────┐
                         │                               │
                ┌────────▼────────┐             ┌────────▼────────┐
                │   MobileSheets  │             │   MobileSheets  │
                │ (Tablet / iOS)  │             │ (Android / PC)  │
                └─────────────────┘             └─────────────────┘
```

1. **Storage**: Your PDF, MusicXML, and audio files reside inside your standard Nextcloud Files folder (e.g., `SheetMusic/`).
2. **Virtual Database**: When MobileSheets connects to the folder via WebDAV, Sheet Music's DAV plugin intercepts requests for `mobilesheets.db` and dynamically exports the database from Nextcloud.
3. **Synchronization**: When MobileSheets syncs changes back, Sheet Music receives the SQLite file, extracts updates, and applies them to Nextcloud's database.
4. **PDF Metadata Reflection**: Standard document properties (Title, Authors/Composers, Keywords) are embedded into the PDF files themselves, ensuring external readers also recognize the score metadata.

---

## Requirements

- **Nextcloud**: `31.0.0` to `37.x`
- **PHP**: `8.1` or higher (with `pdo`, `sqlite3`, `zlib`, `mbstring`, and `gd` extensions enabled)
- **Database**: MariaDB / MySQL, PostgreSQL, or SQLite (managed by Nextcloud)
- **Client App (optional for tablet sync)**: MobileSheets by Zubersoft (v3.x or later recommended)

---

## Getting Started

### 1. Installation

#### Via Nextcloud App Store (Recommended)
1. Log in to your Nextcloud instance as an administrator.
2. Navigate to **Apps** > **Multimedia** / **Organization**.
3. Search for **Sheet Music** and click **Download and enable**.

#### Manual Installation
1. Clone or extract this repository into your Nextcloud `apps-extra/` (or `apps/`) directory:
   ```bash
   cd /path/to/nextcloud/apps-extra
   git clone https://github.com/ChWick/nextcloud-sheetmusic.git sheetmusic
   ```
2. Enable the app using the Nextcloud command line:
   ```bash
   php occ app:enable sheetmusic
   ```

### 2. Creating a Library
1. Open **Sheet Music** from the Nextcloud top navigation bar.
2. Click **Add Library** in the sidebar.
3. You can either:
   - **Create New Library**: Select any Nextcloud folder containing your sheet music files.
   - **Import Existing Library**: Point to an existing `mobilesheets.db` file already in your Nextcloud files to immediately restore your songs, setlists, and collections.

### 3. Setting Up MobileSheets Sync

To connect MobileSheets on your tablet or desktop to Nextcloud:

1. Open **MobileSheets** > **Settings** > **Storage** > **Set MobileSheets Storage Location** (or **Sync Library** > **Sync with a cloud folder** / **WebDAV**).
2. Choose **WebDAV** as the connection protocol.
3. Enter the WebDAV connection details:
   - **Server URL**: `https://<your-nextcloud-domain>/remote.php/dav/files/<username>/<path-to-library-folder>`
   - **Username**: Your Nextcloud username
   - **Password**: A dedicated Nextcloud **App Password** (generated under *Personal Settings* > *Security* > *Devices & sessions*)
4. Start synchronization. MobileSheets will automatically detect the virtual `mobilesheets.db` and synchronize your scores and metadata!

#### Syncing a Sublibrary (e.g. for Ensembles or Specific Instruments)
If you created a Sublibrary in Sheet Music (e.g., named `Saxophone`):
- Set the WebDAV path in MobileSheets to:
  ```
  https://<your-nextcloud-domain>/remote.php/dav/files/<username>/<path-to-library-folder>/_SubLibraries_/Saxophone
  ```
Only the songs assigned to that sublibrary will be synchronized to the device.

---

## Development

The repository includes a complete local development environment using Docker Compose.

### Starting the Development Environment

```bash
# Start Nextcloud container
docker compose -f .docker/docker-compose.yml up -d

# Enable the Sheet Music app in the container
docker compose -f .docker/docker-compose.yml exec nextcloud php occ app:enable sheetmusic

# Install frontend dependencies and run the file watcher
npm install
npm run watch
```

### Hot Reloading & PHP Opcache
The frontend is compiled directly into the mounted app directory. PHP files are loaded by the container; `.docker/opcache-dev.ini` is mounted into the container to apply PHP changes immediately:

```bash
docker compose -f .docker/docker-compose.yml up -d --force-recreate nextcloud
```

If you add new routes in `appinfo/routes.php` or change DI containers, restart the container:
```bash
docker compose -f .docker/docker-compose.yml restart nextcloud
```

### Testing & Code Quality

```bash
# Frontend Unit & Component Tests (Vitest)
npm run test:frontend

# End-to-End Tests (Playwright)
npm run test:e2e

# Code Style & Linter Checks
npm run lint
npm run stylelint
composer cs:check
composer psalm

# PHP Unit Tests
composer test:unit
```

---

## License

This project is licensed under the **GNU Affero General Public License v3.0 or later** ([AGPL-3.0-or-later](LICENSE)).

## Author & Support

- **Author**: Christoph Wick ([wick.chr.info@gmail.com](mailto:wick.chr.info@gmail.com))
- **Bug Tracker & Issues**: [GitHub Issues](https://github.com/ChWick/nextcloud-sheetmusic/issues)
- **Nextcloud Developer Documentation**: [https://docs.nextcloud.com/](https://docs.nextcloud.com/)
