<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity\SystemHistoryLogCollection;

interface SystemHistoryRepositoryInterface
{
	public function tail(int $templateId, int $offset = 0, int $limit = 50): SystemHistoryLogCollection;

	public function count(int $templateId): int;
}
