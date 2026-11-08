<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Embedding;

use Bitrix\Rest\Internal\Entity\Application\Embedding\Embedding;
use Bitrix\Rest\Internal\Service\Embedding\EmbeddingOwnerResolver;
use Bitrix\Rest\Public\Provider\Dto\Embedding\EmbeddingDto;

final class EmbeddingDtoMapper
{
	public function mapFromRow(array $row): ?EmbeddingDto
	{
		$id = (int)($row['ID'] ?? 0);
		$owner = EmbeddingOwnerResolver::normalize($row);
		if ($id <= 0 || $owner === [])
		{
			return null;
		}

		return new EmbeddingDto(
			id: $id,
			moduleId: $owner['MODULE_ID'],
			entityType: $owner['ENTITY_TYPE'],
			entityId: $owner['ENTITY_ID'],
			placement: (string)($row['PLACEMENT'] ?? ''),
			handler: (string)($row['PLACEMENT_HANDLER'] ?? ''),
			userId: (int)($row['USER_ID'] ?? 0),
		);
	}

	public function mapFromEntity(Embedding $embedding): ?EmbeddingDto
	{
		return $this->mapFromRow([
			'ID' => $embedding->getId(),
			'APP_ID' => $embedding->getAppId(),
			'MODULE_ID' => $embedding->getModuleId(),
			'ENTITY_TYPE' => $embedding->getEntityType(),
			'ENTITY_ID' => $embedding->getEntityId(),
			'PLACEMENT' => $embedding->getPlacement(),
			'PLACEMENT_HANDLER' => $embedding->getHandler(),
			'USER_ID' => $embedding->getUserId(),
		]);
	}
}
