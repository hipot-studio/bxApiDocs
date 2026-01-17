<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Mail\Repository;

use Bitrix\Tasks\V2\Internal\Integration\Mail\Entity\Email;

interface EmailRepositoryInterface
{
	public function getByTaskId(int $taskId): ?Email;
}
