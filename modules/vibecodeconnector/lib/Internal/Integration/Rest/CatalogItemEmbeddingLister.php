<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Rest;

use Bitrix\Vibecodeconnector\Internal\Exception\CatalogItemNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Exception\NotOwnerException;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;

final class CatalogItemEmbeddingLister
{
	public function __construct(
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly EmbeddingProviderResolver $providerResolver = new EmbeddingProviderResolver(),
	) {}

	public function list(int $catalogItemId, int $userId): array
	{
		$item = $this->itemRepository->getById($catalogItemId);
		if ($item === null)
		{
			throw new CatalogItemNotFoundException($catalogItemId);
		}

		if ($item->getOwnerId() !== $userId)
		{
			throw new NotOwnerException();
		}

		return $this->providerResolver->resolve()?->getByOwner(CatalogItemEmbeddingOwner::forItem($catalogItemId)) ?? [];
	}
}
