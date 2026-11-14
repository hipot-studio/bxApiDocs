<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Journal;

use Bitrix\Disk\BaseObject;

interface ObjectChangePublisherInterface
{
	/**
	 * @param string[]|null $changedFields
	 */
	public function publishObjectChange(
		BaseObject $object,
		ChangeType $changeType,
		?int $authorId = null,
		?array $changedFields = null,
		bool $isCascadeRoot = true,
	): bool;
}
