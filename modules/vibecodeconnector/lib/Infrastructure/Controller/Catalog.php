<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Controller;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\Response\AjaxJson;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Vibecodeconnector\Infrastructure\Dto\CatalogItemDtoMapper;
use Bitrix\Vibecodeconnector\Infrastructure\Integration\Main\CatalogOnboardingSpotlight;
use Bitrix\Vibecodeconnector\Infrastructure\Service\Catalog\OpenApp\OpenAppLayoutService;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogListState;
use Bitrix\Vibecodeconnector\Internal\Exception\CatalogItemNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\AccessRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemFilter;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\LastOpenedRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\PinRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Access\HiddenAccessCleaner;
use Bitrix\Vibecodeconnector\Public\Dto\CatalogItemCollection;
use Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Hide\HideCatalogItemCommand;
use Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Hide\HideCatalogItemCommandHandler;
use Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Hide\UnhideCatalogItemCommand;
use Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Hide\UnhideCatalogItemCommandHandler;
use Bitrix\Vibecodeconnector\Public\Provider\CatalogProvider;

final class Catalog extends Controller
{
	private CatalogProvider $catalog;
	private CatalogItemDtoMapper $mapper;
	private CatalogItemRepository $itemRepository;
	private PinRepository $pinRepository;
	private LastOpenedRepository $lastOpenedRepository;
	private AccessRepository $accessRepository;
	private AccessCodes $accessCodes;
	private HiddenAccessCleaner $hiddenAccessCleaner;
	private OpenAppLayoutService $openAppLayoutService;

	public function init(): void
	{
		parent::init();
		$this->catalog = new CatalogProvider();
		$this->mapper = new CatalogItemDtoMapper();
		$this->itemRepository = new CatalogItemRepository();
		$this->pinRepository = new PinRepository();
		$this->lastOpenedRepository = new LastOpenedRepository();
		$this->accessRepository = new AccessRepository();
		$this->accessCodes = new AccessCodes();
		$this->hiddenAccessCleaner = new HiddenAccessCleaner();
		$this->openAppLayoutService = ServiceLocator::getInstance()->get(OpenAppLayoutService::class);
	}

	public function configureActions(): array
	{
		$configureActions = parent::configureActions();
		$configureActions['recordOpen'] = [
			'-prefilters' => [
				ActionFilter\HttpMethod::class,
			],
			'+prefilters' => [
				new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			],
		];
		$configureActions['markCatalogShown'] = [
			'-prefilters' => [
				ActionFilter\HttpMethod::class,
			],
			'+prefilters' => [
				new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			],
		];
		$configureActions['openAppPage'] = [
			'-prefilters' => [
				ActionFilter\Csrf::class,
				ActionFilter\HttpMethod::class,
			],
			'+prefilters' => [
				new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_GET]),
				new ActionFilter\Csrf(false),
			],
		];

		return $configureActions;
	}

	public function vibe24ListAction(
		CurrentUser $currentUser,
		?string $q = null,
		int $offset = 0,
		int $limit = 20,
		?int $previewUserId = null,
	): array
	{
		$userId = $this->resolveListUserId($currentUser, $previewUserId);

		return ['items' => $this->mapper->toArrayList(
			$this->catalog->listNonPrivate(
				$userId,
				$q,
				$offset,
				$limit,
			),
		)];
	}

	public function isEmptyAction(
		CurrentUser $currentUser,
		?int $previewUserId = null,
	): array
	{
		$userId = $this->resolveListUserId($currentUser, $previewUserId);
		$isEmpty = true;

		if ($userId > 0)
		{
			$isEmpty = $this->catalog->isEmptyForUser($userId);
		}

		return ['isEmpty' => $isEmpty];
	}

	public function getNewAppsCountAction(
		CurrentUser $currentUser,
		?int $previewUserId = null,
	): array
	{
		$userId = $this->resolveListUserId($currentUser, $previewUserId);
		$count = 0;

		if ($userId > 0)
		{
			$count = $this->catalog->countNewAppsForUser($userId, useCache: false);
		}

		return ['count' => $count];
	}

	public function myListAction(
		CurrentUser $currentUser,
		?string $q = null,
		?string $state = null,
		?int $previewUserId = null,
		?PageNavigation $pageNavigation = null,
	): array
	{
		$selfId = (int)$currentUser->getId();
		$userId = $this->resolveListUserId($currentUser, $previewUserId);
		$offset = $this->resolvePaginationOffset($pageNavigation);
		$limit = $this->resolvePaginationLimit($pageNavigation);
		$collection = $this->catalog->listAccessibleToUser(
			$userId,
			$q,
			$offset,
			$limit + 1,
			CatalogListState::fromRequest($state),
		);
		[$collection, $hasNext] = $this->slicePaginationCollection($collection, $limit);

		if ($userId === $selfId && $userId > 0)
		{
			$this->catalog->markCatalogOpenedForUser($userId);
		}

		return [
			'items' => $this->mapper->toArrayList($collection),
			'pagination' => [
				'offset' => $offset,
				'limit' => $limit,
				'hasNext' => $hasNext,
			],
		];
	}

	private function resolvePaginationOffset(?PageNavigation $pageNavigation = null): int
	{
		if ($pageNavigation !== null)
		{
			return max(0, $pageNavigation->getOffset());
		}

		return 0;
	}

	private function resolvePaginationLimit(?PageNavigation $pageNavigation = null): int
	{
		if ($pageNavigation !== null)
		{
			return max(1, $pageNavigation->getLimit());
		}

		return 20;
	}

	/**
	 * @return array{CatalogItemCollection, bool}
	 */
	private function slicePaginationCollection(CatalogItemCollection $collection, int $limit): array
	{
		if (count($collection) <= $limit)
		{
			return [$collection, false];
		}

		return [
			new CatalogItemCollection(...array_slice($collection->toArray(), 0, $limit)),
			true,
		];
	}

	public function companyListAction(
		CurrentUser $currentUser,
		?string $q = null,
		int $offset = 0,
		int $limit = 20,
		?int $previewUserId = null,
	): array
	{
		$userId = $this->resolveListUserId($currentUser, $previewUserId);
		$query = $q !== null && trim($q) !== '' ? trim($q) : null;

		return [
			'items' => $this->mapper->toArrayList(
				$this->catalog->listDiscoverableToUser($userId, $query, $offset, $limit),
			),
		];
	}

	public function openAppLayoutAction(
		CurrentUser $currentUser,
		int $catalogItemId,
	): array
	{
		return [
			'html' => $this->openAppLayoutService->render(
				$catalogItemId,
				(int)$currentUser->getId(),
			),
		];
	}

	public function openAppPageAction(
		CurrentUser $currentUser,
		int $catalogItemId,
	): HttpResponse
	{
		return $this->openAppLayoutService->renderPageResponse(
			$catalogItemId,
			(int)$currentUser->getId(),
		);
	}

	private function resolveListUserId(CurrentUser $currentUser, ?int $previewUserId): int
	{
		$selfId = (int)$currentUser->getId();
		if ($previewUserId === null || $previewUserId <= 0 || $previewUserId === $selfId)
		{
			return $selfId;
		}

		if (!$currentUser->isAdmin())
		{
			return $selfId;
		}

		return $previewUserId;
	}

	public function pinAction(
		CurrentUser $currentUser,
		int $catalogItemId,
		?int $previewUserId = null,
	): array
	{
		$userId = $this->resolveListUserId($currentUser, $previewUserId);
		$this->mustGetItem($catalogItemId);

		if (!$this->itemRepository->isAccessibleToUser(
			$catalogItemId,
			$userId,
			$this->accessCodes->getUserCodes($userId),
		))
		{
			throw new AccessDeniedException('User has no access to this catalog item');
		}

		$this->pinRepository->pin($userId, $catalogItemId);

		return ['ok' => true];
	}

	public function unpinAction(
		CurrentUser $currentUser,
		int $catalogItemId,
		?int $previewUserId = null,
	): array
	{
		$userId = $this->resolveListUserId($currentUser, $previewUserId);
		$this->mustGetItem($catalogItemId);

		if (!$this->itemRepository->isAccessibleToUser(
			$catalogItemId,
			$userId,
			$this->accessCodes->getUserCodes($userId),
		))
		{
			throw new AccessDeniedException('User has no access to this catalog item');
		}

		$this->pinRepository->unpin($userId, $catalogItemId);

		return ['ok' => true];
	}

	public function hideAction(
		CurrentUser $currentUser,
		int $catalogItemId,
		?int $previewUserId = null,
	): array
	{
		$userId = $this->resolveListUserId($currentUser, $previewUserId);

		(new HideCatalogItemCommandHandler())(new HideCatalogItemCommand($userId, $catalogItemId));

		return ['ok' => true];
	}

	public function unhideAction(
		CurrentUser $currentUser,
		int $catalogItemId,
		?int $previewUserId = null,
	): array
	{
		$userId = $this->resolveListUserId($currentUser, $previewUserId);

		(new UnhideCatalogItemCommandHandler())(new UnhideCatalogItemCommand($userId, $catalogItemId));

		return ['ok' => true];
	}

	public function recordOpenAction(
		CurrentUser $currentUser,
		int $catalogItemId,
	): AjaxJson
	{
		$userId = (int)$currentUser->getId();
		$userCodes = $this->accessCodes->getUserCodes($userId);

		if (!$this->itemRepository->isAccessibleToUser(
			$catalogItemId,
			$userId,
			$userCodes,
		))
		{
			if (!$this->itemRepository->exists((new CatalogItemFilter())->id($catalogItemId)))
			{
				throw new CatalogItemNotFoundException($catalogItemId);
			}

			throw new AccessDeniedException('User has no access to this catalog item');
		}

		$this->lastOpenedRepository->recordOpen($userId, $catalogItemId);
		$this->catalog->forgetNewAppsCount($userId);

		return AjaxJson::createSuccess(['ok' => true]);
	}

	public function markCatalogShownAction(CurrentUser $currentUser): AjaxJson
	{
		(new CatalogOnboardingSpotlight())->markShownForUser((int)$currentUser->getId());

		return AjaxJson::createSuccess(['ok' => true]);
	}

	public function grantAccessAction(
		CurrentUser $currentUser,
		int $catalogItemId,
		string $accessCode,
	): array
	{
		if ($accessCode === '')
		{
			throw new ArgumentException('accessCode must not be empty');
		}
		$userId = (int)$currentUser->getId();
		$item = $this->mustGetItem($catalogItemId);
		$this->mustOwnItem($item, $userId);
		$this->accessRepository->grant($catalogItemId, $accessCode);

		return ['ok' => true];
	}

	public function revokeAccessAction(
		CurrentUser $currentUser,
		int $catalogItemId,
		string $accessCode,
	): array
	{
		$userId = (int)$currentUser->getId();
		$item = $this->mustGetItem($catalogItemId);
		$this->mustOwnItem($item, $userId);
		$this->accessRepository->revoke($catalogItemId, $accessCode);

		$this->hiddenAccessCleaner->cleanupLostAccess($catalogItemId);

		return ['ok' => true];
	}

	private function mustGetItem(int $catalogItemId): CatalogItem
	{
		$item = $this->itemRepository->getById($catalogItemId);
		if ($item === null)
		{
			throw new CatalogItemNotFoundException($catalogItemId);
		}

		return $item;
	}

	private function mustOwnItem(CatalogItem $item, int $userId): void
	{
		if ($item->getOwnerId() !== $userId)
		{
			throw new AccessDeniedException('Only the owner can perform this operation');
		}
	}
}
