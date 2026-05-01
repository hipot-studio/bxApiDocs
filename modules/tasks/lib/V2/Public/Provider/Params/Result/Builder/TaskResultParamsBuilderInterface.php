<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Result\Builder;

use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Tasks\V2\Public\Provider\Params\Result\TaskResultFilter;
use Bitrix\Tasks\V2\Public\Provider\Params\Result\TaskResultSelect;
use Bitrix\Tasks\V2\Public\Provider\Params\Result\TaskResultSort;

interface TaskResultParamsBuilderInterface
{
	public function buildPager(): PagerInterface;
	public function buildFilter(): ?TaskResultFilter;
	public function buildSort(): ?TaskResultSort;
	public function buildSelect(): ?TaskResultSelect;
}
