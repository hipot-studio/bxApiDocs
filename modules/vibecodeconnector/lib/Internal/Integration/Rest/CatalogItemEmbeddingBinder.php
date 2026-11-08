<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Rest;

use Bitrix\Rest\Public\Command\Embedding\BindEmbeddingCommand;
use Bitrix\Rest\Public\Provider\Dto\Embedding\EmbeddingDto;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Exception\CatalogItemNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Exception\EmbeddingNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Exception\EmbeddingOperationRejectedException;
use Bitrix\Vibecodeconnector\Internal\Exception\NotOwnerException;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp\CatalogItemOpenability;

final class CatalogItemEmbeddingBinder
{
	private const ERROR_ITEM_IS_NOT_OPENABLE = 'The catalog item cannot be opened as an application';
	private const FIELD_ITEM_IS_NOT_OPENABLE = 'catalogItemId';

	public function __construct(
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly CatalogItemOpenability $openability = new CatalogItemOpenability(),
		private readonly EmbeddingProviderResolver $providerResolver = new EmbeddingProviderResolver(),
		private readonly EmbeddingCommandRunner $commandRunner = new EmbeddingCommandRunner(),
		private readonly EmbeddingLanguageFactory $languageFactory = new EmbeddingLanguageFactory(),
	) {}

	public function bind(
		int $catalogItemId,
		int $userId,
		string $placement,
		string $handler,
		array $options = [],
		array $languages = [],
	): EmbeddingDto
	{
		$item = $this->getItem($catalogItemId, $userId);

		$result = $this->commandRunner->run(new BindEmbeddingCommand(
			owner: CatalogItemEmbeddingOwner::forItem((int)$item->getId()),
			placement: $placement,
			handler: $handler,
			options: $options,
			languages: $this->languageFactory->create($languages),
		));

		$embeddingId = (int)($result->getData()['embeddingId'] ?? 0);
		$embedding = $this->providerResolver->resolve()?->getById($embeddingId);
		if ($embedding === null)
		{
			throw new EmbeddingNotFoundException($embeddingId);
		}

		return $embedding;
	}

	private function getItem(int $catalogItemId, int $userId): CatalogItem
	{
		$item = $this->itemRepository->getById($catalogItemId);
		if ($item === null || $item->isDeactivated())
		{
			throw new CatalogItemNotFoundException($catalogItemId);
		}

		if ($item->getOwnerId() !== $userId)
		{
			throw new NotOwnerException();
		}

		if (!$this->openability->isOpenable($item))
		{
			throw new EmbeddingOperationRejectedException(
				self::ERROR_ITEM_IS_NOT_OPENABLE,
				field: self::FIELD_ITEM_IS_NOT_OPENABLE,
			);
		}

		return $item;
	}
}
