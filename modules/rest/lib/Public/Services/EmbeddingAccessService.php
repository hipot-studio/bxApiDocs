<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Services;

use Bitrix\Rest\Internal\Repository\EmbeddingAccessRepository;
use Bitrix\Rest\Internal\Service\Application\AccessCacheInvalidator;
use Bitrix\Rest\Internal\Service\Embedding\EmbeddingAccessCodeNormalizer;
use Bitrix\Rest\Internal\Service\Embedding\EmbeddingOwnerResolver;

class EmbeddingAccessService
{
	public function __construct(
		private readonly EmbeddingAccessRepository $repository = new EmbeddingAccessRepository(),
	)
	{
	}

	public function set(string $moduleId, string $entityType, int $embeddingId, array $accessCodes): void
	{
		$this->setForEmbeddings($moduleId, $entityType, [$embeddingId => $accessCodes]);
	}

	public function setForEmbeddings(
		string $moduleId,
		string $entityType,
		array $accessCodesByEmbeddingId,
	): void
	{
		if (empty($accessCodesByEmbeddingId))
		{
			return;
		}

		try
		{
			$this->repository->replaceForEmbeddings(
				$moduleId,
				$entityType,
				EmbeddingAccessCodeNormalizer::normalizeMap($accessCodesByEmbeddingId),
			);
		}
		finally
		{
			if (!EmbeddingOwnerResolver::isAppOwnerType($moduleId, $entityType))
			{
				AccessCacheInvalidator::clear();
			}
		}
	}
}
