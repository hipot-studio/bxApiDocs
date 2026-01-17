<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\History\Service;

use Bitrix\Tasks\Internals\Task\Mark;

class TaskMarkService
{
	public function fillMark(string $mark): ?string
	{
		return Mark::getMessage($mark);
	}
}
