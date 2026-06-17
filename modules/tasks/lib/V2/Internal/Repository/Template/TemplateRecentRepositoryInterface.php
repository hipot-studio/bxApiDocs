<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template;

use Bitrix\Tasks\V2\Internal\Entity\Template;

interface TemplateRecentRepositoryInterface
{
	public function get(int $userId): array;

	public function save(int $userId, array $recentIds): void;
}
