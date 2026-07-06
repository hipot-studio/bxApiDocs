<?php

declare(strict_types=1);

namespace Bitrix\Main\UpdateSystem\Migration\Tools;

class SourceCode
{
	public static function classBelongsToModule(string $className, string $moduleName): ?bool
	{
		$className = ltrim($className, '\\');

		$namespaceParts = explode('\\', $className);
		if (count($namespaceParts) <= 1) // class in root namespace. Result is undefined.
		{
			return null;
		}

		if (
			$namespaceParts[0] !== 'Bitrix'
			|| mb_strtolower($namespaceParts[1] ?? '') !== $moduleName
		)
		{
			return false;
		}

		return true;
	}
}
