<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Dto\Embedding;

use Bitrix\Main\ArgumentException;

final class EmbeddingOwnerDto
{
	public readonly string $moduleId;
	public readonly string $entityType;

	public function __construct(
		string $moduleId,
		string $entityType,
		public readonly string $entityId,
	)
	{
		if ($moduleId === '')
		{
			throw new ArgumentException('Owner module id must not be empty', 'moduleId');
		}

		if ($entityType === '')
		{
			throw new ArgumentException('Owner entity type must not be empty', 'entityType');
		}

		if ($entityId === '')
		{
			throw new ArgumentException('Owner entity id must not be empty', 'entityId');
		}

		$this->moduleId = mb_strtolower($moduleId);
		$this->entityType = mb_strtolower($entityType);
	}
}
