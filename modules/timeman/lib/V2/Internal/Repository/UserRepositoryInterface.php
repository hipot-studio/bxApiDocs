<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Repository;

interface UserRepositoryInterface
{
	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getByIds(array $userIds): array;

	public function isExists(int $userId): bool;
}
