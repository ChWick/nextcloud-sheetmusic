<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SheetMusic\Sync\MobileSheets;

use OCA\SheetMusic\Db\InternalSongAnnotation;
use OCA\SheetMusic\Util\SvgCurveUtil;
use PDO;

class MobileSheetsAnnotationAdapter {
	public const FLOAT_MAX = 3.4028234663852886e+38;

	/**
	 * Import all annotations from a MobileSheets SQLite database for a given song.
	 *
	 * @return list<InternalSongAnnotation>
	 */
	public function importAnnotations(
		PDO $db,
		int $songId,
		int $fileId,
		int $clientSongId,
	): array {
		if (!MobileSheetsTableHelper::tableExists($db, 'AnnotationsBase')) {
			return [];
		}

		$basesStmt = $db->prepare('SELECT * FROM AnnotationsBase WHERE SongId = :songId ORDER BY Page ASC, Layer ASC, Id ASC');
		$basesStmt->execute([':songId' => $clientSongId]);
		$bases = $basesStmt->fetchAll(PDO::FETCH_ASSOC);

		if (empty($bases)) {
			return [];
		}

		$pointsMap = [];
		if (MobileSheetsTableHelper::tableExists($db, 'AnnotationPoints')) {
			$ptsStmt = $db->prepare('
				SELECT p.* FROM AnnotationPoints p
				JOIN AnnotationsBase a ON a.Id = p.AnnotationId
				WHERE a.SongId = :songId
			');
			$ptsStmt->execute([':songId' => $clientSongId]);
			while ($row = $ptsStmt->fetch(PDO::FETCH_ASSOC)) {
				$pointsMap[(int)$row['AnnotationId']] = $row;
			}
		}

		$drawsMap = [];
		if (MobileSheetsTableHelper::tableExists($db, 'DrawAnnotations')) {
			$dStmt = $db->prepare('
				SELECT d.* FROM DrawAnnotations d
				JOIN AnnotationsBase a ON a.Id = d.BaseId
				WHERE a.SongId = :songId
			');
			$dStmt->execute([':songId' => $clientSongId]);
			while ($row = $dStmt->fetch(PDO::FETCH_ASSOC)) {
				$drawsMap[(int)$row['BaseId']] = $row;
			}
		}

		$stampsMap = [];
		if (MobileSheetsTableHelper::tableExists($db, 'StampAnnotations')) {
			$sStmt = $db->prepare('
				SELECT s.* FROM StampAnnotations s
				JOIN AnnotationsBase a ON a.Id = s.BaseId
				WHERE a.SongId = :songId
			');
			$sStmt->execute([':songId' => $clientSongId]);
			while ($row = $sStmt->fetch(PDO::FETCH_ASSOC)) {
				$stampsMap[(int)$row['BaseId']] = $row;
			}
		}

		$textsMap = [];
		if (MobileSheetsTableHelper::tableExists($db, 'TextboxAnnotations')) {
			$tStmt = $db->prepare('
				SELECT t.* FROM TextboxAnnotations t
				JOIN AnnotationsBase a ON a.Id = t.BaseId
				WHERE a.SongId = :songId
			');
			$tStmt->execute([':songId' => $clientSongId]);
			while ($row = $tStmt->fetch(PDO::FETCH_ASSOC)) {
				$textsMap[(int)$row['BaseId']] = $row;
			}
		}

		$annotations = [];
		foreach ($bases as $base) {
			$baseId = (int)$base['Id'];
			$draw = $drawsMap[$baseId] ?? null;
			$stamp = $stampsMap[$baseId] ?? null;
			$textbox = $textsMap[$baseId] ?? null;
			$pointsRow = $pointsMap[$baseId] ?? null;
			$pointsBlob = $pointsRow['Points'] ?? null;

			$ann = self::parseAnnotationRecord($base, $draw, $stamp, $textbox, $pointsBlob, $songId, $fileId, $pointsRow);
			if ($ann !== null) {
				$annotations[] = $ann;
			}
		}

		return $annotations;
	}

	/**
	 * Parse a single MobileSheets annotation and its related table records into an InternalSongAnnotation.
	 */
	public static function parseAnnotationRecord(
		array $base,
		?array $draw,
		?array $stamp,
		?array $textbox,
		?string $pointsBlob,
		int $songId,
		int $fileId,
		?array $pointsRow = null,
	): ?InternalSongAnnotation {
		$baseId = (int)($base['Id'] ?? 0);
		$page = (int)($base['Page'] ?? 0);
		$rawType = (int)($base['Type'] ?? 1);
		$sourceW = (float)($base['SourcePageWidth'] ?: 595.0);
		$sourceH = (float)($base['SourcePageHeight'] ?: 842.0);
		$opacity = (int)($base['Opacity'] ?? 100) / 100.0;
		$layer = (int)($base['Layer'] ?? 0);

		$baseClientMeta = [
			'clientRecordId' => (string)$baseId,
			'sourceWidth' => $sourceW,
			'sourceHeight' => $sourceH,
			'zoom' => (float)($base['Zoom'] ?? 1.0),
			'zoomY' => (float)($base['ZoomY'] ?? 1.0),
			'version' => (int)($base['Version'] ?? 3),
			'mobilesheetsType' => $rawType,
		];

		if (is_string($pointsBlob) && str_starts_with($pointsBlob, '__b64:')) {
			$pointsBlob = base64_decode(substr($pointsBlob, 6));
		}

		if (is_string($pointsBlob) && $pointsBlob !== '') {
			$baseClientMeta['rawPointsBlob'] = base64_encode($pointsBlob);
			if ($pointsRow !== null) {
				$baseClientMeta['pointsCount'] = (int)($pointsRow['Count'] ?? 0);
				$baseClientMeta['pointsId'] = (int)($pointsRow['Id'] ?? $baseId);
			}
		}

		$points = [];
		$aperture = null;
		if (is_string($pointsBlob) && strlen($pointsBlob) >= 16) {
			$points = self::unpackPointsBlob($pointsBlob, $sourceW, $sourceH);
			if (strlen($pointsBlob) === 40) {
				$unpackedDoubles = array_values(unpack('d*', $pointsBlob) ?: []);
				if (count($unpackedDoubles) === 5) {
					$aperture = (float)$unpackedDoubles[4];
					$baseClientMeta['aperture'] = $aperture;
				}
			}
		}

		$type = match ($rawType) {
			0 => 'text',
			2 => 'highlight',
			3 => 'stamp',
			6 => 'shape',
			default => ($draw['DrawMode'] ?? 0) === 1 ? 'shape' : ($opacity < 0.6 ? 'highlight' : 'drawing'),
		};

		if ($type === 'text' && $textbox !== null) {
			$textColor = self::argbToHex((int)($textbox['TextColor'] ?? -16777216));
			$text = (string)($textbox['Text'] ?? '');
			$fontSize = (float)($textbox['FontSize'] ?? 14.0);
			$rawFontFamily = (string)($textbox['FontFamily'] ?? '0');
			$fontFamily = match ($rawFontFamily) {
				'1' => 'serif',
				'2' => 'monospace',
				default => 'sans-serif',
			};
			$scaledFontSize = round($fontSize / ($sourceW > 0 ? $sourceW : 595.0) * 1000, 1);
			$bbox = !empty($points) ? self::calculateBoundingBox($points) : ['x' => 0.1, 'y' => 0.1, 'width' => 0.3, 'height' => 0.05];

			$data = array_merge($baseClientMeta, [
				'text' => $text,
				'fontSize' => $fontSize,
				'scaledFontSize' => $scaledFontSize,
				'fontFamily' => $fontFamily,
				'rawFontFamily' => $rawFontFamily,
				'textboxId' => (int)($textbox['Id'] ?? $baseId),
				'fontStyle' => (int)($textbox['FontStyle'] ?? 0),
				'fillColor' => (int)($textbox['FillColor'] ?? 0),
				'borderColor' => (int)($textbox['BorderColor'] ?? 0),
				'textAlign' => (int)($textbox['TextAlign'] ?? 0),
				'hasBorder' => (int)($textbox['HasBorder'] ?? 0),
				'borderWidth' => (float)($textbox['BorderWidth'] ?? 0.0),
				'autoSize' => (int)($textbox['AutoSize'] ?? 1),
				'lineSpacing' => (float)($textbox['LineSpacing'] ?? 1.0),
			]);

			return new InternalSongAnnotation(
				id: null,
				uuid: self::generateDeterministicUuid("mobilesheets_text_{$songId}", $baseId),
				songId: $songId,
				fileId: $fileId,
				pageNumber: $page,
				layerIndex: $layer,
				type: 'text',
				x: $bbox['x'],
				y: $bbox['y'],
				width: $bbox['width'],
				height: $bbox['height'],
				style: [
					'color' => $textColor,
					'fontSize' => $scaledFontSize,
					'fontFamily' => $fontFamily,
				],
				data: $data,
			);
		}

		if ($type === 'stamp' && $stamp !== null) {
			$color = self::argbToHex((int)($stamp['Color'] ?? -16777216));
			$size = (float)($stamp['Size'] ?? 40.0);
			$font = (int)($stamp['Font'] ?? 1);
			$symbol = (string)($stamp['Symbol'] ?? 'q');
			$displaySymbol = self::mapMobileSheetsSymbol($symbol, $font);

			$normW = round($size / $sourceW, 4);
			$normH = round($size / $sourceH, 4);
			$x = !empty($points) ? $points[0]['x'] : 0.0;
			$y = !empty($points) ? $points[0]['y'] : 0.0;

			$data = array_merge($baseClientMeta, [
				'symbol' => $symbol,
				'displaySymbol' => $displaySymbol,
				'size' => $size,
				'font' => $font,
				'stampId' => (int)($stamp['Id'] ?? $baseId),
				'stampType' => (int)($stamp['Type'] ?? 2),
				'filePath' => (string)($stamp['FilePath'] ?? ''),
			]);

			return new InternalSongAnnotation(
				id: null,
				uuid: self::generateDeterministicUuid("mobilesheets_stamp_{$songId}", $baseId),
				songId: $songId,
				fileId: $fileId,
				pageNumber: $page,
				layerIndex: $layer,
				type: 'stamp',
				x: $x,
				y: $y,
				width: $normW,
				height: $normH,
				style: [
					'color' => $color,
					'fontSize' => round($size / $sourceW * 1000, 1),
				],
				data: $data,
			);
		}

		// Drawing / Highlight / Shape
		$lineColorInt = isset($draw['LineColor']) ? (int)$draw['LineColor'] : -65536;
		$strokeColor = self::argbToHex($lineColorInt);
		$lineWidth = isset($draw['LineWidth']) ? (float)$draw['LineWidth'] : 2.0;

		$penMode = (int)($draw['PenMode'] ?? 1);
		$smoothMode = (int)($draw['SmoothMode'] ?? 0);
		$drawMode = (int)($draw['DrawMode'] ?? 0);

		$isHairpin = ($type === 'shape' || $rawType === 6) && ($drawMode === 4 || $drawMode === 5) && count($points) >= 2;
		if ($isHairpin) {
			$isDecrescendo = ($drawMode === 5);
			$pathD = SvgCurveUtil::generateHairpinPath($points[0], $points[1], $isDecrescendo, $aperture);
			$bbox = SvgCurveUtil::calculateHairpinBoundingBox($points[0], $points[1], $isDecrescendo, $aperture);
		} else {
			$bbox = !empty($points) ? self::calculateBoundingBox($points) : ['x' => 0.0, 'y' => 0.0, 'width' => 0.0, 'height' => 0.0];
			$pathD = !empty($points) ? self::generateSvgPath($points, smooth: $type !== 'shape' && $drawMode !== 4) : '';
		}

		$lineStyle = match ($penMode) {
			2 => 'dotted',
			3 => 'dashed',
			default => 'solid',
		};
		$strokeDashArray = match ($penMode) {
			2 => '4, 12',
			3 => '36, 20',
			default => null,
		};

		$style = [
			'stroke' => $strokeColor,
			'strokeWidth' => round($lineWidth / ($sourceW > 0 ? $sourceW : 595.0) * 1000, 2),
			'opacity' => $opacity,
			'blendMode' => $type === 'highlight' ? 'multiply' : 'normal',
			'lineStyle' => $lineStyle,
		];
		if ($strokeDashArray !== null) {
			$style['strokeDashArray'] = $strokeDashArray;
		}

		$data = array_merge($baseClientMeta, [
			'path' => $pathD,
			'points' => $points,
			'drawId' => (int)($draw['Id'] ?? $baseId),
			'drawMode' => $drawMode,
			'penMode' => $penMode,
			'smoothMode' => $smoothMode,
			'fillColor' => (int)($draw['FillColor'] ?? 0),
			'lineWidth' => $lineWidth,
			'isHairpin' => $isHairpin,
		]);

		return new InternalSongAnnotation(
			id: null,
			uuid: self::generateDeterministicUuid("mobilesheets_{$songId}", $baseId),
			songId: $songId,
			fileId: $fileId,
			pageNumber: $page,
			layerIndex: $layer,
			type: $type,
			x: $bbox['x'],
			y: $bbox['y'],
			width: $bbox['width'],
			height: $bbox['height'],
			style: $style,
			data: $data,
		);
	}

	/**
	 * Map MobileSheets music font character to standard Unicode glyph for web rendering.
	 */
	public static function mapMobileSheetsSymbol(string $symbol, int $font = 1): string {
		if ($font > 1) {
			return $symbol;
		}
		return match ($symbol) {
			'q' => '♩',
			'e' => '♪',
			'ee' => '♫',
			'h' => '𝅗𝅥',
			'w' => '𝅝',
			's' => '𝅘𝅥𝅯',
			'b' => '♭',
			'bb', 'BB' => '𝄫',
			'#', 'sharp' => '♯',
			'##', 'x', 'X' => '𝄪',
			'n' => '♮',
			'∫' => '𝄋',
			default => $symbol,
		};
	}

	/**
	 * Map standard Unicode music glyphs back to MobileSheets font codes.
	 */
	public static function mapToMobileSheetsSymbol(string $symbol): string {
		return match ($symbol) {
			'♩' => 'q',
			'♪' => 'e',
			'♫' => 'ee',
			'𝅗𝅥' => 'h',
			'𝅝' => 'w',
			'𝅘𝅥𝅯' => 's',
			'♭' => 'b',
			'𝄫', '♭♭' => 'bb',
			'♯' => '#',
			'𝄪' => 'x',
			'♮' => 'n',
			'𝄋' => '∫',
			default => $symbol,
		};
	}

	/**
	 * Export canonical annotations back to a MobileSheets SQLite database.
	 *
	 * @param list<InternalSongAnnotation> $annotations
	 * @param array<int, int> $clientRecordIds Mapping of internal annotation ID => MobileSheets AnnotationsBase.Id
	 * @return array<int, int> Map of internal annotation ID => MobileSheets AnnotationsBase.Id
	 */
	public function exportAnnotations(
		PDO $db,
		int $clientSongId,
		array $annotations,
		float $sourceWidth = 595.0,
		float $sourceHeight = 842.0,
		array $clientRecordIds = [],
	): array {
		if (!MobileSheetsTableHelper::tableExists($db, 'AnnotationsBase')) {
			return [];
		}

		// Clean up existing records for this song to ensure clean export/overwrite
		$existingBaseIds = $db->query('SELECT Id FROM AnnotationsBase WHERE SongId = ' . (int)$clientSongId)->fetchAll(PDO::FETCH_COLUMN);
		if (!empty($existingBaseIds)) {
			$inList = implode(',', array_map('intval', $existingBaseIds));
			$db->exec("DELETE FROM AnnotationsBase WHERE Id IN ($inList)");
			if (MobileSheetsTableHelper::tableExists($db, 'DrawAnnotations')) {
				$db->exec("DELETE FROM DrawAnnotations WHERE BaseId IN ($inList)");
			}
			if (MobileSheetsTableHelper::tableExists($db, 'StampAnnotations')) {
				$db->exec("DELETE FROM StampAnnotations WHERE BaseId IN ($inList)");
			}
			if (MobileSheetsTableHelper::tableExists($db, 'TextboxAnnotations')) {
				$db->exec("DELETE FROM TextboxAnnotations WHERE BaseId IN ($inList)");
			}
			if (MobileSheetsTableHelper::tableExists($db, 'AnnotationPoints')) {
				$db->exec("DELETE FROM AnnotationPoints WHERE AnnotationId IN ($inList)");
			}
		}

		$usedIds = [];
		$allBaseIds = $db->query('SELECT Id FROM AnnotationsBase')->fetchAll(PDO::FETCH_COLUMN);
		foreach ($allBaseIds as $bid) {
			$usedIds[(int)$bid] = true;
		}
		$nextId = !empty($usedIds) ? max(array_keys($usedIds)) + 1 : 1;

		$usedPointsIds = [];
		if (MobileSheetsTableHelper::tableExists($db, 'AnnotationPoints')) {
			$allPtsIds = $db->query('SELECT Id FROM AnnotationPoints')->fetchAll(PDO::FETCH_COLUMN);
			foreach ($allPtsIds as $pid) {
				$usedPointsIds[(int)$pid] = true;
			}
		}

		$usedDrawIds = [];
		if (MobileSheetsTableHelper::tableExists($db, 'DrawAnnotations')) {
			$allDrawIds = $db->query('SELECT Id FROM DrawAnnotations')->fetchAll(PDO::FETCH_COLUMN);
			foreach ($allDrawIds as $did) {
				$usedDrawIds[(int)$did] = true;
			}
		}

		$usedStampIds = [];
		if (MobileSheetsTableHelper::tableExists($db, 'StampAnnotations')) {
			$allStampIds = $db->query('SELECT Id FROM StampAnnotations')->fetchAll(PDO::FETCH_COLUMN);
			foreach ($allStampIds as $sid) {
				$usedStampIds[(int)$sid] = true;
			}
		}

		$usedTextIds = [];
		if (MobileSheetsTableHelper::tableExists($db, 'TextboxAnnotations')) {
			$allTextIds = $db->query('SELECT Id FROM TextboxAnnotations')->fetchAll(PDO::FETCH_COLUMN);
			foreach ($allTextIds as $tid) {
				$usedTextIds[(int)$tid] = true;
			}
		}

		$annInsert = $db->prepare('
			INSERT INTO AnnotationsBase (Id, SongId, Page, Type, Opacity, Zoom, ZoomY, Version, SourcePageWidth, SourcePageHeight, Layer)
			VALUES (:id, :songId, :page, :type, :opacity, :zoom, :zoomY, :version, :sourceW, :sourceH, :layer)
		');

		$drawInsert = MobileSheetsTableHelper::tableExists($db, 'DrawAnnotations') ? $db->prepare('
			INSERT INTO DrawAnnotations (Id, BaseId, LineColor, FillColor, LineWidth, DrawMode, PenMode, SmoothMode)
			VALUES (:id, :baseId, :lineColor, :fillColor, :lineWidth, :drawMode, :penMode, :smoothMode)
		') : null;

		$pointsInsert = MobileSheetsTableHelper::tableExists($db, 'AnnotationPoints') ? $db->prepare('
			INSERT INTO AnnotationPoints (Id, AnnotationId, Points, Count)
			VALUES (:id, :annId, :points, :count)
		') : null;

		$textInsert = MobileSheetsTableHelper::tableExists($db, 'TextboxAnnotations') ? $db->prepare('
			INSERT INTO TextboxAnnotations (Id, BaseId, TextColor, Text, FontFamily, FontSize, FontStyle, FillColor, BorderColor, TextAlign, HasBorder, BorderWidth, AutoSize, LineSpacing)
			VALUES (:id, :baseId, :color, :text, :fontFamily, :size, :fontStyle, :fillColor, :borderColor, :textAlign, :hasBorder, :borderWidth, :autoSize, :lineSpacing)
		') : null;

		$stampInsert = MobileSheetsTableHelper::tableExists($db, 'StampAnnotations') ? $db->prepare('
			INSERT INTO StampAnnotations (Id, BaseId, Type, Size, FilePath, Color, Font, Symbol)
			VALUES (:id, :baseId, :type, :size, :filePath, :color, :font, :symbol)
		') : null;

		$assignedBaseIds = [];
		// Phase 1: Preserve preferred IDs for existing annotations
		foreach ($annotations as $ann) {
			if ($ann->deletedAt !== null) {
				continue;
			}
			$preferredId = null;
			if ($ann->id !== null && isset($clientRecordIds[$ann->id])) {
				$preferredId = $clientRecordIds[$ann->id];
			} elseif (isset($ann->data['clientRecordId']) && is_numeric($ann->data['clientRecordId'])) {
				$preferredId = (int)$ann->data['clientRecordId'];
			}

			if ($preferredId !== null && !isset($usedIds[$preferredId])) {
				$usedIds[$preferredId] = true;
				$assignedBaseIds[spl_object_id($ann)] = $preferredId;
			}
		}

		// Phase 2: Allocate next available IDs for new annotations
		foreach ($annotations as $ann) {
			if ($ann->deletedAt !== null) {
				continue;
			}
			$objId = spl_object_id($ann);
			if (isset($assignedBaseIds[$objId])) {
				continue;
			}
			while (isset($usedIds[$nextId])) {
				$nextId++;
			}
			$usedIds[$nextId] = true;
			$assignedBaseIds[$objId] = $nextId;
			$nextId++;
		}

		$exportedMap = [];

		foreach ($annotations as $ann) {
			if ($ann->deletedAt !== null) {
				continue;
			}

			$baseId = $assignedBaseIds[spl_object_id($ann)];
			if ($ann->id !== null) {
				$exportedMap[$ann->id] = $baseId;
			}

			$opacityInt = (int)round(($ann->style['opacity'] ?? 1.0) * 100);
			$annType = isset($ann->data['mobilesheetsType']) && is_numeric($ann->data['mobilesheetsType'])
				? (int)$ann->data['mobilesheetsType']
				: match ($ann->type) {
					'text' => 0,
					'highlight' => 2,
					'stamp' => 3,
					'shape' => 6,
					default => 1,
				};

			$srcW = (float)($ann->data['sourceWidth'] ?? $sourceWidth);
			$srcH = (float)($ann->data['sourceHeight'] ?? $sourceHeight);
			$zoom = (float)($ann->data['zoom'] ?? 1.0);
			$zoomY = (float)($ann->data['zoomY'] ?? 1.0);
			$version = (int)($ann->data['version'] ?? 3);

			$annInsert->execute([
				':id' => $baseId,
				':songId' => $clientSongId,
				':page' => $ann->pageNumber,
				':type' => $annType,
				':opacity' => $opacityInt,
				':zoom' => $zoom,
				':zoomY' => $zoomY,
				':version' => $version,
				':sourceW' => $srcW,
				':sourceH' => $srcH,
				':layer' => $ann->layerIndex,
			]);

			if ($ann->type === 'text') {
				if ($textInsert !== null) {
					$color = self::hexToArgb((string)($ann->style['color'] ?? '#000000'));
					$text = (string)($ann->data['text'] ?? '');
					$size = (float)($ann->data['fontSize'] ?? 14.0);
					$rawFf = $ann->data['rawFontFamily'] ?? $ann->data['fontFamily'] ?? 0;
					$fontFamily = match ($rawFf) {
						1, '1', 'serif' => 1,
						2, '2', 'monospace' => 2,
						default => 0,
					};
					$fontStyle = (int)($ann->data['fontStyle'] ?? 0);
					$fillColor = (int)($ann->data['fillColor'] ?? 0);
					$borderColor = (int)($ann->data['borderColor'] ?? 0);
					$textAlign = (int)($ann->data['textAlign'] ?? 0);
					$hasBorder = (int)($ann->data['hasBorder'] ?? 0);
					$borderWidth = (float)($ann->data['borderWidth'] ?? 0.0);
					$autoSize = (int)($ann->data['autoSize'] ?? 1);
					$lineSpacing = (float)($ann->data['lineSpacing'] ?? 1.0);
					$textId = isset($ann->data['textboxId']) && is_numeric($ann->data['textboxId']) ? (int)$ann->data['textboxId'] : $baseId;
					while (isset($usedTextIds[$textId])) {
						$textId++;
					}
					$usedTextIds[$textId] = true;

					$textInsert->execute([
						':id' => $textId,
						':baseId' => $baseId,
						':color' => $color,
						':text' => $text,
						':fontFamily' => $fontFamily,
						':size' => $size,
						':fontStyle' => $fontStyle,
						':fillColor' => $fillColor,
						':borderColor' => $borderColor,
						':textAlign' => $textAlign,
						':hasBorder' => $hasBorder,
						':borderWidth' => $borderWidth,
						':autoSize' => $autoSize,
						':lineSpacing' => $lineSpacing,
					]);
				}
				if ($pointsInsert !== null) {
					$pointsId = isset($ann->data['pointsId']) && is_numeric($ann->data['pointsId']) ? (int)$ann->data['pointsId'] : $baseId;
					while (isset($usedPointsIds[$pointsId])) {
						$pointsId++;
					}
					$usedPointsIds[$pointsId] = true;

					if (isset($ann->data['rawPointsBlob']) && is_string($ann->data['rawPointsBlob'])) {
						$blob = base64_decode($ann->data['rawPointsBlob']);
						$count = (int)($ann->data['pointsCount'] ?? intdiv(strlen($blob), 8));
					} else {
						$points = [
							['x' => $ann->x, 'y' => $ann->y],
							['x' => $ann->x + $ann->width, 'y' => $ann->y + $ann->height],
						];
						[$blob, $count] = self::packPointsBlob($points, $srcW, $srcH, 'text');
					}
					$pointsInsert->bindValue(':id', $pointsId, \PDO::PARAM_INT);
					$pointsInsert->bindValue(':annId', $baseId, \PDO::PARAM_INT);
					$pointsInsert->bindValue(':points', $blob, \PDO::PARAM_LOB);
					$pointsInsert->bindValue(':count', $count, \PDO::PARAM_INT);
					$pointsInsert->execute();
				}
			} elseif ($ann->type === 'stamp') {
				if ($stampInsert !== null) {
					$color = self::hexToArgb((string)($ann->style['color'] ?? '#000000'));
					$size = (float)($ann->data['size'] ?? 40.0);
					$font = (int)($ann->data['font'] ?? 1);
					$rawSymbol = (string)($ann->data['symbol'] ?? $ann->data['displaySymbol'] ?? 'q');
					$symbol = self::mapToMobileSheetsSymbol($rawSymbol);
					$stampType = (int)($ann->data['stampType'] ?? 2);
					$filePath = (string)($ann->data['filePath'] ?? '');
					$stampId = isset($ann->data['stampId']) && is_numeric($ann->data['stampId']) ? (int)$ann->data['stampId'] : $baseId;
					while (isset($usedStampIds[$stampId])) {
						$stampId++;
					}
					$usedStampIds[$stampId] = true;

					$stampInsert->execute([
						':id' => $stampId,
						':baseId' => $baseId,
						':type' => $stampType,
						':size' => $size,
						':filePath' => $filePath,
						':color' => $color,
						':font' => $font,
						':symbol' => $symbol,
					]);
				}
				if ($pointsInsert !== null) {
					$pointsId = isset($ann->data['pointsId']) && is_numeric($ann->data['pointsId']) ? (int)$ann->data['pointsId'] : $baseId;
					while (isset($usedPointsIds[$pointsId])) {
						$pointsId++;
					}
					$usedPointsIds[$pointsId] = true;

					if (isset($ann->data['rawPointsBlob']) && is_string($ann->data['rawPointsBlob'])) {
						$blob = base64_decode($ann->data['rawPointsBlob']);
						$count = (int)($ann->data['pointsCount'] ?? intdiv(strlen($blob), 8));
					} else {
						$points = [['x' => $ann->x, 'y' => $ann->y]];
						[$blob, $count] = self::packPointsBlob($points, $srcW, $srcH, 'stamp');
					}
					$pointsInsert->bindValue(':id', $pointsId, \PDO::PARAM_INT);
					$pointsInsert->bindValue(':annId', $baseId, \PDO::PARAM_INT);
					$pointsInsert->bindValue(':points', $blob, \PDO::PARAM_LOB);
					$pointsInsert->bindValue(':count', $count, \PDO::PARAM_INT);
					$pointsInsert->execute();
				}
			} else {
				if ($drawInsert !== null) {
					$lineColor = self::hexToArgb((string)($ann->style['stroke'] ?? '#ff0000'));
					$strokeW = (float)($ann->data['lineWidth'] ?? ($ann->style['strokeWidth'] ?? 2.0));
					$drawMode = isset($ann->data['drawMode']) && is_numeric($ann->data['drawMode'])
						? (int)$ann->data['drawMode']
						: ($ann->type === 'shape' ? 1 : ($ann->type === 'highlight' ? 2 : 0));

					if (isset($ann->data['penMode']) && is_numeric($ann->data['penMode'])) {
						$penMode = (int)$ann->data['penMode'];
					} else {
						$lineStyle = (string)($ann->style['lineStyle'] ?? '');
						$dashArray = (string)($ann->style['strokeDashArray'] ?? '');
						if ($lineStyle === 'dashed' || ($dashArray !== '' && !str_starts_with($dashArray, '4,') && !str_starts_with($dashArray, '1,'))) {
							$penMode = 3;
						} elseif ($lineStyle === 'dotted' || str_starts_with($dashArray, '4,') || str_starts_with($dashArray, '1,')) {
							$penMode = 2;
						} else {
							$penMode = $ann->type === 'shape' ? 0 : 1;
						}
					}

					$smoothMode = (int)($ann->data['smoothMode'] ?? 0);
					$fillColor = (int)($ann->data['fillColor'] ?? 0);
					$drawId = isset($ann->data['drawId']) && is_numeric($ann->data['drawId']) ? (int)$ann->data['drawId'] : $baseId;
					while (isset($usedDrawIds[$drawId])) {
						$drawId++;
					}
					$usedDrawIds[$drawId] = true;

					$drawInsert->execute([
						':id' => $drawId,
						':baseId' => $baseId,
						':lineColor' => $lineColor,
						':fillColor' => $fillColor,
						':lineWidth' => $strokeW,
						':drawMode' => $drawMode,
						':penMode' => $penMode,
						':smoothMode' => $smoothMode,
					]);
				}

				if ($pointsInsert !== null && (isset($ann->data['rawPointsBlob']) || !empty($ann->data['points']))) {
					$pointsId = isset($ann->data['pointsId']) && is_numeric($ann->data['pointsId']) ? (int)$ann->data['pointsId'] : $baseId;
					while (isset($usedPointsIds[$pointsId])) {
						$pointsId++;
					}
					$usedPointsIds[$pointsId] = true;

					if (isset($ann->data['rawPointsBlob']) && is_string($ann->data['rawPointsBlob'])) {
						$blob = base64_decode($ann->data['rawPointsBlob']);
						$count = (int)($ann->data['pointsCount'] ?? intdiv(strlen($blob), 8));
					} else {
						$points = $ann->data['points'] ?? [];
						if (!empty($points) && is_array($points)) {
							$strokeW = (float)($ann->data['lineWidth'] ?? ($ann->style['strokeWidth'] ?? 2.0));
							$aperture = isset($ann->data['aperture']) ? (float)$ann->data['aperture'] : null;
							[$blob, $count] = self::packPointsBlob($points, $srcW, $srcH, $ann->type, $strokeW, $aperture, $drawMode);
						} else {
							$blob = '';
							$count = 0;
						}
					}

					$pointsInsert->bindValue(':id', $pointsId, \PDO::PARAM_INT);
					$pointsInsert->bindValue(':annId', $baseId, \PDO::PARAM_INT);
					$pointsInsert->bindValue(':points', $blob, \PDO::PARAM_LOB);
					$pointsInsert->bindValue(':count', $count, \PDO::PARAM_INT);
					$pointsInsert->execute();
				}
			}
		}

		return $exportedMap;
	}

	/**
	 * Unpack MobileSheets binary Points BLOB of IEEE 754 64-bit doubles into normalized [0..1] coordinates.
	 *
	 * @return list<array{x: float, y: float}>
	 */
	public static function unpackPointsBlob(string $blob, float $sourceW, float $sourceH): array {
		$len = strlen($blob);
		$doublesCount = intdiv($len, 8);
		if ($doublesCount < 2) {
			return [];
		}

		$doubles = array_values(unpack('d*', $blob) ?: []);
		$points = [];

		$w = $sourceW > 0 ? $sourceW : 595.0;
		$h = $sourceH > 0 ? $sourceH : 842.0;

		// Check if this is a segmented stroke (starts with 1.0, 6.0)
		if (count($doubles) >= 7 && $doubles[0] == 1.0 && $doubles[1] == 6.0) {
			$i = 1;
			while ($i < count($doubles)) {
				// Each segment: 6.0, pressure, x1, y1, x2, y2, [FLOAT_MAX, FLOAT_MAX]
				if ($doubles[$i] == 6.0 && $i + 5 < count($doubles)) {
					$x1 = $doubles[$i + 2];
					$y1 = $doubles[$i + 3];
					$x2 = $doubles[$i + 4];
					$y2 = $doubles[$i + 5];

					if (empty($points)) {
						$points[] = ['x' => round($x1 / $w, 5), 'y' => round($y1 / $h, 5)];
					}
					$points[] = ['x' => round($x2 / $w, 5), 'y' => round($y2 / $h, 5)];

					$i += 6;
					while ($i < count($doubles) && abs($doubles[$i] - self::FLOAT_MAX) < 1e30) {
						$i++;
					}
				} else {
					$i++;
				}
			}
			return $points;
		}

		// Check if this is a MobileSheets stroke format with header [0.0, stroke_len, line_width, x0, y0, ...]
		if ($doubles[0] == 0.0 && count($doubles) >= 5) {
			$i = 0;
			while ($i < count($doubles)) {
				if ($doubles[$i] == 0.0 && $i + 2 < count($doubles)) {
					// Header: [0, stroke_count, line_width]
					$i += 3;
				}
				if ($i + 1 < count($doubles)) {
					$x = $doubles[$i];
					$y = $doubles[$i + 1];
					if (abs($x - self::FLOAT_MAX) >= 1e30 && abs($y - self::FLOAT_MAX) >= 1e30) {
						$points[] = [
							'x' => round($x / $w, 5),
							'y' => round($y / $h, 5),
						];
					}
					$i += 2;
				} else {
					$i++;
				}
			}
			return $points;
		}

		// Simple list of coordinate pairs: [x1, y1, x2, y2, ...]
		for ($i = 0; $i + 1 < count($doubles); $i += 2) {
			$x = $doubles[$i];
			$y = $doubles[$i + 1];

			if (abs($x - self::FLOAT_MAX) < 1e30 || abs($y - self::FLOAT_MAX) < 1e30) {
				continue;
			}

			$points[] = [
				'x' => round($x / $w, 5),
				'y' => round($y / $h, 5),
			];
		}

		return $points;
	}

	/**
	 * Pack normalized [0..1] coordinate pairs into MobileSheets binary BLOB of IEEE doubles.
	 *
	 * @param list<array{x: float, y: float}> $points
	 * @return array{string, int} [blob, count]
	 */
	public static function packPointsBlob(
		array $points,
		float $sourceW,
		float $sourceH,
		string $type = 'drawing',
		float $lineWidth = 2.0,
		?float $aperture = null,
		int $drawMode = 0,
	): array {
		if (empty($points)) {
			return ['', 0];
		}

		$doubles = [];
		$w = $sourceW > 0 ? $sourceW : 595.0;
		$h = $sourceH > 0 ? $sourceH : 842.0;

		if ($type === 'drawing' || $type === 'highlight') {
			$coordCount = (float)(2 * count($points));
			$doubles[] = 0.0;
			$doubles[] = $coordCount;
			$doubles[] = $lineWidth;
			foreach ($points as $pt) {
				$doubles[] = (float)$pt['x'] * $w;
				$doubles[] = (float)$pt['y'] * $h;
			}
		} elseif ($type === 'shape' && ($drawMode === 4 || $drawMode === 5 || $aperture !== null) && count($points) === 2) {
			$doubles[] = (float)$points[0]['x'] * $w;
			$doubles[] = (float)$points[0]['y'] * $h;
			$doubles[] = (float)$points[1]['x'] * $w;
			$doubles[] = (float)$points[1]['y'] * $h;
			$doubles[] = $aperture !== null ? (float)$aperture : 4.0;
		} else {
			foreach ($points as $pt) {
				$doubles[] = (float)$pt['x'] * $w;
				$doubles[] = (float)$pt['y'] * $h;
			}
		}

		$blob = '';
		foreach ($doubles as $d) {
			$blob .= pack('d', (float)$d);
		}

		return [$blob, count($doubles)];
	}

	/**
	 * Convert 32-bit signed ARGB integer to Hex string #RRGGBB or #RRGGBBAA.
	 */
	public static function argbToHex(int $argb): string {
		$uint = $argb & 0xFFFFFFFF;
		$a = ($uint >> 24) & 0xFF;
		$r = ($uint >> 16) & 0xFF;
		$g = ($uint >> 8) & 0xFF;
		$b = $uint & 0xFF;

		if ($a === 255) {
			return sprintf('#%02x%02x%02x', $r, $g, $b);
		}
		return sprintf('#%02x%02x%02x%02x', $r, $g, $b, $a);
	}

	/**
	 * Convert Hex string #RRGGBB or #RRGGBBAA to 32-bit signed ARGB integer.
	 */
	public static function hexToArgb(string $hex): int {
		$hex = ltrim($hex, '#');
		$a = 255;
		$r = 0;
		$g = 0;
		$b = 0;

		if (strlen($hex) === 6) {
			$r = hexdec(substr($hex, 0, 2));
			$g = hexdec(substr($hex, 2, 2));
			$b = hexdec(substr($hex, 4, 2));
		} elseif (strlen($hex) === 8) {
			$r = hexdec(substr($hex, 0, 2));
			$g = hexdec(substr($hex, 2, 2));
			$b = hexdec(substr($hex, 4, 2));
			$a = hexdec(substr($hex, 6, 2));
		}

		$uint = (($a & 0xFF) << 24) | (($r & 0xFF) << 16) | (($g & 0xFF) << 8) | ($b & 0xFF);
		if ($uint > 0x7FFFFFFF) {
			$uint -= 0x100000000;
		}
		return $uint;
	}

	/**
	 * Generate SVG path string with 0..1000 coordinate space.
	 * Applies Catmull-Rom cubic Bezier curve smoothing for freehand strokes with 3+ points.
	 *
	 * @param list<array{x: float, y: float}> $points
	 */
	public static function generateSvgPath(array $points, bool $smooth = true): string {
		return SvgCurveUtil::generateSvgPath($points, $smooth);
	}

	/**
	 * @param list<array{x: float, y: float}> $points
	 * @return array{x: float, y: float, width: float, height: float}
	 */
	public static function calculateBoundingBox(array $points): array {
		return SvgCurveUtil::calculateBoundingBox($points);
	}

	public static function generateDeterministicUuid(string $prefix, int $id): string {
		return sprintf('%s-%08x-%04x', substr(md5($prefix . '_' . $id), 0, 18), $id, $id & 0xFFFF);
	}
}
