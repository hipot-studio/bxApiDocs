<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Service\Catalog\OpenApp;

use Bitrix\Main\Application;
use Bitrix\Main\IO\File;
use Bitrix\Main\UI\Extension;

final class AccessDeniedAssets
{
	public static function load(): ?string
	{
		try
		{
			$config = Extension::getConfig('vibecodeconnector.open-app-access-denied') ?? [];
			$path = $config['css'] ?? null;
			if (is_array($path))
			{
				$path = $path[0] ?? null;
			}
			if (!is_string($path) || $path === '')
			{
				return null;
			}

			$absolutePath = Application::getDocumentRoot() . $path;
			if (!File::isFileExists($absolutePath))
			{
				return null;
			}
			$css = (string)File::getFileContents($absolutePath);
		}
		catch (\Throwable)
		{
			return null;
		}

		$css = trim((string)preg_replace('~^\s*/\*# sourceMappingURL=\S+ \*/\s*$~m', '', $css));

		return $css === '' ? null : $css;
	}
}
