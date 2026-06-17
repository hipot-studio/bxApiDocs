<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Relation;

use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Main\Provider\Params\SelectInterface;

class RelationTaskParams extends GridParams
{
	public function __construct(
		public int $userId,
		public int $taskId,
		public int $templateId,
		public PagerInterface $pager,
		public ?SelectInterface $select = null,
		public bool $checkRootAccess = true,
		public bool $withCompleted = true,
		public bool $withSubTasks = true,
	)
	{
		parent::__construct(
			pager: $pager,
			select: $select,
		);
	}
}
