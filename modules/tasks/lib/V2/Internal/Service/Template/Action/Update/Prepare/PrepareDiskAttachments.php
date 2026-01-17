<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Prepare;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Repository\DiskFileRepositoryInterface;

class PrepareDiskAttachments implements PrepareFieldInterface
{
	public function __invoke(array $fields, array $fullTemplateData): array
	{
		$attachedObjectIds = $fullTemplateData[UserField::TASK_ATTACHMENTS] ?? null;
		if (empty($attachedObjectIds))
		{
			return $fields;
		}

		$newObjectIds = $fields[UserField::TASK_ATTACHMENTS] ?? null;
		if (empty($newObjectIds))
		{
			return $fields;
		}

		$repository = Container::getInstance()->get(DiskFileRepositoryInterface::class);
		$attachmentMap = $repository->getObjectIdsByAttachmentIds($attachedObjectIds);

		$newAttachments = [];
		foreach ($newObjectIds as $newObjectId)
		{
			if (is_string($newObjectId) && str_starts_with($newObjectId, 'n'))
			{
				$realId = substr($newObjectId, 1);
				$newAttachments[] = $attachmentMap[$realId] ?? $newObjectId;
			}
			else
			{
				$newAttachments[] = $newObjectId;
			}
		}

		$fields[UserField::TASK_ATTACHMENTS] = $newAttachments;

		return $fields;
	}
}
