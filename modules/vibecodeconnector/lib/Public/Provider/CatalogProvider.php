<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Provider;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem as InternalCatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemForUser;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogListState;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\IconStorageService;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\UserNameResolver;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemFilter;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemPager;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemSort;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\NewApps\BaselineResolver;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\NewApps\NewAppsCountCache;
use Bitrix\Vibecodeconnector\Public\Dto\CatalogItem;
use Bitrix\Vibecodeconnector\Public\Dto\CatalogItemCollection;

final class CatalogProvider
{
	public function __construct(
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly AccessCodes $accessCodes = new AccessCodes(),
		private readonly IconStorageService $iconStorage = new IconStorageService(),
		private readonly UserNameResolver $userNameResolver = new UserNameResolver(),
		private readonly BaselineResolver $baselineResolver = new BaselineResolver(),
		private readonly NewAppsCountCache $countCache = new NewAppsCountCache(),
	) {}

	public function listAccessibleToUser(
		int $userId,
		?string $query = null,
		int $offset = 0,
		int $limit = 20,
		CatalogListState $state = CatalogListState::All,
	): CatalogItemCollection
	{
		$userCodes = $this->accessCodes->getUserCodes($userId);
		$baseline = $this->baselineResolver->resolveForUser($userId);

		if ($state === CatalogListState::New)
		{
			return $this->listNewApps($userId, $userCodes, $baseline, $query, $offset, $limit);
		}

		$filter = (new CatalogItemFilter())
			->accessibleToUser($userId, $userCodes)
			->query($query)
			->hiddenState($state->hiddenPredicate())
		;

		$views = iterator_to_array($this->itemRepository->getListForUser(
			$userId,
			$filter,
			$this->pinAwareSort(),
			new CatalogItemPager($offset, $limit),
		));

		$pageItemIds = array_map(
			static fn(CatalogItemForUser $view): int => (int)$view->item->getId(),
			$views,
		);
		$newAppItemIds = $this->itemRepository->getNewAppItemIds($userId, $userCodes, $baseline, $pageItemIds);

		return $this->toCollection($views, $newAppItemIds);
	}

	/**
	 * @param string[] $userCodes
	 */
	private function listNewApps(
		int $userId,
		array $userCodes,
		DateTime $baseline,
		?string $query,
		int $offset,
		int $limit,
	): CatalogItemCollection {
		$filter = (new CatalogItemFilter())
			->accessibleToUser($userId, $userCodes)
			->type(CatalogItemType::Application)
			->ownerUserNotId($userId)
			->notOpenedByUser(true)
			->hiddenState(false)
			->query($query)
		;

		$views = iterator_to_array($this->itemRepository->getNewAppListForUser(
			$userId,
			$userCodes,
			$baseline,
			$filter,
			$this->pinAwareSort(),
			new CatalogItemPager($offset, $limit),
		));

		$pageItemIds = array_map(
			static fn(CatalogItemForUser $view): int => (int)$view->item->getId(),
			$views,
		);

		return $this->toCollection($views, $pageItemIds);
	}

	public function markCatalogOpenedForUser(int $userId): void
	{
		$this->baselineResolver->markCatalogOpened($userId);
	}

	public function countNewAppsForUser(int $userId, bool $useCache = true): int
	{
		$compute = fn(): int => $this->computeNewAppsCount($userId);

		return $useCache ? $this->countCache->remember($userId, $compute) : $compute();
	}

	public function forgetNewAppsCount(int $userId): void
	{
		$this->countCache->forget($userId);
	}

	private function computeNewAppsCount(int $userId): int
	{
		$userCodes = $this->accessCodes->getUserCodes($userId);
		$baseline = $this->baselineResolver->resolveForUser($userId);

		return $this->itemRepository->getNewAppCount($userId, $userCodes, $baseline);
	}

	public function listDiscoverableToUser(int $userId, ?string $query = null, int $offset = 0, int $limit = 20): CatalogItemCollection
	{
		$filter = (new CatalogItemFilter())
			->accessType(CatalogItemAccessType::ACL)
			->ownerUserNotId($userId)
			->notGrantedTo($this->accessCodes->getUserCodes($userId))
			->query($query)
		;

		$views = $this->itemRepository->getListForUser(
			$userId,
			$filter,
			(new CatalogItemSort())->byCreatedAt(),
			new CatalogItemPager($offset, $limit),
		);

		return $this->toCollection($views);
	}

	public function listNonPrivate(int $userId, ?string $query = null, int $offset = 0, int $limit = 20): CatalogItemCollection
	{
		$filter = (new CatalogItemFilter())
			->accessTypeNot(CatalogItemAccessType::Private)
			->query($query)
		;

		$views = $this->itemRepository->getListForUser(
			$userId,
			$filter,
			$this->pinAwareSort(),
			new CatalogItemPager($offset, $limit),
		);

		return $this->toCollection($views);
	}

	public function isEmptyForUser(int $userId): bool
	{
		return $this->listAccessibleToUser($userId, null, 0, 1)->isEmpty();
	}

	private function pinAwareSort(): CatalogItemSort
	{
		return (new CatalogItemSort())
			->byPinned()
			->byPinnedAt()
			->byOwnedByUser()
			->byLastOpened()
			->byLastOpenedAt()
			->byCreatedAt()
		;
	}

	/**
	 * @param iterable<CatalogItemForUser> $views
	 * @param int[] $newAppItemIds
	 */
	private function toCollection(iterable $views, array $newAppItemIds = []): CatalogItemCollection
	{
		$views = is_array($views) ? $views : iterator_to_array($views);

		$ownerIds = array_map(
			static fn(CatalogItemForUser $view): int => $view->item->getOwnerId(),
			$views,
		);
		$ownerNames = $this->userNameResolver->resolveMany($ownerIds);

		$iconUrls = $this->resolveIconUrls($views);
		$newAppItemIdSet = array_flip($newAppItemIds);

		$dtos = [];
		foreach ($views as $view)
		{
			$ownerId = $view->item->getOwnerId();
			$iconFileId = $view->item->getIconFileId();
			$iconUrl = $iconFileId !== null ? ($iconUrls[$iconFileId] ?? null) : null;
			$isNew = isset($newAppItemIdSet[$view->item->getId()]);
			$dtos[] = $this->toDto($view, $ownerNames[$ownerId] ?? null, $iconUrl, $isNew);
		}

		return new CatalogItemCollection(...$dtos);
	}

	/**
	 * Batch-resolves icon URLs for a list so serialization does not issue one
	 * b_file query per element (N+1).
	 *
	 * @param array<CatalogItemForUser> $views
	 * @return array<int, string> map of iconFileId => public URL
	 */
	private function resolveIconUrls(array $views): array
	{
		$iconFileIds = [];
		foreach ($views as $view)
		{
			$iconFileId = $view->item->getIconFileId();
			if ($iconFileId !== null)
			{
				$iconFileIds[] = $iconFileId;
			}
		}

		if ($iconFileIds === [])
		{
			return [];
		}

		return $this->iconStorage->getPublicUrls($iconFileIds);
	}

	private function resolveDescription(InternalCatalogItem $item): string
	{
		$description = $item->getDescription();
		if ($description !== null && $description !== '')
		{
			return $description;
		}

		$messageId = match ($item->getType())
		{
			CatalogItemType::Bot => 'VIBECODECONNECTOR_CATALOG_PROVIDER_DESCRIPTION_DEFAULT_BOT',
			CatalogItemType::Application => 'VIBECODECONNECTOR_CATALOG_PROVIDER_DESCRIPTION_DEFAULT_APPLICATION',
		};

		return (string)Loc::getMessage($messageId);
	}

	private function toDto(
		CatalogItemForUser $view,
		?string $ownerName = null,
		?string $iconUrl = null,
		bool $isNew = false,
	): CatalogItem
	{
		$item = $view->item;

		return new CatalogItem(
			id: (int)$item->getId(),
			kind: $item->getType()->value,
			title: $item->getTitle(),
			description: $this->resolveDescription($item),
			iconUrl: $iconUrl,
			editUrl: $item->getEditUrl(),
			viewUrl: $item->getViewUrl(),
			chatId: $item->getChatId(),
			externalId: $item->getExternalId(),
			ownerId: $item->getOwnerId(),
			ownerName: $ownerName,
			color: $item->getColor(),
			isPinned: $view->isPinned,
			isMine: $view->isOwnedByUser,
			createdAt: $item->getCreatedAt()?->getTimestamp(),
			isHidden: $view->isHidden,
			isNew: $isNew,
		);
	}
}
