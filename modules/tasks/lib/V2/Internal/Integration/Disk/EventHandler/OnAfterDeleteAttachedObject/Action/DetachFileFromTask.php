<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Disk\EventHandler\OnAfterDeleteAttachedObject\Action;

use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;

class DetachFileFromTask
{
	use DetachFileFromUserFieldTrait;

	public function __invoke(int $taskId, int $attachId): void
	{
		$this->detachFileFromUserField(UserField::TASK, $taskId, $attachId);
	}
}
