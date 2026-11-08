<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Dto\Catalog;

use Bitrix\Rest\Public\Provider\Dto\Embedding;

final class EmbeddingDtoMapper
{
	public function toDto(Embedding\EmbeddingDto $embedding): EmbeddingDto
	{
		$dto = new EmbeddingDto();
		$dto->id = $embedding->id;
		$dto->catalogItemId = (int)$embedding->entityId;
		$dto->placement = $embedding->placement;
		$dto->handler = $embedding->handler;

		return $dto;
	}
}
