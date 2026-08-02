<?php

declare(strict_types=1);

use Bitrix\Main\UI\Extension;
use Bitrix\Main\Web\Json;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class DiskDeferredDocLoader extends CBitrixComponent
{
	private const EXTENSION = 'disk.deferred-doc-load';

	public function executeComponent(): void
	{
		$this->arResult = [
			'extension' => self::EXTENSION,
			'css' => $this->getCss(),
		];

		$this->includeComponentTemplate();
	}

	private function getCss(): string
	{
		$extensions = [self::EXTENSION, 'ui.design-tokens.air', 'ui.design-tokens'];

		foreach ($extensions as $extension)
		{
			$assets = Extension::getAssets($extension);

			$assetsCss = array_merge($assetsCss ?? [], $assets['css'] ?? []);
		}

		$closure = static fn(string $path) => \CUtil::GetAdditionalFileURL($path, true);

		return Json::encode(array_map($closure, $assetsCss), JSON_UNESCAPED_SLASHES);
	}
}