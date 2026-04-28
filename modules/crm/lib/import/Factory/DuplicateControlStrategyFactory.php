<?php

namespace Bitrix\Crm\Import\Factory;

use Bitrix\Crm\Import\Contract\Strategy\DuplicateControlStrategyInterface;
use Bitrix\Crm\Import\Enum\DuplicateControl\DuplicateControlBehavior;
use Bitrix\Crm\Import\Strategy\DuplicateControl\DuplicateControlMergeStrategy;
use Bitrix\Crm\Import\Strategy\DuplicateControl\DuplicateControlNoControlStrategy;
use Bitrix\Crm\Import\Strategy\DuplicateControl\DuplicateControlReplaceStrategy;
use Bitrix\Crm\Import\Strategy\DuplicateControl\DuplicateControlSkipStrategy;

final class DuplicateControlStrategyFactory
{
	public function create(DuplicateControlBehavior $behavior): DuplicateControlStrategyInterface
	{
		return match ($behavior) {
			DuplicateControlBehavior::NoControl => new DuplicateControlNoControlStrategy(),
			DuplicateControlBehavior::Replace => new DuplicateControlReplaceStrategy(),
			DuplicateControlBehavior::Merge => new DuplicateControlMergeStrategy(),
			DuplicateControlBehavior::Skip => new DuplicateControlSkipStrategy(),
		};
	}
}
