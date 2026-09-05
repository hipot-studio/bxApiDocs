<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\File;
use Bitrix\Disk\Version;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Parsedown;

/**
 * Renders a markdown file into safe HTML for the viewer.
 *
 * Pipeline: Parsedown (bundled with the ui module) -> mandatory sanitization via
 * CBXSanitizer, which is the single point of sanitization.
 *
 * Returns a Result: on success data['html'] holds the rendered document; on failure
 * the error carries a stable machine code (see ERROR_* below) and a developer-facing
 * message. Controllers forward these into the ajax envelope, so the real cause is
 * visible in devtools while the viewer still shows its standard error block.
 */
class MarkdownRenderService
{
	public const ERROR_VIEWER_DISABLED = 'DISK_MARKDOWN_VIEWER_DISABLED';
	public const ERROR_SIZE_LIMIT = 'DISK_MARKDOWN_RENDER_SIZE_LIMIT';
	public const ERROR_RENDERER_UNAVAILABLE = 'DISK_MARKDOWN_RENDER_UNAVAILABLE';
	public const ERROR_CONTENT_UNREADABLE = 'DISK_MARKDOWN_RENDER_CONTENT_UNREADABLE';
	public const ERROR_RENDER_FAILED = 'DISK_MARKDOWN_RENDER_FAILED';

	private const CACHE_DIR = '/disk/markdown';
	private const CACHE_TTL = 86400;

	// Bump on any change to the cached payload shape (old caches stored a bare html string,
	// not the html+diagrams array) so stale entries are dropped instead of misread.
	// v3: the em tag is now allowed, so italic-less renders cached under v2 must be dropped.
	private const RENDER_FORMAT_VERSION = 3;

	private const MERMAID_PLACEHOLDER_PREFIX = '[[DISK_MERMAID_PLACEHOLDER::';
	private const MERMAID_PLACEHOLDER_SUFFIX = ']]';

	private const DOCUMENT_STYLE = <<<CSS
		body {
			margin: 0;
			padding: 18px 24px;
			font: 14px/1.6 -apple-system, BlinkMacSystemFont, "Helvetica Neue", Arial, sans-serif;
			color: #333;
			word-wrap: break-word;
		}
		img { max-width: 100%; height: auto; }
		pre { overflow: auto; padding: 12px; background: #f5f7f8; border-radius: 4px; }
		code { font-family: Menlo, Consolas, monospace; font-size: .9em; color: #355068; background: rgba(27, 31, 35, .06); padding: .15em .4em; border-radius: 4px; }
		pre code { color: inherit; background: none; padding: 0; font-size: inherit; }
		table { border-collapse: collapse; }
		th, td { border: 1px solid #dfe2e5; padding: 6px 12px; }
		blockquote { margin: 0; padding-left: 16px; border-left: 3px solid #dfe2e5; color: #4d555e; }
		.disk-markdown-diagram { margin: 16px 0; text-align: center; overflow-x: auto; }
		.disk-markdown-diagram svg { max-width: 100%; height: auto; }
		.disk-markdown-diagram--error { margin: 16px 0; padding: 12px; border: 1px solid #e6b8ba; border-radius: 4px; background: #fdf0f0; color: #a3494c; text-align: left; }
		CSS;

	public static function isAvailable(): bool
	{
		return Loader::includeModule('ui') && class_exists(Parsedown::class);
	}

	public function renderByFile(File $file): Result
	{
		return $this->renderByBlob((int)$file->getId(), (int)$file->getFileId(), (int)$file->getSize());
	}

	public function renderByVersion(Version $version): Result
	{
		return $this->renderByBlob((int)$version->getObjectId(), (int)$version->getFileId(), (int)$version->getSize());
	}

	/**
	 * @param int $objectId Disk object id (for cache scoping).
	 * @param int $fileId    b_file id of the concrete revision to render.
	 * @param int $size      Size of that revision (for the size limit).
	 * @return Result data['html'] on success; an error with an ERROR_* code on failure.
	 */
	private function renderByBlob(int $objectId, int $fileId, int $size): Result
	{
		$result = new Result();

		$sizeLimit = Configuration::getMaxSizeForMarkdownRender();
		if ($size > $sizeLimit)
		{
			return $result->addError(new Error(
				"Markdown source is too large to render: {$size} bytes (limit {$sizeLimit}).",
				self::ERROR_SIZE_LIMIT
			));
		}

		$cache = Application::getInstance()->getCache();
		// Format version in the key: editing styles/wrapper auto-invalidates the cache.
		// LANGUAGE_ID is part of the key too: the document's lang attribute depends on it,
		// so portals with mixed interface languages must cache each variant separately.
		// Keyed by the revision blob, so each version caches separately.
		$formatVersion = self::RENDER_FORMAT_VERSION . '_' . crc32(self::DOCUMENT_STYLE);
		$cacheId = 'disk_markdown_' . $formatVersion . '_' . LANGUAGE_ID . '_' . $objectId . '_' . $fileId;
		// Shard into 256 hash buckets so a busy portal never piles hundreds of thousands of
		// cache files into a single directory (which slows the filesystem to a crawl).
		// Offset 2 (not 0): initCache()/Cache::getPath() already buckets by the first two md5
		// chars, so the next two give an independent second level of distribution.
		$cacheDir = self::CACHE_DIR . '/' . substr(md5($cacheId), 2, 2);
		if ($cache->initCache(self::CACHE_TTL, $cacheId, $cacheDir))
		{
			$cached = $cache->getVars();

			return $result->setData([
				'html' => $cached['html'] ?? '',
				'diagrams' => $cached['diagrams'] ?? [],
			]);
		}

		if (!self::isAvailable())
		{
			return $result->addError(new Error(
				'Markdown renderer is unavailable.',
				self::ERROR_RENDERER_UNAVAILABLE
			));
		}

		$content = $this->readContent($fileId);
		if ($content === null)
		{
			return $result->addError(new Error(
				"Markdown source blob #{$fileId} could not be read from storage.",
				self::ERROR_CONTENT_UNREADABLE
			));
		}

		$rendered = $this->render($content);
		if ($rendered === null)
		{
			// The renderer availability is ensured above, so reaching here is unexpected.
			return $result->addError(new Error(
				'Markdown rendering produced no output.',
				self::ERROR_RENDER_FAILED
			));
		}

		// Only write when caching is actually allowed: startDataCache() returns false when the
		// cache is disabled or another process already holds the lock for this key — in that
		// case endDataCache() must not be called. The html is returned regardless.
		if ($cache->startDataCache())
		{
			$cache->endDataCache($rendered);
		}

		return $result->setData($rendered);
	}

	/**
	 * @return array{html: string, diagrams: list<string>}|null Diagram sources are returned apart
	 *         from the html because mermaid can only render (to SVG) in the browser. Null when the renderer is unavailable.
	 */
	public function render(string $text): ?array
	{
		if (!self::isAvailable())
		{
			return null;
		}

		$rawHtml = (string)(new Parsedown())->text($text);

		// Before sanitization: CBXSanitizer would drop the language class and escape the diagram syntax.
		[$rawHtml, $diagrams] = $this->extractMermaidDiagrams($rawHtml);

		$sanitizer = new \CBXSanitizer();
		$sanitizer->SetLevel(\CBXSanitizer::SECURE_LEVEL_MIDDLE);
		$sanitizer->AddTags(['em' => []]);
		$safeHtml = $sanitizer->SanitizeHtml($rawHtml);

		$safeHtml = $this->rewriteLinks($safeHtml);

		return [
			'html' => $this->buildDocument($safeHtml),
			'diagrams' => $diagrams,
		];
	}

	/**
	 * Swaps every ```mermaid block (emitted by Parsedown as
	 * <pre><code class="language-mermaid">...</code></pre>) for a placeholder, returning
	 * [html with placeholders, ordered diagram sources].
	 */
	private function extractMermaidDiagrams(string $html): array
	{
		$diagrams = [];

		$html = (string)preg_replace_callback(
			'#<pre><code class="language-mermaid">(.*?)</code></pre>#s',
			function (array $match) use (&$diagrams): string {
				$index = count($diagrams);
				// Undo Parsedown's htmlspecialchars() to recover the exact source mermaid expects.
				$diagrams[] = htmlspecialchars_decode($match[1], ENT_QUOTES);

				return self::MERMAID_PLACEHOLDER_PREFIX . $index . self::MERMAID_PLACEHOLDER_SUFFIX;
			},
			$html
		);

		return [$html, $diagrams];
	}

	/**
	 * Makes external links open in a new tab (hardened against tab-nabbing).
	 */
	private function rewriteLinks(string $html): string
	{
		return (string)preg_replace_callback(
			'/<a\s+([^>]*?)>/is',
			static function (array $match): string {
				$attrs = $match[1];
				if (preg_match('/\bhref\s*=\s*([\'"])\s*#/i', $attrs) === 1)
				{
					return $match[0];
				}

				return '<a target="_blank" rel="noopener noreferrer nofollow" ' . $attrs . '>';
			},
			$html
		);
	}

	private function buildDocument(string $bodyHtml): string
	{
		$style = self::DOCUMENT_STYLE;
		// Declare the portal interface language, not a hardcoded one: a wrong lang misleads
		// screen readers, browser auto-translation and language heuristics.
		$lang = htmlspecialcharsbx(LANGUAGE_ID);

		return '<!DOCTYPE html>'
			. '<html lang="' . $lang . '"><head>'
			. '<meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<style>' . $style . '</style>'
			. '</head><body>' . $bodyHtml . '</body></html>'
		;
	}

	private function readContent(int $fileId): ?string
	{
		$fileArray = \CFile::makeFileArray($fileId);
		if (empty($fileArray['tmp_name']) || !is_file($fileArray['tmp_name']))
		{
			return null;
		}

		$content = file_get_contents($fileArray['tmp_name']);

		return $content === false ? null : $content;
	}
}
