<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Result\Builder;

use Bitrix\Tasks\V2\Public\Provider\Params\Result\TaskResultParams;

class TaskResultParamsDirector
{
	public function produce(TaskResultParamsBuilderInterface $builder): TaskResultParams
	{
		return new TaskResultParams(
			pager: $builder->buildPager(),
			filter: $builder->buildFilter(),
			sort: $builder->buildSort(),
			select: $builder->buildSelect(),
		);
	}
}
