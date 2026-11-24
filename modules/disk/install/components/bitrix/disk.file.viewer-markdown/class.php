<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/**
 * Page of a Markdown file: the shared document header over the document, both drawn by the
 * `disk.viewer.markdown-page` extension. The page carries no document in its markup: the extension asks
 * CONTENT_URL for it and shows it isolated, so the untrusted content never reaches this page's DOM.
 *
 * The params are built by {@see \Bitrix\Disk\Internal\Service\MarkdownViewerPageService}: the header is
 * drawn from HEADER_COMPOSITION alone, DOWNLOAD_URL is the address of the revision the page shows, and
 * DOCUMENT_STATE is what the page knows of the document beyond the header.
 */
class DiskFileViewerMarkdownComponent extends CBitrixComponent
{
	public function executeComponent(): void
	{
		$composition = $this->getRawParam('HEADER_COMPOSITION');
		$documentState = $this->getRawParam('DOCUMENT_STATE');
		$documentState = is_array($documentState) ? $documentState : [];
		$lock = is_array($documentState['lock'] ?? null) ? $documentState['lock'] : [];

		$this->arResult['NAME'] = (string)$this->getRawParam('NAME');
		$this->arResult['ROOT_ID'] = 'disk-md-viewer-page-' . $this->randString();
		$this->arResult['OPTIONS'] = [
			'name' => $this->arResult['NAME'],
			'contentUrl' => (string)$this->getRawParam('CONTENT_URL'),
			'downloadUrl' => (string)$this->getRawParam('DOWNLOAD_URL'),
			'portalUrl' => (string)$this->getRawParam('PORTAL_URL'),
			'composition' => is_array($composition) ? $composition : null,
			'document' => [
				'versionId' => isset($documentState['versionId']) ? (int)$documentState['versionId'] : null,
				'canEdit' => (bool)($documentState['canEdit'] ?? false),
				'lock' => [
					'isLocked' => (bool)($lock['isLocked'] ?? false),
					'lockedBy' => isset($lock['lockedBy']) ? (int)$lock['lockedBy'] : null,
				],
				// The editing of the page (DTO-02), passed on as the page service built it.
				'editRefusal' => is_array($documentState['editRefusal'] ?? null) ? $documentState['editRefusal'] : null,
				'edit' => is_array($documentState['edit'] ?? null) ? $documentState['edit'] : null,
				'pullTag' => is_string($documentState['pullTag'] ?? null) ? $documentState['pullTag'] : null,
			],
			'mode' => 'view',
			'theme' => $this->getPortalTheme(),
		];

		// Wrapper prints <title> via ShowTitle(); feed it the file name instead of a client-side script.
		$GLOBALS['APPLICATION']->SetTitle($this->arResult['NAME']);

		// The wrapper head carries no viewport meta, and a phone without one lays the page out at 980px
		// and scales it down. Asked to stay unique in case a shell brings its own.
		$GLOBALS['APPLICATION']->AddHeadString(
			'<meta name="viewport" content="width=device-width, initial-scale=1">',
			true,
		);

		$this->includeComponentTemplate();
	}

	/**
	 * The base theme of the portal of the current user ('dark' or 'light'): the shell of the page is
	 * always light, while the editor follows the portal (AC-010).
	 */
	private function getPortalTheme(): string
	{
		global $USER;

		$userId = ($USER instanceof \CUser && $USER->isAuthorized()) ? (int)$USER->getId() : 0;
		if (
			$userId <= 0
			|| !\Bitrix\Main\Loader::includeModule('intranet')
			|| !class_exists(\Bitrix\Intranet\Integration\Templates\Bitrix24\ThemePicker::class)
		)
		{
			return 'light';
		}

		try
		{
			$picker = new \Bitrix\Intranet\Integration\Templates\Bitrix24\ThemePicker('bitrix24', SITE_ID, $userId);

			return $picker->getCurrentBaseThemeId() === 'dark' ? 'dark' : 'light';
		}
		catch (\Throwable)
		{
			return 'light';
		}
	}

	/**
	 * The value as the caller passed it: the template escapes every value for the context it prints it in,
	 * so the copy the engine escaped for html would be escaped twice.
	 */
	private function getRawParam(string $key): mixed
	{
		return $this->arParams['~' . $key] ?? $this->arParams[$key] ?? null;
	}
}
