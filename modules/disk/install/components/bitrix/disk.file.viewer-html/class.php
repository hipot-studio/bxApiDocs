<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * Page of an html file: a portal header (logo, file name, sharing and download buttons) over a
 * sandboxed iframe with the isolated document. The untrusted content reaches the iframe src only,
 * never this page's DOM.
 *
 * The caller ({@see \Bitrix\Disk\Internal\Service\HtmlViewerPageService}) decides what the header
 * offers: an empty SHARING_OBJECT_ID hides the access popup, an empty COPY_LINK_URL the copy button
 * and an empty PORTAL_URL leaves the logo unlinked, as the public page of an external link needs.
 */
class DiskFileViewerHtmlComponent extends CBitrixComponent
{
	public function executeComponent(): void
	{
		$this->arResult['NAME'] = (string)($this->arParams['NAME'] ?? '');
		$this->arResult['CONTENT_URL'] = (string)($this->arParams['CONTENT_URL'] ?? '');
		$this->arResult['DOWNLOAD_URL'] = (string)($this->arParams['DOWNLOAD_URL'] ?? '');
		$this->arResult['COPY_LINK_URL'] = (string)($this->arParams['COPY_LINK_URL'] ?? '');
		$this->arResult['PORTAL_URL'] = (string)($this->arParams['PORTAL_URL'] ?? '');
		$this->arResult['SHARING_OBJECT_ID'] = (int)($this->arParams['SHARING_OBJECT_ID'] ?? 0);
		$this->arResult['SHARING_UNIQUE_CODE'] = (string)($this->arParams['SHARING_UNIQUE_CODE'] ?? '');

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
