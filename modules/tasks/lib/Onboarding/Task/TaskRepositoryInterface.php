<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Onboarding\Task;

interface TaskRepositoryInterface
{
	public function getCount(int $userId): int;
}