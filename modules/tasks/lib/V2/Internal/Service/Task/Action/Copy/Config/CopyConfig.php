<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Copy\Config;

use Bitrix\Tasks\V2\Internal\Entity\Template\ReplicateParams;

class CopyConfig
{
	public function __construct(
		public readonly int $userId,
		public readonly bool $withSubTasks = false,
		public readonly bool $withCheckLists = false,
		public readonly bool $withAttachments = false,
		public readonly bool $withRelatedTasks = false,
		public readonly bool $withReminders = false,
		public readonly bool $withGanttLinks = false,
		public readonly bool $withReplication = false,
		public readonly ?bool $requestReplicate = null,
		public readonly ?ReplicateParams $requestReplicateParams = null,
		public readonly bool $useConsistency = false,
		public readonly ?int $targetTaskId = null,
	)
	{
	}
}
