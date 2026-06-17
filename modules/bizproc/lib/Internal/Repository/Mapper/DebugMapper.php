<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\Mapper;

use Bitrix\Bizproc\Internal\Entity\Debugger\DebugSession;

final class DebugMapper
{
	public function convertFromArray(array $data): DebugSession
	{
		return DebugSession::mapFromArray($data);
	}

	public function convertToArray(DebugSession $entity): array
	{
		return $entity->toArray();
	}
}
