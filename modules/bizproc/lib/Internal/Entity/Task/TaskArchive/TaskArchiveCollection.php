<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Task\TaskArchive;

use Bitrix\Bizproc\Internal\Entity\BaseEntityCollection;

class TaskArchiveCollection extends BaseEntityCollection
{
	public function __construct(TaskArchive ...$taskArchives)
	{
		foreach ($taskArchives as $taskArchive)
		{
			$this->collectionItems[] = $taskArchive;
		}
	}
}
