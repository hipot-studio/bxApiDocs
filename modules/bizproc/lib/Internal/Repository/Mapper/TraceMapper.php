<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Mapper;

use Bitrix\Bizproc\Internal\Entity\Debugger\DebugTrace;

final class TraceMapper
{
	public function convertFromArray(array $data): DebugTrace
	{
		return DebugTrace::mapFromArray($data);
	}

	public function convertToArray(DebugTrace $entity): array
	{
		return $entity->toArray();
	}
}
