<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Result;

use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\Pager;

class TaskResultParams extends GridParams
{
	public function __construct(
		Pager $pager,
		?TaskResultFilter $filter = null,
		?TaskResultSort $sort = null,
		?TaskResultSelect $select = null,
	)
	{
		parent::__construct(...func_get_args());
	}

	public function isInSelect(string $name): ?bool
	{
		return $this->select?->isInConditions($name);
	}
}
