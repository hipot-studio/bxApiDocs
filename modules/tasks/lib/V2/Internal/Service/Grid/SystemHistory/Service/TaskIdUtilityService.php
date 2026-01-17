<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Service;

class TaskIdUtilityService
{
	public function unpackId(?string $systemLogMessage): ?int
	{
		if ($systemLogMessage === null)
		{
			return null;
		}

		$pattern = '/\(#(\d+)\)/';
		preg_match_all($pattern, $systemLogMessage, $matches);

		if (empty($matches[1]))
		{
			return null;
		}

		return (int)end($matches[1]);
	}
}
