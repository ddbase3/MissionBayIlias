<?php declare(strict_types=1);

namespace MissionBayIlias\Provider;

use Base3\Database\Api\IDatabase;
use MissionBayIlias\Api\IObjectTreeResolver;
use MissionBayIlias\Dto\ContentBatchDto;
use MissionBayIlias\Dto\ContentCursorDto;
use MissionBayIlias\Dto\ContentUnitDto;

final class SahsAssetContentProvider extends AbstractContentProvider {

	private const SOURCE_SYSTEM = 'ilias';
	private const SOURCE_KIND = 'sahs_asset';

	/**
	 * Locator format:
	 *   sahs:<obj_id>:<relpath>
	 *
	 * relpath is relative to SAHS root directory:
	 *   <webspace>/lm_data/lm_<obj_id>/
	 */
	private const LOCATOR_PREFIX = 'sahs';

	/**
	 * Iteration 1:
	 * - Include useful text and document files.
	 * - Exclude noisy assets (js/css/images/media) and schemas.
	 */
	private const EXT_INCLUDE = [
		'html', 'htm', 'xhtml',
		'txt', 'md',
		'xml', 'json',
		'pdf',
		'doc', 'docx',
		'rtf',
		'odt',
		'xls', 'xlsx',
		'ods',
		'ppt', 'pptx',
		'odp',
	];

	private const EXT_EXCLUDE = [
		'zip',
		'js', 'css',
		'xsd', 'svg',

		// images
		'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'ico',

		// audio/video
		'mp3', 'wav', 'ogg', 'flac',
		'mp4', 'm4v', 'mov', 'avi', 'mkv', 'webm',

		// fonts
		'woff', 'woff2', 'ttf', 'otf', 'eot',
	];

	/**
	 * Hard safeguards to avoid embedding garbage / memory issues.
	 * - Text files read into memory should be bounded.
	 * - Binary documents can be passed as file paths to unstructured; still cap extremely large files.
	 */
	private const MAX_TEXT_BYTES = 5_000_000;      // 5 MB
	private const MAX_BINARY_BYTES = 50_000_000;   // 50 MB

	public function __construct(
		IDatabase $db,
		private readonly IObjectTreeResolver $objectTreeResolver
	) {
		parent::__construct($db);
	}

	public static function getName(): string {
		return 'sahsassetcontentprovider';
	}

	public function isActive() {
		return true;
	}

	public function getSourceSystem(): string {
		return self::SOURCE_SYSTEM;
	}

	public function getSourceKind(): string {
		return self::SOURCE_KIND;
	}

	public function fetchChanged(ContentCursorDto $cursor, int $limit): ContentBatchDto {
		$limit = max(1, (int)$limit);

		$sinceTs = trim((string)($cursor->changedAt ?? ''));
		// Variant A: changedId is ignored and stays 0
		$sinceId = 0;

		// Provider must be robust even if cursor is empty/invalid.
		if (!$this->isValidTimestamp($sinceTs)) {
			$sinceTs = '1970-01-01 00:00:00';
		}

		$sinceEpoch = strtotime($sinceTs);
		if ($sinceEpoch === false) {
			$sinceEpoch = 0;
			$sinceTs = '1970-01-01 00:00:00';
		}

		// Discover all SAHS modules (stable parent join pattern).
		$modules = $this->queryAll(
			"SELECT
					o.obj_id,
					o.title
			 FROM object_data o
			 INNER JOIN sahs_lm s ON s.id = o.obj_id
			 WHERE o.type = 'sahs'
			 ORDER BY o.obj_id ASC"
		);

		if (!$modules) {
			return new ContentBatchDto([], new ContentCursorDto($sinceTs, $sinceId));
		}

		// Collect candidates across all modules, then sort deterministically:
		// (mtime asc, relpath asc, obj_id asc)
		$candidates = [];

		foreach ($modules as $m) {
			$objId = (int)($m['obj_id'] ?? 0);
			if ($objId <= 0) {
				continue;
			}

			$rootAbs = $this->getSahsRootAbsPath($objId);
			if ($rootAbs === null || !is_dir($rootAbs) || !is_readable($rootAbs)) {
				continue;
			}

			foreach ($this->listCandidateFiles($rootAbs) as $file) {
				// $file: ['abs' => string, 'rel' => string, 'mtime' => int, 'size' => int, 'ext' => string]
				$mtime = (int)($file['mtime'] ?? 0);
				if ($mtime <= $sinceEpoch) {
					continue; // Variant A: strictly greater than sinceTs
				}

				$rel = (string)($file['rel'] ?? '');
				if ($rel === '') {
					continue;
				}

				$candidates[] = [
					'obj_id' => $objId,
					'rel' => $rel,
					'abs' => (string)($file['abs'] ?? ''),
					'mtime' => $mtime,
					'size' => (int)($file['size'] ?? 0),
					'ext' => (string)($file['ext'] ?? ''),
				];
			}
		}

		if (!$candidates) {
			return new ContentBatchDto([], new ContentCursorDto($sinceTs, $sinceId));
		}

		usort($candidates, static function(array $a, array $b): int {
			$am = (int)($a['mtime'] ?? 0);
			$bm = (int)($b['mtime'] ?? 0);
			if ($am !== $bm) {
				return $am <=> $bm;
			}

			$ar = (string)($a['rel'] ?? '');
			$br = (string)($b['rel'] ?? '');
			$cmp = strcmp($ar, $br);
			if ($cmp !== 0) {
				return $cmp;
			}

			return (int)($a['obj_id'] ?? 0) <=> (int)($b['obj_id'] ?? 0);
		});

		$units = [];
		$maxEpoch = $sinceEpoch;

		foreach ($candidates as $c) {
			if (count($units) >= $limit) {
				break;
			}

			$objId = (int)($c['obj_id'] ?? 0);
			$rel = (string)($c['rel'] ?? '');
			$mtime = (int)($c['mtime'] ?? 0);
			$abs = (string)($c['abs'] ?? '');

			if ($objId <= 0 || $rel === '' || $mtime <= 0 || $abs === '') {
				continue;
			}

			$ts = date('Y-m-d H:i:s', $mtime);

			$locator = self::LOCATOR_PREFIX . ':' . $objId . ':' . $rel;

			// Title contract: stable string. For iteration 1, relpath is good enough.
			$title = basename($rel);
			if ($title === '') {
				$title = $rel;
			}

			// Token for correctness: md5_file() (can be expensive; acceptable for iteration 1)
			$token = null;
			try {
				if (is_file($abs) && is_readable($abs)) {
					$token = @md5_file($abs) ?: null;
				}
			} catch (\Throwable) {
				$token = null;
			}

			$units[] = new ContentUnitDto(
				self::SOURCE_SYSTEM,
				self::SOURCE_KIND,
				$locator,
				$objId,
				null,         // no stable DB int id for file units
				$title,
				$rel,         // description: show relative path
				$ts,
				$token
			);

			if ($mtime > $maxEpoch) {
				$maxEpoch = $mtime;
			}
		}

		$nextTs = $maxEpoch > 0 ? date('Y-m-d H:i:s', $maxEpoch) : $sinceTs;

		return new ContentBatchDto(
			$units,
			new ContentCursorDto($nextTs, 0)
		);
	}

	public function fetchMissingSourceIntIds(int $limit): array {
		// Iteration 1: no deletes for sahs_asset (file units have no stable numeric ID).
		return [];
	}

	public function fetchContent(string $sourceLocator, ?int $containerObjId, ?int $sourceIntId): array {
		$parsed = $this->parseLocator($sourceLocator);

		$objId = $containerObjId !== null && $containerObjId > 0 ? (int)$containerObjId : (int)($parsed['obj_id'] ?? 0);
		$rel = (string)($parsed['rel'] ?? '');

		if ($objId <= 0 || $rel === '') {
			return [];
		}

		$rootAbs = $this->getSahsRootAbsPath($objId);
		if ($rootAbs === null || !is_dir($rootAbs) || !is_readable($rootAbs)) {
			return [];
		}

		// Resolve absolute path safely (prevent traversal)
		$abs = $this->resolveAbsPathSafe($rootAbs, $rel);
		if ($abs === null || !is_file($abs) || !is_readable($abs)) {
			return [];
		}

		$ext = $this->getExtensionLower($abs);
		$size = @filesize($abs);
		$size = is_int($size) && $size >= 0 ? $size : 0;

		$mtime = @filemtime($abs);
		$mtime = is_int($mtime) && $mtime > 0 ? $mtime : 0;
		$mtimeTs = $mtime > 0 ? date('Y-m-d H:i:s', $mtime) : null;

		// Determine whether to inline content or pass as file path.
		// - For binary docs (pdf/office), prefer file path for unstructured.
		// - For text-like (html/xml/json/txt/md), inline content (bounded), and also include file path for debugging/fallback.
		$isBinaryDoc = $this->isBinaryDocumentExt($ext);

		$content = '';

		if ($isBinaryDoc) {
			// Cap extremely large binary files.
			if ($size > self::MAX_BINARY_BYTES) {
				return [];
			}
		} else {
			// Inline text with safeguards.
			if ($size > self::MAX_TEXT_BYTES) {
				// Too large to inline; skip in iteration 1 (can be improved later).
				return [];
			}

			try {
				$raw = @file_get_contents($abs);
				if (is_string($raw)) {
					// Keep raw HTML/XML/etc; parser decides how to interpret.
					$content = $raw;
				}
			} catch (\Throwable) {
				$content = '';
			}

			if ($content === '') {
				// If text file can't be read, skip.
				return [];
			}
		}

		$title = basename($rel);
		if ($title === '') {
			$title = $rel;
		}

		return [
			'type' => self::SOURCE_KIND,
			'obj_id' => $objId,
			'source_locator' => $sourceLocator,
			'title' => $title,
			'description' => $rel,
			'content' => $content,
			'meta' => [
				// required-ish: file location for parsers that support file paths (esp. pdf/office)
				'location' => $abs,
				'file_path' => $abs,

				'relpath' => $rel,
				'ext' => $ext !== '' ? $ext : null,
				'size' => $size > 0 ? $size : null,
				'mtime' => $mtimeTs,

				// Useful for debugging / future improvements
				'sahs_root' => $rootAbs,
			]
		];
	}

	public function fetchReadRoles(string $sourceLocator, ?int $containerObjId, ?int $sourceIntId): array {
		$parsed = $this->parseLocator($sourceLocator);

		$objId = $containerObjId !== null && $containerObjId > 0 ? (int)$containerObjId : (int)($parsed['obj_id'] ?? 0);
		if ($objId <= 0) {
			return [];
		}

		$refIds = \ilObject::_getAllReferences($objId);
		if (!$refIds) {
			return [];
		}

		global $DIC;
		$review = $DIC->rbac()->review();

		$readOpsId = $this->getReadOpsIdForType('sahs');
		if ($readOpsId <= 0) {
			return [];
		}

		$roleIds = [];

		foreach ($refIds as $refId) {
			$refId = (int)$refId;
			if ($refId <= 0) {
				continue;
			}

			foreach ($review->getParentRoleIds($refId) as $r) {
				$rolId = (int)($r['rol_id'] ?? 0);
				if ($rolId <= 0) {
					continue;
				}

				$ops = $review->getActiveOperationsOfRole($refId, $rolId);
				if ($ops && in_array($readOpsId, $ops, true)) {
					$roleIds[$rolId] = true;
				}
			}
		}

		return array_keys($roleIds);
	}

	public function getDirectLink(string $sourceLocator, ?int $containerObjId, ?int $sourceIntId): string {
		$parsed = $this->parseLocator($sourceLocator);

		$objId = $containerObjId !== null && $containerObjId > 0 ? (int)$containerObjId : (int)($parsed['obj_id'] ?? 0);
		if ($objId <= 0) {
			return '';
		}

		$refId = $this->getFirstRefIdByObjId($objId);
		if ($refId <= 0) {
			return '';
		}

		return 'goto.php/sahs/' . $refId;
	}

	/* ---------- Helpers ---------- */

	private function getSahsRootAbsPath(int $objId): ?string {
		if ($objId <= 0) {
			return null;
		}

		// Prefer ILIAS utilities to stay client-safe.
		// ilFileUtils::getWebspaceDir("filesystem") returns a relative path from ILIAS root:
		// "./public/data/<client>" <== wrong!
		// "./data/<client>" <== reality!
		try {
			$webspaceRel = \ilFileUtils::getWebspaceDir('filesystem');
		} catch (\Throwable) {
			$webspaceRel = '';
		}

		$webspaceRel = is_string($webspaceRel) ? trim($webspaceRel) : '';
		if ($webspaceRel === '') {
			return null;
		}

		$rel = rtrim($webspaceRel, '/\\') . '/lm_data/lm_' . $objId;

		// Convert to absolute path.
		// ILIAS_ABSOLUTE_PATH points to the ILIAS installation root.
		$base = defined('ILIAS_ABSOLUTE_PATH') ? (string)ILIAS_ABSOLUTE_PATH : '';
		$base = rtrim($base, '/\\');
		if ($base === '') {
			return null;
		}

		$relTrimmed = ltrim($rel, './\\');
		// $abs = $base . '/' . $relTrimmed; // <== does not work!
		$abs = $base . '/public/' . $relTrimmed; // <== adding public!

		return $abs;
	}

	/**
	 * Recursively list candidate files in a SAHS root directory.
	 *
	 * @return array<int,array{abs:string,rel:string,mtime:int,size:int,ext:string}>
	 */
	private function listCandidateFiles(string $rootAbs): array {
		$out = [];

		$rootAbs = rtrim($rootAbs, '/\\');
		if ($rootAbs === '' || !is_dir($rootAbs) || !is_readable($rootAbs)) {
			return [];
		}

		$it = null;

		try {
			$dirIt = new \RecursiveDirectoryIterator(
				$rootAbs,
				\FilesystemIterator::SKIP_DOTS
				| \FilesystemIterator::CURRENT_AS_FILEINFO
				| \FilesystemIterator::FOLLOW_SYMLINKS
			);

			$it = new \RecursiveIteratorIterator($dirIt);
		} catch (\Throwable) {
			return [];
		}

		foreach ($it as $fi) {
			if (!$fi instanceof \SplFileInfo) {
				continue;
			}

			if (!$fi->isFile()) {
				continue;
			}

			$abs = $fi->getPathname();
			if (!is_string($abs) || $abs === '') {
				continue;
			}

			// Resolve relpath
			$rel = substr($abs, strlen($rootAbs));
			$rel = is_string($rel) ? $rel : '';
			$rel = ltrim(str_replace('\\', '/', $rel), '/');

			if ($rel === '') {
				continue;
			}

			// Always include imsmanifest.xml explicitly.
			if (strcasecmp($rel, 'imsmanifest.xml') !== 0) {
				$ext = $this->getExtensionLower($abs);
				if (!$this->isAllowedExt($ext)) {
					continue;
				}
			}

			$ext = $this->getExtensionLower($abs);

			$mtime = $fi->getMTime();
			$mtime = is_int($mtime) && $mtime > 0 ? $mtime : 0;

			$size = $fi->getSize();
			$size = is_int($size) && $size >= 0 ? $size : 0;

			// Basic size guards
			if ($this->isBinaryDocumentExt($ext)) {
				if ($size > self::MAX_BINARY_BYTES) {
					continue;
				}
			} else {
				if ($size > self::MAX_TEXT_BYTES) {
					continue;
				}
			}

			$out[] = [
				'abs' => $abs,
				'rel' => $rel,
				'mtime' => $mtime,
				'size' => $size,
				'ext' => $ext,
			];
		}

		return $out;
	}

	private function parseLocator(string $locator): array {
		$locator = trim((string)$locator);
		if ($locator === '') {
			return ['obj_id' => 0, 'rel' => ''];
		}

		// Expected: sahs:<obj_id>:<relpath>
		$p = explode(':', $locator, 3);
		if (($p[0] ?? '') !== self::LOCATOR_PREFIX) {
			return ['obj_id' => 0, 'rel' => ''];
		}

		$objId = (int)($p[1] ?? 0);
		$rel = (string)($p[2] ?? '');

		$rel = $this->normalizeRelPath($rel);

		return ['obj_id' => $objId, 'rel' => $rel];
	}

	private function normalizeRelPath(string $rel): string {
		$rel = trim($rel);
		$rel = str_replace('\\', '/', $rel);

		// Remove leading slash
		$rel = ltrim($rel, '/');

		// Basic traversal protection
		if ($rel === '' || str_contains($rel, "\0")) {
			return '';
		}

		// Disallow any ".." path segments
		$parts = explode('/', $rel);
		$clean = [];

		foreach ($parts as $seg) {
			$seg = trim($seg);
			if ($seg === '' || $seg === '.') {
				continue;
			}
			if ($seg === '..') {
				return '';
			}
			$clean[] = $seg;
		}

		return implode('/', $clean);
	}

	private function resolveAbsPathSafe(string $rootAbs, string $rel): ?string {
		$rootAbs = rtrim($rootAbs, '/\\');
		$rel = $this->normalizeRelPath($rel);
		if ($rootAbs === '' || $rel === '') {
			return null;
		}

		$path = $rootAbs . '/' . $rel;

		$realRoot = @realpath($rootAbs);
		$realPath = @realpath($path);

		if (!is_string($realRoot) || $realRoot === '' || !is_string($realPath) || $realPath === '') {
			return null;
		}

		$realRoot = rtrim(str_replace('\\', '/', $realRoot), '/') . '/';
		$realPathNorm = str_replace('\\', '/', $realPath);

		// Ensure resolved path stays under root
		if (!str_starts_with($realPathNorm . (is_dir($realPathNorm) ? '/' : ''), $realRoot) && !str_starts_with($realPathNorm, rtrim($realRoot, '/'))) {
			return null;
		}

		return $realPath;
	}

	private function isAllowedExt(string $ext): bool {
		$ext = strtolower(trim($ext));
		if ($ext === '') {
			return false;
		}
		if (in_array($ext, self::EXT_EXCLUDE, true)) {
			return false;
		}
		return in_array($ext, self::EXT_INCLUDE, true);
	}

	private function isBinaryDocumentExt(string $ext): bool {
		$ext = strtolower(trim($ext));
		return in_array($ext, ['pdf','doc','docx','rtf','odt','xls','xlsx','ods','ppt','pptx','odp'], true);
	}

	private function getExtensionLower(string $path): string {
		$path = (string)$path;
		$ext = pathinfo($path, PATHINFO_EXTENSION);
		$ext = is_string($ext) ? strtolower(trim($ext)) : '';
		return $ext;
	}

	private function isValidTimestamp(string $ts): bool {
		$ts = trim($ts);
		if ($ts === '') {
			return false;
		}
		return (bool)preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $ts);
	}

	private function getReadOpsIdForType(string $type): int {
		static $cache = [];

		if (isset($cache[$type])) {
			return (int)$cache[$type];
		}

		$readOpsId = 0;
		foreach (\ilRbacReview::_getOperationList($type) as $op) {
			if (($op['operation'] ?? '') === 'read') {
				$readOpsId = (int)($op['ops_id'] ?? 0);
				break;
			}
		}

		return $cache[$type] = $readOpsId;
	}

	private function getFirstRefIdByObjId(int $objId): int {
		if ($objId <= 0) {
			return 0;
		}

		// Primary path: object tree resolver
		try {
			$refIds = $this->objectTreeResolver->getRefIdsByObjId($objId);
			$refId = (int)($refIds[0] ?? 0);
			if ($refId > 0) {
				return $refId;
			}
		} catch (\Throwable) {
			// ignore and try fallback
		}

		// Fallback: direct ILIAS API
		try {
			$refIds = \ilObject::_getAllReferences($objId);
			return (int)($refIds[0] ?? 0);
		} catch (\Throwable) {
			return 0;
		}
	}
}
