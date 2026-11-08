<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Rest;

use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Rest\Public\Services\EmbeddingAccessService;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Embedding\EmbeddingAccessCodes;
use Psr\Log\LoggerInterface;

final class CatalogItemEmbeddingAccessSynchronizer
{
	private const LOGGER_ID = 'vibecodeconnector.embedding.access_synchronizer';

	private ?EmbeddingAccessService $accessService;

	public function __construct(
		private readonly EmbeddingAccessCodes $embeddingAccessCodes = new EmbeddingAccessCodes(),
		private readonly EmbeddingProviderResolver $providerResolver = new EmbeddingProviderResolver(),
		?EmbeddingAccessService $accessService = null,
		private ?LoggerInterface $logger = null,
	)
	{
		$this->accessService = $accessService;
	}

	public function syncForItem(int $catalogItemId): void
	{
		try
		{
			$this->replaceCodes($catalogItemId);
		}
		catch (\Throwable $exception)
		{
			$this->getLogger()->error('Unable to synchronize embedding access codes of a catalog item', [
				'catalogItemId' => $catalogItemId,
				'exception' => $exception,
			]);
		}
	}

	private function replaceCodes(int $catalogItemId): void
	{
		$embeddingProvider = $this->providerResolver->resolve();
		if ($embeddingProvider === null)
		{
			return;
		}

		$embeddings = $embeddingProvider->getByOwner(CatalogItemEmbeddingOwner::forItem($catalogItemId));
		if ($embeddings === [])
		{
			return;
		}

		$accessCodes = $this->embeddingAccessCodes->getForItem($catalogItemId);

		$accessCodesByEmbeddingId = [];
		foreach ($embeddings as $embedding)
		{
			$accessCodesByEmbeddingId[$embedding->id] = $accessCodes;
		}

		$this->getAccessService()->setForEmbeddings(
			CatalogItemEmbeddingOwner::MODULE_ID,
			CatalogItemEmbeddingOwner::ENTITY_TYPE,
			$accessCodesByEmbeddingId,
		);
	}

	private function getAccessService(): EmbeddingAccessService
	{
		return $this->accessService ??= new EmbeddingAccessService();
	}

	private function getLogger(): LoggerInterface
	{
		return $this->logger ??= (new LoggerFactory())->createById(self::LOGGER_ID);
	}
}
