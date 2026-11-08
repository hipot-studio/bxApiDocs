<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * Page of an html file: the shared document header over a sandboxed iframe with the isolated
 * document. The untrusted content reaches the iframe src only, never this page's DOM.
 *
 * The header draws itself from HEADER_COMPOSITION and from nothing else, which the caller
 * ({@see \Bitrix\Disk\Internal\Service\HtmlViewerPageService}) describes for the very opening the
 * page was built for: this page carries no session of a document, so an empty description leaves it
 * with no header at all. The download, the access popup and the copying of the link are elements of
 * that description, and PORTAL_URL is what the logo leads to, empty on the public page of an
 * external link, which has no session to lead to.
 */
class DiskFileViewerHtmlComponent extends CBitrixComponent
{
	public function executeComponent(): void
	{
		$this->arResult['NAME'] = (string)($this->arParams['NAME'] ?? '');
		$this->arResult['CONTENT_URL'] = (string)($this->arParams['CONTENT_URL'] ?? '');
		$this->arResult['PORTAL_URL'] = (string)($this->arParams['PORTAL_URL'] ?? '');

		$composition = $this->arParams['HEADER_COMPOSITION'] ?? null;
		$this->arResult['HEADER_COMPOSITION'] = is_array($composition) ? $composition : null;

		// Wrapper prints <title> via ShowTitle(); feed it the file name instead of a client-side script.
		$GLOBALS['APPLICATION']->SetTitle($this->arResult['NAME']);

		// The wrapper head carries no viewport meta, and a phone without one lays the page out at 980px
		// and scales it down. Added once here for either shell the component is rendered in, and asked
		// to stay unique in case a shell brings its own.
		$GLOBALS['APPLICATION']->AddHeadString(
			'<meta name="viewport" content="width=device-width, initial-scale=1">',
			true,
		);

		$this->includeComponentTemplate();
	}
}
