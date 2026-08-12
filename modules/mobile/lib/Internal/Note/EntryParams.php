<?php

namespace Bitrix\Mobile\Internal\Note;

/**
 * Resolves the Knowledge Base mobile webview entry state from the raw `entryPath`
 * request value delivered by the in-app-url route and the webview feed rewrite rule.
 */
final class EntryParams
{
	private function __construct(
		public readonly string $spaPath,
		public readonly array $componentParams,
	)
	{
	}

	public static function resolve(mixed $rawEntryPath): self
	{
		// Accept only a string sub-path with a leading "/" and without parent traversal ("..");
		// otherwise fall back to home (the main-menu behavior).
		$entryPath = is_string($rawEntryPath) ? $rawEntryPath : '';
		if ($entryPath === '' || $entryPath[0] !== '/' || str_contains($entryPath, '..'))
		{
			$entryPath = '/';
		}

		// Target SPA path: /note + sub-path. The /note prefix neutralizes protocol-relative links.
		$spaPath = ($entryPath === '/') ? '/note/' : '/note' . $entryPath;

		// Direct document open reuses the server-side direct-open (parity with the web entry).
		$documentId = 0;
		if (preg_match('~^/document/(\d+)(?:[/?#]|$)~', $entryPath, $matches))
		{
			$documentId = (int)$matches[1];
		}

		$componentParams = [];
		if ($documentId > 0)
		{
			$componentParams['DOCUMENT_ID'] = $documentId;
			$componentParams['DIRECT_LINK'] = 'Y';
		}

		return new self($spaPath, $componentParams);
	}

	public function getSpaPathJs(): string
	{
		// JSON_HEX_TAG prevents breaking out of <script> (e.g. </script> in the query);
		// JSON_UNESCAPED_UNICODE keeps non-ASCII readable (search query).
		return json_encode($this->spaPath, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
}
