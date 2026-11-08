<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Controller;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Context;
use Bitrix\Main\Config\Ini;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\Response\AjaxJson;
use Bitrix\Main\Error;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Vibecodeconnector\Infrastructure\Controller\ActionFilter\CheckCatalogAvailability;
use Bitrix\Vibecodeconnector\Infrastructure\Dto\CatalogItemDtoMapper;
use Bitrix\Vibecodeconnector\Infrastructure\Dto\LaunchAppTargetDto;
use Bitrix\Vibecodeconnector\Infrastructure\Integration\Main\CatalogOnboardingSpotlight;
use Bitrix\Vibecodeconnector\Infrastructure\Service\Catalog\OpenApp\OpenAppLayoutService;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogListState;
use Bitrix\Vibecodeconnector\Internal\Exception\CatalogItemNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Exception\CatalogSyncFailedException;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\IconStorageService;
use Bitrix\Vibecodeconnector\Internal\Integration\Rest\CatalogItemEmbeddingAccessSynchronizer;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\AccessRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemFilter;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\LastOpenedRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\PinRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\ViewedRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Access\AccessChangeHandler;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Access\AccessWriter;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Access\HiddenAccessCleaner;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatOnDemandService;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatWriteAccessService;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Item\CatalogItemSettings;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Launch\LaunchService;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\NewApps\NewAppsCountInvalidator;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Icon\IconUpdateIntent;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Icon\UploadedIconProcessor;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Sharing\CatalogSharingService;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Vibecode\CatalogItemSender;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Vibecode\CatalogItemUpdateSender;
use Bitrix\Vibecodeconnector\Public\Dto\CatalogItemCollection;
use Bitrix\Vibecodeconnector\Public\Service\AvailabilityService;
use Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Favorite\FavoriteCatalogItemCommand;
use Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Favorite\FavoriteCatalogItemCommandHandler;
use Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Favorite\UnfavoriteCatalogItemCommand;
use Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Favorite\UnfavoriteCatalogItemCommandHandler;
use Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Hide\HideCatalogItemCommand;
use Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Hide\HideCatalogItemCommandHandler;
use Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Hide\UnhideCatalogItemCommand;
use Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Hide\UnhideCatalogItemCommandHandler;
use Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\UpdateCatalogItemCommand;
use Bitrix\Vibecodeconnector\Public\Provider\CatalogProvider;

Loc::loadMessages(__FILE__);

final class Catalog extends Controller
{
	private const TITLE_MIN_LENGTH = 2;
	private const TITLE_MAX_LENGTH = 100;
	private const DESCRIPTION_MAX_LENGTH = 500;
	private const VIEW_SESSION_MAX_AGE = 86400;

	private CatalogProvider $catalog;
	private CatalogItemDtoMapper $mapper;
	private CatalogItemRepository $itemRepository;
	private PinRepository $pinRepository;
	private LastOpenedRepository $lastOpenedRepository;
	private ViewedRepository $viewedRepository;
	private AccessRepository $accessRepository;
	private AccessWriter $accessWriter;
	private AccessCodes $accessCodes;
	private HiddenAccessCleaner $hiddenAccessCleaner;
	private AccessChangeHandler $accessChangeHandler;
	private NewAppsCountInvalidator $newAppsCountInvalidator;
	private CatalogItemEmbeddingAccessSynchronizer $embeddingAccessSynchronizer;
	private LaunchService $launchService;
	private OpenAppLayoutService $openAppLayoutService;
	private CatalogItemUpdateSender $catalogItemSender;
	private IconStorageService $iconStorage;
	private UploadedIconProcessor $uploadedIconProcessor;
	private CatalogSharingService $sharingService;
	private AppChatOnDemandService $appChatOnDemandService;

	public function init(): void
	{
		parent::init();
		$this->catalog = new CatalogProvider();
		$this->mapper = new CatalogItemDtoMapper();
		$this->itemRepository = new CatalogItemRepository();
		$this->pinRepository = new PinRepository();
		$this->lastOpenedRepository = new LastOpenedRepository();
		$this->viewedRepository = new ViewedRepository();
		$this->accessRepository = new AccessRepository();
		$this->accessWriter = new AccessWriter($this->accessRepository, $this->itemRepository);
		$this->accessCodes = new AccessCodes();
		$this->hiddenAccessCleaner = new HiddenAccessCleaner();
		$this->accessChangeHandler = ServiceLocator::getInstance()->get(AccessChangeHandler::class);
		$this->newAppsCountInvalidator = new NewAppsCountInvalidator();
		$this->embeddingAccessSynchronizer = new CatalogItemEmbeddingAccessSynchronizer();
		$this->launchService = ServiceLocator::getInstance()->get(LaunchService::class);
		$this->openAppLayoutService = ServiceLocator::getInstance()->get(OpenAppLayoutService::class);
		$this->catalogItemSender = ServiceLocator::getInstance()->get(CatalogItemSender::class);
		$this->iconStorage = new IconStorageService();
		$this->uploadedIconProcessor = new UploadedIconProcessor($this->iconStorage);
		$this->sharingService = ServiceLocator::getInstance()->get(CatalogSharingService::class);
		$this->appChatOnDemandService = ServiceLocator::getInstance()->get(AppChatOnDemandService::class);
	}

	protected function processBeforeAction(Action $action)
	{
		if (
			$action->getName() === 'update'
			&& $this->getRequest()->isPost()
			&& $this->getRequest()->getPost('catalogItemId') === null
		)
		{
			$contentLength = (int)($this->getRequest()->getServer()->get('CONTENT_LENGTH') ?? 0);
			$postMaxSize = Ini::getInt('post_max_size');
			if ($postMaxSize > 0 && $contentLength > $postMaxSize)
			{
				$this->addError(new Error(
					(string)Loc::getMessage('VIBECODECONNECTOR_CATALOG_UPDATE_ERROR_TOO_LARGE'),
					UploadedIconProcessor::ERROR_SIZE,
				));

				return false;
			}
		}

		return parent::processBeforeAction($action);
	}

	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			// from the container, like the client extensions: the registration stays the
			// single place the availability service is assembled in
			new CheckCatalogAvailability(
				ServiceLocator::getInstance()->get(AvailabilityService::class),
			),
		];
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
				// recordOpen fires in parallel with the open-app page request: holding
				// the session write lock here delays the first byte of that page
				new ActionFilter\CloseSession(),
			],
		];
		$configureActions['update'] = [
			'-prefilters' => [
				ActionFilter\HttpMethod::class,
			],
			'+prefilters' => [
				new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
				new ActionFilter\CloseSession(),
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
		foreach (['launch', 'getShare', 'setShare', 'getLink', 'setLink'] as $action)
		{
			$configureActions[$action] = [
				'-prefilters' => [
					ActionFilter\HttpMethod::class,
				],
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\CloseSession(),
				],
			];
		}
		foreach (['favorite', 'unfavorite', 'openChat', 'setChatWriting', 'createChat'] as $action)
		{
			$configureActions[$action] = [
				'-prefilters' => [
					ActionFilter\HttpMethod::class,
				],
				'+prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
				],
			];
		}

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
			$currentUser->isAdmin(),
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
			$count = $this->catalog->countNewAppsForUser($userId);
		}

		return ['count' => $count];
	}

	public function myListAction(
		CurrentUser $currentUser,
		?string $q = null,
		?string $state = null,
		?int $previewUserId = null,
		?int $viewSession = null,
		?PageNavigation $pageNavigation = null,
	): array
	{
		$selfId = (int)$currentUser->getId();
		$userId = $this->resolveListUserId($currentUser, $previewUserId);
		$offset = $this->resolvePaginationOffset($pageNavigation);
		$limit = $this->resolvePaginationLimit($pageNavigation);
		$listState = CatalogListState::fromRequest($state);
		$isSelfListing = $userId === $selfId && $userId > 0;

		// Order matters: the stamp and the count describe the state before this page is
		// marked, so a client switching to the New state by this very response still finds
		// the apps it has just been shown.
		$session = $isSelfListing ? $this->resolveViewSession($viewSession, $offset) : null;
		// The count does not depend on the search query, and the client decides on its
		// starting selection by the first response without one. Unlike the stamp it is
		// reported in preview too — for the employee whose catalog is shown, the same way
		// the standalone count action does it. Computed without the cache: the starting
		// selection must not be decided by a value that may be up to the cache TTL old.
		// The price is a COUNT on every first page, so also on each selection switch inside
		// an open catalog, not just on opening it — paging itself pays nothing. Telling the
		// two apart needs a bootstrap flag in the request; the flag was not added because
		// the count is cheap next to the listing query it travels with.
		$hasSearchQuery = $q !== null && trim($q) !== '';
		$newAppsCount = $userId > 0 && $offset === 0 && !$hasSearchQuery
			? $this->catalog->countNewAppsForUser($userId, useCache: false)
			: null;

		$collection = $this->catalog->listAccessibleToUser(
			userId: $userId,
			query: $q,
			offset: $offset,
			limit: $limit + 1,
			state: $listState,
			markViewing: $isSelfListing,
			viewSession: $session,
			pageSize: $limit,
		);
		[$collection, $hasNext] = $this->slicePaginationCollection($collection, $limit);

		if ($isSelfListing)
		{
			$this->catalog->markCatalogOpenedForUser($userId);
		}

		$response = [
			'itemClickOpensChat' => (new CatalogItemSettings())->isClickOpensChatEnabled(),
			'items' => $this->mapper->toArrayList($collection, $currentUser->isAdmin()),
			'pagination' => [
				'offset' => $offset,
				'limit' => $limit,
				'hasNext' => $hasNext,
			],
		];

		if ($session !== null)
		{
			$response['viewSession'] = $session->getTimestamp();
		}

		if ($newAppsCount !== null)
		{
			$response['newAppsCount'] = $newAppsCount;
		}

		return $response;
	}

	/**
	 * The stamp is issued by the server: the client echoes it back while paging so items
	 * marked as viewed by the pages it already got stay in the New selection. A malformed
	 * stamp (not a positive integer or in the future) is replaced with a fresh one instead
	 * of being rejected.
	 *
	 * The age limit applies to the first page only. Replacing a stale stamp mid-paging
	 * would shrink the selection to items the earlier pages have not marked yet, while the
	 * offset keeps counting the whole one — the pages in between would be skipped. Paging
	 * therefore keeps the stamp it was started with, however old, and the limit takes
	 * effect on the next opening, which starts from the first page.
	 */
	private function resolveViewSession(?int $viewSession, int $offset = 0): DateTime
	{
		$now = time();
		if (
			$viewSession !== null
			&& $viewSession > 0
			&& $viewSession <= $now
			&& ($offset > 0 || $viewSession >= $now - self::VIEW_SESSION_MAX_AGE)
		)
		{
			return DateTime::createFromTimestamp($viewSession);
		}

		return DateTime::createFromTimestamp($now);
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
				$currentUser->isAdmin(),
			),
		];
	}

	public function getShareAction(CurrentUser $currentUser, int $catalogItemId): ?array
	{
		return $this->sharingResult($this->sharingService->getShare(
			$catalogItemId,
			(int)$currentUser->getId(),
			$currentUser->isAdmin(),
		));
	}

	public function getLinkAction(CurrentUser $currentUser, int $catalogItemId): ?array
	{
		return $this->sharingResult($this->sharingService->getLink(
			$catalogItemId,
			(int)$currentUser->getId(),
			$currentUser->isAdmin(),
		));
	}

	public function setShareAction(
		CurrentUser $currentUser,
		int $catalogItemId,
		string $audience,
		array $users = [],
		array $departments = [],
	): ?array {
		return $this->sharingResult($this->sharingService->setShare(
			$catalogItemId,
			(int)$currentUser->getId(),
			$currentUser->isAdmin(),
			$audience,
			$users,
			$departments,
		));
	}

	public function setLinkAction(
		CurrentUser $currentUser,
		int $catalogItemId,
		bool $enabled,
		?string $expiresAt = null,
		?bool $requireB24Auth = null,
	): ?array {
		return $this->sharingResult($this->sharingService->setLink(
			$catalogItemId,
			(int)$currentUser->getId(),
			$currentUser->isAdmin(),
			$enabled,
			$expiresAt,
			$requireB24Auth,
		));
	}

	private function sharingResult(Result $result): ?array
	{
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	public function openAppLayoutAction(
		CurrentUser $currentUser,
		int $catalogItemId,
	): array
	{
		return $this->openAppLayoutService->renderLayout(
			$catalogItemId,
			(int)$currentUser->getId(),
		)->toArray();
	}

	public function openAppPageAction(
		CurrentUser $currentUser,
		int $catalogItemId,
	): HttpResponse
	{
		$queryList = $this->getRequest()->getQueryList();
		$query = $queryList->toArrayRaw() ?? $queryList->toArray();
		$placementFields = array_intersect_key($query, array_flip([
			'PLACEMENT',
			'PLACEMENT_ID',
			'PLACEMENT_OPTIONS',
		]));

		return $this->openAppLayoutService->renderPageResponse(
			$catalogItemId,
			(int)$currentUser->getId(),
			$placementFields,
		);
	}

	public function launchAction(CurrentUser $currentUser, int $catalogItemId): AjaxJson
	{
		try
		{
			$result = $this->launchService->launch($catalogItemId, (int)$currentUser->getId());
		}
		catch (CatalogItemNotFoundException)
		{
			Context::getCurrent()->getResponse()->setStatus(404);
			$this->addError(new Error('Catalog item not found', 'CATALOG_ITEM_NOT_FOUND'));

			return AjaxJson::createError($this->errorCollection);
		}
		catch (AccessDeniedException)
		{
			Context::getCurrent()->getResponse()->setStatus(403);
			$this->addError(new Error('Access denied', 'ACCESS_DENIED'));

			return AjaxJson::createError($this->errorCollection);
		}
		$payload = ['kind' => $result->kind, 'launched' => true];
		if ($result->app !== null)
		{
			$payload += LaunchAppTargetDto::fromLaunchTarget($result->app)->toArray();
		}

		return AjaxJson::createSuccess($payload);
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

	public function createChatAction(
		CurrentUser $currentUser,
		int $catalogItemId,
	): array
	{
		$userId = (int)$currentUser->getId();
		$item = $this->mustGetItem($catalogItemId);

		if ($item->getOwnerId() !== $userId)
		{
			throw new AccessDeniedException('Only the owner of the application may create its chat');
		}

		$chatId = $this->appChatOnDemandService->createChatForOwner($catalogItemId, $userId);

		return ['ok' => $chatId > 0, 'chatId' => $chatId];
	}

	public function openChatAction(
		CurrentUser $currentUser,
		int $catalogItemId,
	): array
	{
		$userId = (int)$currentUser->getId();
		$this->mustGetItem($catalogItemId);

		if (!$this->itemRepository->isAccessibleToUser(
			$catalogItemId,
			$userId,
			$this->accessCodes->getUserCodesForItems($userId, [$catalogItemId]),
		))
		{
			throw new AccessDeniedException('User has no access to this catalog item');
		}

		$chatId = $this->appChatOnDemandService->ensureUserChatBestEffort($catalogItemId, $userId);

		return ['ok' => $chatId > 0, 'chatId' => $chatId];
	}

	public function setChatWritingAction(
		CurrentUser $currentUser,
		int $catalogItemId,
		string $allowed,
	): array
	{
		$userId = (int)$currentUser->getId();
		$item = $this->mustGetItem($catalogItemId);

		if ($item->getOwnerId() !== $userId)
		{
			throw new AccessDeniedException('Only the owner of the application may change this');
		}

		$writingAllowed = $this->parseYesNo($allowed);
		$applied = (new AppChatWriteAccessService())->setWritingAllowed($item, $writingAllowed);

		return ['ok' => $applied, 'allowed' => $writingAllowed];
	}

	private function parseYesNo(string $value): bool
	{
		return match (mb_strtoupper($value))
		{
			'Y' => true,
			'N' => false,
			default => throw new ArgumentException('Expected Y or N', 'allowed'),
		};
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
			$this->accessCodes->getUserCodesForItems($userId, [$catalogItemId]),
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
			$this->accessCodes->getUserCodesForItems($userId, [$catalogItemId]),
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

	public function favoriteAction(
		CurrentUser $currentUser,
		int $catalogItemId,
	): array
	{
		$userId = (int)$currentUser->getId();

		(new FavoriteCatalogItemCommandHandler())(new FavoriteCatalogItemCommand($userId, $catalogItemId));

		return ['ok' => true];
	}

	public function unfavoriteAction(
		CurrentUser $currentUser,
		int $catalogItemId,
	): array
	{
		$userId = (int)$currentUser->getId();

		(new UnfavoriteCatalogItemCommandHandler())(new UnfavoriteCatalogItemCommand($userId, $catalogItemId));

		return ['ok' => true];
	}

	public function recordOpenAction(
		CurrentUser $currentUser,
		int $catalogItemId,
	): AjaxJson
	{
		$userId = (int)$currentUser->getId();
		$userCodes = $this->accessCodes->getUserCodesForItems($userId, [$catalogItemId]);

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

		$item = $this->itemRepository->getById($catalogItemId);
		if ($item !== null)
		{
			$this->appChatOnDemandService->ensureUserChatBestEffort((int)$item->getId(), $userId);
		}

		$this->lastOpenedRepository->recordOpen($userId, $catalogItemId);
		$this->viewedRepository->markViewed($userId, [$catalogItemId]);
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
		$changed = $this->accessWriter->grant($catalogItemId, $accessCode);
		if ($changed)
		{
			$this->newAppsCountInvalidator->forgetAllFor($item);
			$this->accessChangeHandler->handle($item);
			$this->embeddingAccessSynchronizer->syncForItem($catalogItemId);
		}

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
		$changed = $this->accessWriter->revoke($catalogItemId, $accessCode);

		$this->hiddenAccessCleaner->cleanupLostAccess($catalogItemId);
		if ($changed)
		{
			$this->newAppsCountInvalidator->forgetAllFor($item);
			$this->accessChangeHandler->handle($item);
			$this->embeddingAccessSynchronizer->syncForItem($catalogItemId);
		}

		return ['ok' => true];
	}

	public function updateAction(
		CurrentUser $currentUser,
		int $catalogItemId,
		?string $title = null,
		?string $description = null,
		?string $iconAction = null,
	): array
	{
		$userId = (int)$currentUser->getId();
		$iconIntent = $this->resolveIconIntent($iconAction);
		$item = $this->mustGetItem($catalogItemId);
		$this->mustOwnItem($item, $userId);

		if ($item->getType() !== CatalogItemType::Application)
		{
			throw new ArgumentException(
				Loc::getMessage('VIBECODECONNECTOR_CATALOG_UPDATE_ERROR_TYPE'),
				'catalogItemId',
			);
		}

		$fields = [];

		if ($title !== null)
		{
			$fields['title'] = $this->validateTitle($title, $item->getTitle());
		}

		if ($description !== null)
		{
			$description = trim($this->validateDescription($description, $item->getDescription()) ?? '');
			$fields['description'] = $description === '' ? null : $description;
		}

		if (
			$iconIntent === IconUpdateIntent::Keep
			&& ($fields['title'] ?? $item->getTitle()) === $item->getTitle()
			&& (array_key_exists('description', $fields) ? $fields['description'] : $item->getDescription())
				=== $item->getDescription()
		)
		{
			return ['ok' => true];
		}

		$preparedIcon = $iconIntent === IconUpdateIntent::Replace ? $this->storeUploadedIcon() : null;
		$newIconFileId = $preparedIcon['fileId'] ?? null;

		if ($iconIntent !== IconUpdateIntent::Keep)
		{
			$fields['iconFileId'] = $newIconFileId;
		}

		$displacedIconFileId = null;
		$connection = Application::getConnection();
		try
		{
			$connection->startTransaction();

			$commandResult = (new UpdateCatalogItemCommand($userId, $catalogItemId, $fields))->run();
			$displacedIconFileId = $commandResult->getData()['displacedIconFileId'] ?? null;

			$item = $commandResult->getData()['item'];
			$this->catalogItemSender->updateItem($item, $userId, $iconIntent, $preparedIcon);

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			try
			{
				$connection->rollbackTransaction();
			}
			catch (TransactionException)
			{
			}

			if ($newIconFileId !== null)
			{
				$this->iconStorage->delete($newIconFileId);
			}

			Application::getInstance()->getExceptionHandler()->writeToLog($e);

			if ($e instanceof CatalogSyncFailedException)
			{
				throw new ArgumentException(
					Loc::getMessage('VIBECODECONNECTOR_CATALOG_UPDATE_ERROR_SYNC'),
					'catalogItemId',
					$e,
				);
			}

			throw new ArgumentException(
				Loc::getMessage('VIBECODECONNECTOR_CATALOG_UPDATE_ERROR_COMMON'),
				'catalogItemId',
				$e,
			);
		}

		if ($displacedIconFileId !== null)
		{
			$this->iconStorage->delete($displacedIconFileId);
		}

		$iconFileId = $item->getIconFileId();

		return [
			'ok' => true,
			'item' => [
				'id' => $catalogItemId,
				'title' => $item->getTitle(),
				'description' => $this->catalog->resolveDisplayDescription($item->getType(), $item->getDescription()),
				'isDescriptionDefault' => $item->getDescription() === null || $item->getDescription() === '',
				'iconUrl' => $iconFileId !== null ? $this->iconStorage->getPublicUrl($iconFileId) : null,
			],
		];
	}

	/**
	 * @return array{fileId: int, content: string, format: string}
	 */
	private function storeUploadedIcon(): array
	{
		$iconFile = $this->getRequest()->getFile('icon');
		if (!is_array($iconFile))
		{
			throw new ArgumentException(
				Loc::getMessage('VIBECODECONNECTOR_CATALOG_UPDATE_ERROR_ICON_REQUIRED'),
				'icon',
			);
		}

		$result = $this->uploadedIconProcessor->process($iconFile);
		if (!$result->isSuccess())
		{
			throw new ArgumentException($this->resolveIconErrorMessage($result), 'icon');
		}

		return $result->getData();
	}

	private function resolveIconErrorMessage(Result $result): string
	{
		$code = (string)($result->getErrors()[0]?->getCode() ?? '');
		$phraseId = match ($code)
		{
			UploadedIconProcessor::ERROR_SIZE => 'VIBECODECONNECTOR_CATALOG_UPDATE_ERROR_ICON_SIZE',
			UploadedIconProcessor::ERROR_PIXELS => 'VIBECODECONNECTOR_CATALOG_UPDATE_ERROR_ICON_PIXELS',
			UploadedIconProcessor::ERROR_FORMAT => 'VIBECODECONNECTOR_CATALOG_UPDATE_ERROR_ICON_FORMAT',
			UploadedIconProcessor::ERROR_UPLOAD => 'VIBECODECONNECTOR_CATALOG_UPDATE_ERROR_ICON_UPLOAD',
			default => 'VIBECODECONNECTOR_CATALOG_UPDATE_ERROR_ICON_SAVE',
		};

		return (string)Loc::getMessage($phraseId);
	}

	private function validateTitle(string $title, string $currentTitle): string
	{
		$title = trim($title);

		if (!mb_check_encoding($title, 'UTF-8'))
		{
			throw new ArgumentException(
				Loc::getMessage('VIBECODECONNECTOR_CATALOG_UPDATE_ERROR_TITLE_ENCODING'),
				'title',
			);
		}

		if ($title === $currentTitle)
		{
			return $title;
		}

		$length = mb_strlen($title);
		if ($length < self::TITLE_MIN_LENGTH)
		{
			throw new ArgumentException(
				Loc::getMessagePlural(
					'VIBECODECONNECTOR_CATALOG_UPDATE_ERROR_TITLE_MIN',
					self::TITLE_MIN_LENGTH,
					['#MIN#' => self::TITLE_MIN_LENGTH],
				),
				'title',
			);
		}

		if ($length > self::TITLE_MAX_LENGTH)
		{
			throw new ArgumentException(
				Loc::getMessagePlural(
					'VIBECODECONNECTOR_CATALOG_UPDATE_ERROR_TITLE_MAX',
					self::TITLE_MAX_LENGTH,
					['#MAX#' => self::TITLE_MAX_LENGTH],
				),
				'title',
			);
		}

		if (preg_match('/[\x00-\x1F\x7F]/u', $title) !== 0)
		{
			throw new ArgumentException(
				Loc::getMessage('VIBECODECONNECTOR_CATALOG_UPDATE_ERROR_TITLE_CONTROL'),
				'title',
			);
		}

		return $title;
	}

	private function validateDescription(?string $description, ?string $currentDescription): ?string
	{
		if ($description === null)
		{
			return null;
		}

		if (!mb_check_encoding($description, 'UTF-8'))
		{
			throw new ArgumentException(
				Loc::getMessage('VIBECODECONNECTOR_CATALOG_UPDATE_ERROR_DESCRIPTION_ENCODING'),
				'description',
			);
		}

		if (trim($description) === $currentDescription)
		{
			return $description;
		}

		if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH)
		{
			throw new ArgumentException(
				Loc::getMessagePlural(
					'VIBECODECONNECTOR_CATALOG_UPDATE_ERROR_DESCRIPTION_LENGTH',
					self::DESCRIPTION_MAX_LENGTH,
					['#MAX#' => self::DESCRIPTION_MAX_LENGTH],
				),
				'description',
			);
		}

		if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $description) !== 0)
		{
			throw new ArgumentException(
				Loc::getMessage('VIBECODECONNECTOR_CATALOG_UPDATE_ERROR_DESCRIPTION_CONTROL'),
				'description',
			);
		}

		return $description;
	}

	private function resolveIconIntent(?string $iconAction): IconUpdateIntent
	{
		if ($iconAction === null || $iconAction === '')
		{
			return IconUpdateIntent::Keep;
		}

		return IconUpdateIntent::tryFrom($iconAction)
			?? throw new ArgumentException('Unsupported iconAction value', 'iconAction');
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
