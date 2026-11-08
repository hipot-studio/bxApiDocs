<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider;

use Bitrix\Rest\Public\Dto\Embedding\EmbeddingOwnerDto;
use Bitrix\Rest\Public\Provider\Dto\Embedding\EmbeddingDto;
use Bitrix\Rest\Public\Provider\Dto\Embedding\EmbeddingOpenDto;

interface EmbeddingOwnerProviderInterface
{
	/**
	 * @param EmbeddingDto[] $embeddings
	 * @return EmbeddingOpenDto[]
	 */
	public function resolveEmbeddings(array $embeddings, int $userId): array;

	/**
	 * @return array<int, mixed>
	 */
	public function getEmbeddingAccessCodes(EmbeddingOwnerDto $owner): array;
}
