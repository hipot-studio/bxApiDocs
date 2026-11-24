<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class DiskFileEditorHtmlComponent extends CBitrixComponent
{
	public function executeComponent(): void
	{
		$this->arResult['NAME'] = (string)($this->arParams['NAME'] ?? '');
		$this->arResult['SOURCE'] = is_array($this->arParams['SOURCE'] ?? null)
			? $this->arParams['SOURCE']
			: []
		;
		$this->arResult['HEADER_COMPOSITION'] = is_array($this->arParams['HEADER_COMPOSITION'] ?? null)
			? $this->arParams['HEADER_COMPOSITION']
			: []
		;
		$this->arResult['PORTAL_URL'] = (string)($this->arParams['PORTAL_URL'] ?? '');
		$this->arResult['BUSY_URL'] = (string)($this->arParams['BUSY_URL'] ?? '');
		$this->arResult['FILE_CHAT_ID'] = (int)($this->arParams['FILE_CHAT_ID'] ?? 0);

		$GLOBALS['APPLICATION']->SetTitle($this->arResult['NAME']);
		$GLOBALS['APPLICATION']->AddHeadString(
			'<meta name="viewport" content="width=device-width, initial-scale=1">',
			true,
		);

		$this->includeComponentTemplate();
	}
}
