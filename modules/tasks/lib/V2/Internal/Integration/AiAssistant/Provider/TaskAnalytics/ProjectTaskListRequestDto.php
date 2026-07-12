<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\TaskAnalytics;

use Bitrix\Tasks\V2\Internal\Repository\Pagination;
use Bitrix\Tasks\V2\Internal\Repository\Task\Filter;
use Bitrix\Tasks\V2\Internal\Repository\Task\ListSelect;
use Bitrix\Tasks\V2\Internal\Repository\Task\Order;

class ProjectTaskListRequestDto
{
	public function __construct(
		public readonly Pagination $pagination,
		public readonly ?Filter $filter = null,
		public readonly ?Order $order = null,
		public readonly ?ListSelect $listSelect = null,
	)
	{
	}
}
