<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Trait;

use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Service\FileBbCodeService;

trait DiskAttachmentsTrait
{
	private function stripDetachedInlineFiles(array $fields, array $fullData): array
	{
		$newFileIds = $fields[UserField::TASK_ATTACHMENTS] ?? null;
		if (!is_array($newFileIds) || !Loader::includeModule('disk'))
		{
			return $fields;
		}

		$oldFileIds = (array)($fullData[UserField::TASK_ATTACHMENTS] ?? []);

		$detachedFileIds = array_diff($oldFileIds, $newFileIds);
		if (empty($detachedFileIds))
		{
			return $fields;
		}

		$description = $fields['DESCRIPTION'] ?? $fullData['DESCRIPTION'] ?? '';
		if (!is_string($description) || $description === '')
		{
			return $fields;
		}

		$fileBbCodeService = Container::getInstance()->get(FileBbCodeService::class);

		$updatedDescription = $fileBbCodeService->stripDetachedBbCodes($description, $detachedFileIds);
		if ($updatedDescription !== $description)
		{
			$fields['DESCRIPTION'] = $updatedDescription;
		}

		return $fields;
	}
}
