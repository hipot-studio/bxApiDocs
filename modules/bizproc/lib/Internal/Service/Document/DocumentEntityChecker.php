<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Document;

use Bitrix\Main\Loader;

final class DocumentEntityChecker
{
	public static function isValid(string $moduleId, string $entity): bool
	{
		if ($moduleId !== '')
		{
			Loader::includeModule($moduleId);
		}

		return class_exists($entity) && isset(class_implements($entity)[\IBPWorkflowDocument::class]);
	}
}
