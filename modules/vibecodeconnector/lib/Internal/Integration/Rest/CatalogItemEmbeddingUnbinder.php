<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Rest;

use Bitrix\Rest\Public\Command\Embedding\UnbindEmbeddingCommand;
use Bitrix\Rest\Public\Exception\Embedding\EmbeddingNotFoundException as RestEmbeddingNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Exception\EmbeddingNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Exception\EmbeddingOperationRejectedException;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;

final class CatalogItemEmbeddingUnbinder
{
	public function __construct(
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly EmbeddingProviderResolver $providerResolver = new EmbeddingProviderResolver(),
		private readonly EmbeddingCommandRunner $commandRunner = new EmbeddingCommandRunner(),
	) {}

	public function unbindById(int $embeddingId, int $userId): void
	{
		$embeddingProvider = $this->providerResolver->resolve();
		if ($embeddingProvider === null)
		{
			throw new EmbeddingNotFoundException($embeddingId);
		}

		$embedding = $embeddingProvider->getById($embeddingId);
		if (
			$embedding === null
			|| $embedding->moduleId !== CatalogItemEmbeddingOwner::MODULE_ID
			|| $embedding->entityType !== CatalogItemEmbeddingOwner::ENTITY_TYPE
		)
		{
			throw new EmbeddingNotFoundException($embeddingId);
		}

		$item = $this->itemRepository->getById((int)$embedding->entityId);
		if ($item === null || $item->getOwnerId() !== $userId)
		{
			throw new EmbeddingNotFoundException($embeddingId);
		}

		$this->commandRunner->run(new UnbindEmbeddingCommand($embeddingId));
	}

	public function unbindByOwner(int $catalogItemId): void
	{
		$embeddingProvider = $this->providerResolver->resolve();
		if ($embeddingProvider === null)
		{
			return;
		}

		foreach ($embeddingProvider->getByOwner(CatalogItemEmbeddingOwner::forItem($catalogItemId)) as $embedding)
		{
			try
			{
				$this->commandRunner->run(new UnbindEmbeddingCommand($embedding->id));
			}
			catch (EmbeddingOperationRejectedException $exception)
			{
				if ($exception->getErrorCode() !== RestEmbeddingNotFoundException::ERROR_CODE)
				{
					throw $exception;
				}
			}
		}
	}
}
