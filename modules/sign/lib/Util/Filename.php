<?php

namespace Bitrix\Sign\Util;

use Bitrix\Main\IO\Path;

class Filename
{
	public static function compose(string $defaultName, ?string $overrideName): string
	{
		if ($overrideName === null)
		{
			return $defaultName;
		}

		$overrideName = trim($overrideName);
		if ($overrideName === '')
		{
			return $defaultName;
		}

		$extension = Path::getExtension($defaultName);
		if ($extension === '')
		{
			return $overrideName;
		}

		if (strcasecmp(Path::getExtension($overrideName), $extension) === 0)
		{
			return $overrideName;
		}

		return $overrideName . '.' . $extension;
	}
}
