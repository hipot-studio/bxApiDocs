<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertProgress;
use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertStatus;

interface ConvertProgressRepositoryInterface
{
	public function getByGroupId(int $groupId): ConvertProgress;

	public function getStatusByGroupId(int $groupId): ?ConvertStatus;

	public function save(ConvertProgress $progress): void;
}