<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Provider;

use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Web\Uri;
use Bitrix\Rest\Public\Dto\Embedding\EmbeddingOwnerDto;
use Bitrix\Rest\Public\Provider\Dto\Embedding\EmbeddingDto;
use Bitrix\Rest\Public\Provider\Dto\Embedding\EmbeddingOpenDto;
use Bitrix\Rest\Public\Provider\EmbeddingOwnerProviderInterface;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Exception\CatalogItemNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibePlusPolicy;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemFilter;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemPager;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Embedding\EmbeddingAccessCodes;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp\CatalogItemOpenability;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp\OpenAppSettings;
use Psr\Log\LoggerInterface;

final class CatalogItemEmbeddingOwnerProvider implements EmbeddingOwnerProviderInterface
{
	private const OPEN_APP_PAGE_PATH = '/bitrix/services/main/ajax.php';
	private const OPEN_APP_PAGE_ACTION = 'vibecodeconnector.Catalog.openAppPage';
	private const LOGGER_ID = 'vibecodeconnector.embedding.owner_provider';

	public function __construct(
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly AccessCodes $accessCodes = new AccessCodes(),
		private readonly OpenAppSettings $openAppSettings = new OpenAppSettings(),
		private readonly VibePlusPolicy $vibePlusPolicy = new VibePlusPolicy(),
		private readonly EmbeddingAccessCodes $embeddingAccessCodes = new EmbeddingAccessCodes(),
		private readonly CatalogItemOpenability $openability = new CatalogItemOpenability(),
		private ?LoggerInterface $logger = null,
	) {}

	/**
	 * @param EmbeddingDto[] $embeddings
	 * @return EmbeddingOpenDto[]
	 */
	public function resolveEmbeddings(array $embeddings, int $userId): array
	{
		if ($userId <= 0 || !$this->isOpenAvailable())
		{
			return [];
		}

		$itemIds = $this->collectItemIds($embeddings);
		if ($itemIds === [])
		{
			return [];
		}

		$accessibleItems = $this->loadAccessibleItems($itemIds, $userId);

		$result = [];
		foreach ($embeddings as $embedding)
		{
			$itemId = $this->parseItemId($embedding->entityId);
			$item = $itemId === null ? null : ($accessibleItems[$itemId] ?? null);
			if ($item === null)
			{
				continue;
			}

			$result[] = $this->makeOpenDto($embedding, $item);
		}

		return $result;
	}

	/**
	 * @return string[]
	 */
	public function getEmbeddingAccessCodes(EmbeddingOwnerDto $owner): array
	{
		$itemId = $this->parseItemId($owner->entityId);
		if ($itemId === null)
		{
			throw new CatalogItemNotFoundException(0);
		}

		return $this->embeddingAccessCodes->getForItem($itemId);
	}

	private function isOpenAvailable(): bool
	{
		return $this->openAppSettings->isOpenInIframeEnabled()
			&& $this->vibePlusPolicy->getAvailability() !== false;
	}

	/**
	 * @param EmbeddingDto[] $embeddings
	 * @return int[]
	 */
	private function collectItemIds(array $embeddings): array
	{
		$itemIds = [];
		foreach ($embeddings as $embedding)
		{
			$itemId = $this->parseItemId($embedding->entityId);
			if ($itemId !== null)
			{
				$itemIds[$itemId] = $itemId;
			}
		}

		return array_values($itemIds);
	}

	/**
	 * @param int[] $itemIds
	 * @return array<int, CatalogItem>
	 */
	private function loadAccessibleItems(array $itemIds, int $userId): array
	{
		$userCodes = $this->accessCodes->getUserCodesForItems($userId, $itemIds);

		$requestedCount = count($itemIds);
		if ($requestedCount > CatalogItemPager::MAX_LIMIT)
		{
			$this->getLogger()->warning('Embedding owner group exceeds the catalog read limit', [
				'requestedCount' => $requestedCount,
				'limit' => CatalogItemPager::MAX_LIMIT,
			]);
		}

		$filter = (new CatalogItemFilter())
			->ids($itemIds)
			->accessibleToUser($userId, $userCodes)
		;
		$pager = new CatalogItemPager(0, min($requestedCount, CatalogItemPager::MAX_LIMIT));

		$items = [];
		foreach ($this->itemRepository->getList($filter, null, $pager) as $item)
		{
			if (!$this->openability->isOpenable($item))
			{
				continue;
			}

			$items[(int)$item->getId()] = $item;
		}

		return $items;
	}

	private function makeOpenDto(EmbeddingDto $embedding, CatalogItem $item): EmbeddingOpenDto
	{
		return new EmbeddingOpenDto(
			embeddingId: $embedding->id,
			url: $this->makeOpenUrl((int)$item->getId()),
			payload: [
				'PLACEMENT' => $embedding->placement,
				'PLACEMENT_ID' => $embedding->id,
				'PLACEMENT_OPTIONS' => '{}',
			],
		);
	}

	private function makeOpenUrl(int $catalogItemId): string
	{
		$uri = new Uri(self::OPEN_APP_PAGE_PATH);
		$uri->addParams([
			'action' => self::OPEN_APP_PAGE_ACTION,
			'catalogItemId' => $catalogItemId,
		]);

		return $uri->getUri();
	}

	private function parseItemId(string $entityId): ?int
	{
		$itemId = (int)$entityId;

		return $itemId > 0 && (string)$itemId === $entityId ? $itemId : null;
	}

	private function getLogger(): LoggerInterface
	{
		return $this->logger ??= (new LoggerFactory())->createById(self::LOGGER_ID);
	}
}
