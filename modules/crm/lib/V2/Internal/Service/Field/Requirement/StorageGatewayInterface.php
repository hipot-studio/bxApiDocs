<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Field\Requirement;

use Bitrix\Main\Result;

interface StorageGatewayInterface
{
	/** @return list<int> */
	public function selectIds(StorageKey $key): array;

	public function delete(int $id): Result;

	public function add(
		StorageKey $key,
		int $phaseGroupTypeId,
		string $startPhaseId,
		string $finishPhaseId,
	): Result;
}
