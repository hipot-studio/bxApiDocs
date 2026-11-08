<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

use Bitrix\Im\V2\Chat;
use Bitrix\Main\Messenger\Entity\ProcessingParam\ItemIdParam;
use Bitrix\Main\UserTable;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\AddedMembers;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\AppChatType;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\ChatLocator;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\ChatMember;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\ChatMembershipFactory;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\ChatMembershipWriter;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\ChatRelations;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\ImGate;
use Bitrix\Vibecodeconnector\Internal\Integration\Intranet\IntranetGate;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\AccessRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogUserRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\NewApps\NewAppsCountCache;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message\AppChatItemSyncMessage;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message\AppChatUserSyncMessage;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\QueueId;

final class AppChatMembershipService
{
	private const CONVERGENCE_BATCH = 20;
	private const USER_SYNC_ITEMS_PER_JOB = 50;

	private readonly ChatRelations $chatRelations;
	private readonly ChatMembershipWriter $membershipWriter;

	public function __construct(
		private readonly AccessRepository $accessRepository = new AccessRepository(),
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly AccessCodes $accessCodes = new AccessCodes(),
		private readonly IntranetGate $intranetGate = new IntranetGate(),
		private readonly AppAudienceResolver $audienceResolver = new AppAudienceResolver(),
		private readonly ChatLocator $chatLocator = new ChatLocator(),
		private readonly AppChatService $appChatService = new AppChatService(),
		private readonly AppChatMessageService $messageService = new AppChatMessageService(),
		?ChatRelations $chatRelations = null,
		private readonly NewAppsCountCache $newAppsCountCache = new NewAppsCountCache(),
		private readonly ?\Closure $audienceLoader = null,
		private readonly ?\Closure $messengerAvailable = null,
		private readonly ?\Closure $addUsers = null,
		private readonly AppChatAvailability $availability = new AppChatAvailability(),
		private readonly AppChatMembershipSettings $membershipSettings = new AppChatMembershipSettings(),
		private readonly CatalogUserRepository $catalogUserRepository = new CatalogUserRepository(),
		private readonly ?int $audienceReadBatch = null,
		?ChatMembershipWriter $membershipWriter = null,
		private readonly ImGate $imGate = new ImGate(),
	)
	{
		$this->chatRelations = $chatRelations ?? ChatMembershipFactory::createRelations();
		$this->membershipWriter = $membershipWriter ?? ChatMembershipFactory::createWriter();
	}

	public function scheduleItemSync(int $catalogItemId): bool
	{
		if ($catalogItemId <= 0 || !$this->availability->isEnabled())
		{
			return false;
		}

		$this->sendItemMessage(new AppChatItemSyncMessage($catalogItemId));

		return true;
	}

	public function resolveSyncRevision(int $catalogItemId): int
	{
		return $this->itemRepository->getAppChatSyncRevision($catalogItemId);
	}

	public function subscribeOwner(CatalogItem $item): int
	{
		if (
			(int)$item->getId() <= 0
			|| $item->getOwnerId() <= 0
			|| !$this->availability->isEnabled()
			|| !$this->isMessengerAvailable()
		)
		{
			return 0;
		}

		$chat = $this->loadAppChat($item);

		return $chat === null
			? 0
			: $this->synchronizeTarget($chat, $item, [$item->getOwnerId()]);
	}

	public function requestUserSync(int $userId): void
	{
		if ($userId <= 0 || !$this->availability->isEnabled())
		{
			return;
		}

		$this->sendUserMessage(new AppChatUserSyncMessage($userId));
	}

	public function applyItemSync(
		int $catalogItemId,
		int $revision,
		int $afterUserId,
		AppChatMembershipPhase $phase = AppChatMembershipPhase::Audience,
	): AppChatMembershipOutcome
	{
		if ($catalogItemId <= 0 || !$this->availability->isEnabled())
		{
			return AppChatMembershipOutcome::completed();
		}
		if (!$this->isMessengerAvailable())
		{
			return AppChatMembershipOutcome::retryable(
				'the messenger is unavailable',
				retryScope: AppChatMembershipRetryScope::GlobalTransient,
			);
		}

		$item = $this->itemRepository->getById($catalogItemId);
		if ($item === null)
		{
			return AppChatMembershipOutcome::completed();
		}
		if ($item->getType() !== CatalogItemType::Application || $item->isDeactivated())
		{
			$this->itemRepository->markAppChatSyncedRevision($catalogItemId, $revision);

			return AppChatMembershipOutcome::completed();
		}

		$outcome = $this->applyLiveItemStep(
			$item,
			$afterUserId,
			$phase,
			null,
		);
		if ($outcome->isComplete())
		{
			$this->itemRepository->markAppChatSyncedRevision($catalogItemId, $revision);
		}

		return $outcome;
	}

	public function applyBackfillStep(
		int $catalogItemId,
		int $afterUserId,
		AppChatMembershipPhase $phase = AppChatMembershipPhase::Audience,
		int $expectedChatId = 0,
	): AppChatMembershipOutcome
	{
		if ($catalogItemId <= 0 || !$this->availability->isEnabled())
		{
			return AppChatMembershipOutcome::completed();
		}
		if (!$this->isMessengerAvailable())
		{
			return AppChatMembershipOutcome::retryable(
				'the messenger is unavailable',
				retryScope: AppChatMembershipRetryScope::GlobalTransient,
			);
		}

		$item = $this->itemRepository->getById($catalogItemId);
		if (
			$item === null
			|| $item->getType() !== CatalogItemType::Application
			|| $item->isDeactivated()
		)
		{
			return AppChatMembershipOutcome::completed();
		}

		return $this->applyLiveItemStep(
			$item,
			$afterUserId,
			$phase,
			max(0, $expectedChatId),
		);
	}

	private function applyLiveItemStep(
		CatalogItem $item,
		int $afterUserId,
		AppChatMembershipPhase $phase,
		?int $expectedChatId,
	): AppChatMembershipOutcome
	{
		$chat = $this->loadAppChat($item);
		if ($chat === null)
		{
			$this->appChatService->ensureAppChat($item);

			$item = $this->itemRepository->getById((int)$item->getId());
			if ($item === null)
			{
				return AppChatMembershipOutcome::completed();
			}

			$chat = $this->loadAppChat($item);
		}
		if ($chat === null)
		{
			return AppChatMembershipOutcome::retryable('the app chat of the item is missing');
		}

		$actualChatId = (int)$chat->getChatId();
		$firstPage = $phase === AppChatMembershipPhase::Audience && $afterUserId === 0;
		if (
			$expectedChatId !== null
			&& (
				($expectedChatId === 0 && !$firstPage)
				|| ($expectedChatId > 0 && $expectedChatId !== $actualChatId)
			)
		)
		{
			return AppChatMembershipOutcome::restartWithChat($actualChatId);
		}

		if ($firstPage)
		{
			$updatedChat = $this->appChatService->syncMetadataNow((int)$item->getId());
			$currentItem = $this->itemRepository->getById((int)$item->getId());
			if (
				$currentItem === null
				|| $currentItem->getType() !== CatalogItemType::Application
				|| $currentItem->isDeactivated()
			)
			{
				return AppChatMembershipOutcome::completed();
			}

			$currentChat = $this->loadAppChat($currentItem);
			if ($updatedChat === null || $currentChat === null)
			{
				return AppChatMembershipOutcome::retryable('the app chat of the item is missing');
			}

			$currentChatId = (int)$currentChat->getChatId();
			if ((int)$updatedChat->getChatId() !== $currentChatId)
			{
				return $expectedChatId === null
					? AppChatMembershipOutcome::retryable('the app chat binding changed during metadata synchronization')
					: AppChatMembershipOutcome::restartWithChat($currentChatId);
			}
			if (
				$expectedChatId !== null
				&& $expectedChatId > 0
				&& $expectedChatId !== $currentChatId
			)
			{
				return AppChatMembershipOutcome::restartWithChat($currentChatId);
			}

			$item = $currentItem;
			$chat = $updatedChat;
		}

		return $this->applyIntent(
			$item,
			$chat,
			$phase,
			max(0, $afterUserId),
		);
	}

	public function applyUserSync(int $userId, int $afterItemId): AppChatMembershipOutcome
	{
		if ($userId <= 0)
		{
			return AppChatMembershipOutcome::completed();
		}
		if (!$this->availability->isEnabled())
		{
			return AppChatMembershipOutcome::completed();
		}
		if (!$this->isMessengerAvailable())
		{
			return AppChatMembershipOutcome::retryable(
				'the messenger is unavailable',
				retryScope: AppChatMembershipRetryScope::GlobalTransient,
			);
		}

		$page = $this->itemRepository->getLiveAppChatItems(
			max(0, $afterItemId),
			self::USER_SYNC_ITEMS_PER_JOB,
		)->toArray();
		if ($page === [])
		{
			return AppChatMembershipOutcome::completed();
		}

		$entitled = $this->resolveUserEntitlement($userId);
		if ($entitled === null)
		{
			return AppChatMembershipOutcome::retryable('the employee directory is unavailable');
		}

		$granted = $entitled ? $this->resolveGrantedItemIds($page, $userId) : [];
		if ($granted === null)
		{
			return AppChatMembershipOutcome::retryable('the employee directory is unavailable');
		}

		$chatIds = [];
		foreach ($page as $item)
		{
			$chatIds[] = (int)$item->getAppChatId();
		}
		$memberships = $this->chatRelations->getUserMemberships($userId, $chatIds);
		$unfinishedItemIds = array_fill_keys($this->catalogUserRepository->filterArrivalPendingItemIds(
			$userId,
			array_map(static fn (CatalogItem $item): int => (int)$item->getId(), $page),
		), true);
		$added = 0;
		$touched = false;
		$lostItemIds = [];
		foreach ($page as $item)
		{
			$chatId = (int)$item->getAppChatId();
			$member = $memberships[$chatId] ?? null;
			$hasAccess = $this->userHasAccess($item, $userId, $entitled, isset($granted[(int)$item->getId()]));
			if ($hasAccess)
			{
				if ($member !== null && !$member->isHidden && !isset($unfinishedItemIds[(int)$item->getId()]))
				{
					continue;
				}

				$chat = $this->loadAppChat($item);
				if ($chat === null)
				{
					continue;
				}

				$added += $this->synchronizeTarget($chat, $item, [$userId]);
				$touched = true;

				continue;
			}

			if ($userId === $item->getOwnerId())
			{
				continue;
			}

			$this->catalogUserRepository->forgetLeft((int)$item->getId(), $userId);
			$lostItemIds[] = (int)$item->getId();

			if ($member === null)
			{
				continue;
			}

			$chat = $this->loadAppChat($item);
			if ($chat === null)
			{
				continue;
			}

			$this->deleteMembers($chat, $chatId, [$userId]);
			$touched = true;
		}

		$this->catalogUserRepository->clearArrivalPendingForUser($userId, $lostItemIds);
		if ($touched)
		{
			$this->newAppsCountCache->forget($userId);
		}

		if (count($page) < self::USER_SYNC_ITEMS_PER_JOB)
		{
			return AppChatMembershipOutcome::completed($added);
		}

		return AppChatMembershipOutcome::advanced((int)end($page)->getId(), $added);
	}

	public function subscribeUser(CatalogItem $item, int $userId): int
	{
		if (
			$userId <= 0
			|| !$this->availability->isEnabled()
			|| !$this->audienceResolver->hasAccess($item, $userId)
			|| !$this->isMessengerAvailable()
			|| $this->leavePolicy()->holdsOut($item, $userId)
		)
		{
			return 0;
		}

		$chat = $this->loadAppChat($item);
		if ($chat === null)
		{
			return 0;
		}

		$this->synchronizeTarget($chat, $item, [$userId]);
		$this->newAppsCountCache->forget($userId);

		return (int)$chat->getChatId();
	}

	public function subscribeUserBestEffort(CatalogItem $item, int $userId): int
	{
		try
		{
			return $this->subscribeUser($item, $userId);
		}
		catch (\Throwable $exception)
		{
			try
			{
				\AddMessage2Log(
					'Catalog item ' . (int)$item->getId() . ': launch did not subscribe user '
						. $userId . ' to the app chat, ' . $exception->getMessage(),
					'vibecodeconnector',
				);
			}
			catch (\Throwable)
			{
			}

			return 0;
		}
	}

	private function resolveUserEntitlement(int $userId): ?bool
	{
		if ($this->filterActiveUserIds([$userId]) === [])
		{
			return false;
		}

		$employeeDecision = $this->intranetGate->resolveIntranetUsers([$userId]);
		if ($employeeDecision->undecided !== [])
		{
			return null;
		}

		return $employeeDecision->employees !== [];
	}

	/**
	 * @param CatalogItem[] $items
	 *
	 * @return array<int, true>|null
	 */
	private function resolveGrantedItemIds(array $items, int $userId): ?array
	{
		$aclItemIds = [];
		foreach ($items as $item)
		{
			if ($item->getAccessType() === CatalogItemAccessType::ACL)
			{
				$aclItemIds[] = (int)$item->getId();
			}
		}

		$granted = [];
		foreach ($this->accessRepository->getCodesByItemIds($aclItemIds) as $itemId => $codes)
		{
			if (!$this->accessCodes->canResolveAudience($codes))
			{
				return null;
			}
			if ($this->accessCodes->resolveCodeHolders([$userId], $codes)->holders !== [])
			{
				$granted[$itemId] = true;
			}
		}

		return $granted;
	}

	private function userHasAccess(
		CatalogItem $item,
		int $userId,
		bool $entitled,
		bool $isGranted,
	): bool
	{
		if (!$entitled)
		{
			return false;
		}

		return match ($item->getAccessType())
		{
			CatalogItemAccessType::Private => $userId === $item->getOwnerId(),
			CatalogItemAccessType::Public => true,
			CatalogItemAccessType::ACL => $userId === $item->getOwnerId() || $isGranted,
		};
	}

	private function loadAudiencePage(CatalogItem $item, int $afterUserId, int $limit): array
	{
		if ($item->getAccessType() === CatalogItemAccessType::Private)
		{
			return ['userIds' => [], 'nextCursor' => null, 'undecided' => false];
		}

		$codes = $item->getAccessType() === CatalogItemAccessType::ACL
			? $this->accessRepository->getCodesForItem((int)$item->getId())
			: [];
		if ($this->audienceLoader !== null)
		{
			$loaded = ($this->audienceLoader)($item, $codes, $limit);
			if ($loaded === null)
			{
				return ['userIds' => [], 'nextCursor' => null, 'undecided' => true];
			}

			$remaining = array_values(array_filter(
				$this->normalizeUserIds($loaded),
				static fn (int $userId): bool => $userId > $afterUserId,
			));
			$page = array_slice($remaining, 0, $limit);

			return [
				'userIds' => $page,
				'nextCursor' => count($remaining) > $limit ? max($page) : null,
				'undecided' => false,
			];
		}

		$companyAudience = $item->getAccessType() === CatalogItemAccessType::Public
			|| AccessCodes::grantsEveryone($codes);
		if (!$companyAudience && !$this->accessCodes->canResolveAudience($codes))
		{
			return ['userIds' => [], 'nextCursor' => null, 'undecided' => true];
		}
		$candidates = $companyAudience
			? $this->loadActiveUserIds($afterUserId, $limit)
			: $this->accessCodes->getUserIdsByCodes($codes, $limit, $afterUserId);
		if ($candidates === [])
		{
			return ['userIds' => [], 'nextCursor' => null, 'undecided' => false];
		}

		$holders = $companyAudience ? $candidates : $this->filterActiveUserIds($candidates);
		if (!$companyAudience)
		{
			$holders = $this->accessCodes->resolveCodeHolders($holders, $codes)->holders;
		}

		$employeeDecision = $this->intranetGate->resolveIntranetUsers($holders);
		if ($employeeDecision->undecided !== [])
		{
			return ['userIds' => [], 'nextCursor' => null, 'undecided' => true];
		}

		return [
			'userIds' => $employeeDecision->employees,
			'nextCursor' => count($candidates) === $limit ? max($candidates) : null,
			'undecided' => false,
		];
	}

	private function loadActiveUserIds(int $afterUserId, int $limit): array
	{
		$rows = UserTable::query()
			->setSelect(['ID'])
			->where('ID', '>', $afterUserId)
			->where('ACTIVE', 'Y')
			->where('REAL_USER', 'expr', true)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['ID'], $rows);
	}

	private function filterActiveUserIds(array $userIds): array
	{
		$userIds = $this->normalizeUserIds($userIds);
		if ($userIds === [])
		{
			return [];
		}

		$rows = UserTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $userIds)
			->where('ACTIVE', 'Y')
			->fetchAll()
		;
		$active = array_fill_keys(array_map(
			static fn (array $row): int => (int)$row['ID'],
			$rows,
		), true);

		return array_values(array_filter(
			$userIds,
			static fn (int $userId): bool => isset($active[$userId]),
		));
	}

	private function normalizeUserIds(array $userIds): array
	{
		$normalized = array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn (int $userId): bool => $userId > 0,
		)));
		sort($normalized, SORT_NUMERIC);

		return $normalized;
	}

	/**
	 * @param int[] $target
	 */
	private function synchronizeTarget(Chat $chat, CatalogItem $item, array $target): int
	{
		if ($target === [])
		{
			return 0;
		}

		$chatId = (int)$chat->getChatId();
		$currentMembers = $this->chatRelations->getMembers($chatId, $target);
		$toAdd = array_values(array_filter(
			$target,
			static fn (int $userId): bool => !isset($currentMembers[$userId]) || $currentMembers[$userId]->isHidden,
		));
		$toAdd = $this->withoutLeavers($item, $toAdd);
		$unfinished = $this->findUnfinishedArrivals($item, $currentMembers);
		if ($toAdd === [] && $unfinished === [])
		{
			return 0;
		}

		$arriving = array_values(array_unique(array_merge($toAdd, $unfinished)));
		$this->beginArrival($item, $arriving);
		$arrived = $this->addArriving($chat, $item, $arriving);
		$this->finishArrival($item, $arriving);
		$this->newAppsCountCache->forgetMany($arriving);

		return count(array_intersect($toAdd, $arrived->addedUserIds));
	}

	/**
	 * @param array<int, ChatMember> $currentMembers
	 * @return int[] visible members whose arrival was interrupted after their relation had been written
	 */
	private function findUnfinishedArrivals(CatalogItem $item, array $currentMembers): array
	{
		$members = [];
		foreach ($currentMembers as $userId => $member)
		{
			if (!$member->isHidden && $userId !== $item->getOwnerId())
			{
				$members[] = $userId;
			}
		}

		return $this->catalogUserRepository->filterArrivalPending((int)$item->getId(), $members);
	}

	/**
	 * @param int[] $userIds
	 */
	private function addArriving(Chat $chat, CatalogItem $item, array $userIds): AddedMembers
	{
		if ($this->addUsers !== null)
		{
			($this->addUsers)($chat, $userIds);

			return new AddedMembers($userIds);
		}

		$chatId = (int)$chat->getChatId();
		$ownerId = $item->getOwnerId();
		$arrived = in_array($ownerId, $userIds, true)
			? $this->membershipWriter->add($chatId, [$ownerId])
			: new AddedMembers();
		$audience = array_values(array_diff($userIds, [$ownerId]));
		if ($audience === [])
		{
			return $arrived;
		}

		return $arrived->merge($this->membershipWriter->add(
			$chatId,
			$audience,
			$this->isCompanyAudience($item) ? $audience : [],
			$this->messageService->findWelcomeMessageId($item, $chatId),
		));
	}

	/**
	 * @param int[] $userIds
	 */
	private function beginArrival(CatalogItem $item, array $userIds): void
	{
		$this->catalogUserRepository->markArrivalPending(
			(int)$item->getId(),
			array_diff($userIds, [$item->getOwnerId()]),
		);
	}

	/**
	 * @param int[] $userIds
	 */
	private function finishArrival(CatalogItem $item, array $userIds): void
	{
		$this->catalogUserRepository->clearArrivalPending(
			(int)$item->getId(),
			array_diff($userIds, [$item->getOwnerId()]),
		);
	}

	/**
	 * @param string[]|null $codes
	 */
	private function isCompanyAudience(CatalogItem $item, ?array $codes = null): bool
	{
		if ($item->getAccessType() === CatalogItemAccessType::Public)
		{
			return true;
		}
		if ($item->getAccessType() !== CatalogItemAccessType::ACL)
		{
			return false;
		}

		$codes ??= $this->accessRepository->getCodesForItem((int)$item->getId());

		return in_array(AccessCodes::ACCESS_CODE_AUTHORIZED, $codes, true);
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	private function withoutLeavers(CatalogItem $item, array $userIds): array
	{
		$heldOut = $this->leavePolicy()->filterHeldOut($item, $userIds);
		if ($heldOut === [])
		{
			return $userIds;
		}

		$heldOutIndex = array_fill_keys($heldOut, true);

		return array_values(array_filter(
			$userIds,
			static fn (int $userId): bool => !isset($heldOutIndex[$userId]),
		));
	}

	private function leavePolicy(): AppChatLeavePolicy
	{
		return new AppChatLeavePolicy($this->catalogUserRepository);
	}

	private function applyIntent(
		CatalogItem $item,
		Chat $chat,
		AppChatMembershipPhase $phase,
		int $afterUserId,
	): AppChatMembershipOutcome
	{
		return $phase === AppChatMembershipPhase::Audience
			? $this->applyAudienceBatch(
				$item,
				$chat,
				$afterUserId,
			)
			: $this->applyConvergenceBatch($item, $chat, $afterUserId);
	}

	private function applyAudienceBatch(
		CatalogItem $item,
		Chat $chat,
		int $afterUserId,
	): AppChatMembershipOutcome
	{
		$chatId = (int)$chat->getChatId();
		$ownerId = $item->getOwnerId();
		// A page limit below one would mean no LIMIT at all for the ORM, that is a full read.
		$pageLimit = max(1, $this->resolveAudienceReadBatch() - (int)($afterUserId === 0 && $ownerId > 0));
		$page = $this->loadAudiencePage($item, $afterUserId, $pageLimit);
		if ($page['undecided'])
		{
			return AppChatMembershipOutcome::retryable(
				'the employee directory is unavailable',
				chatId: $chatId,
			);
		}
		$target = array_values(array_diff($page['userIds'], [$ownerId]));
		if ($afterUserId === 0 && $ownerId > 0)
		{
			$target[] = $ownerId;
		}
		$added = $this->synchronizeTarget($chat, $item, $target);

		if ($page['nextCursor'] !== null)
		{
			return AppChatMembershipOutcome::continued(
				$added,
				AppChatMembershipPhase::Audience,
				$page['nextCursor'],
				$chatId,
			);
		}

		return AppChatMembershipOutcome::continued($added, AppChatMembershipPhase::Members, 0, $chatId);
	}

	/**
	 * A method call cannot be a parameter default, so an unset page size is resolved here.
	 */
	private function resolveAudienceReadBatch(): int
	{
		return $this->audienceReadBatch ?? $this->membershipSettings->getAudienceReadBatch();
	}

	private function applyConvergenceBatch(
		CatalogItem $item,
		Chat $chat,
		int $afterUserId,
	): AppChatMembershipOutcome
	{
		$chatId = (int)$chat->getChatId();
		$memberIds = $this->chatRelations->getMemberIdsAfter(
			$chatId,
			$afterUserId,
			self::CONVERGENCE_BATCH + 1,
		);
		$leftUserIds = $this->catalogUserRepository->getLeftUserIdsAfter(
			(int)$item->getId(),
			$afterUserId,
			self::CONVERGENCE_BATCH + 1,
		);
		$isMember = array_fill_keys($memberIds, true);
		$candidates = $this->normalizeUserIds(array_merge($memberIds, $leftUserIds));
		$batch = array_slice($candidates, 0, self::CONVERGENCE_BATCH);
		$toDrop = array_values(array_diff($batch, [$item->getOwnerId()]));
		if ($toDrop !== [])
		{
			$activeUserIds = $this->filterActiveUserIds($toDrop);
			$codes = $this->accessRepository->getCodesForItem((int)$item->getId());
			$decision = $this->audienceResolver->resolveAudience($item, $activeUserIds, $codes);
			if ($decision->undecided !== [])
			{
				return AppChatMembershipOutcome::retryable(
					'the employee status of the current members is unknown',
					chatId: $chatId,
				);
			}
			$withAccess = array_fill_keys($decision->withAccess, true);
			$deniedUserIds = [];
			foreach ($toDrop as $userId)
			{
				if (isset($withAccess[$userId]))
				{
					continue;
				}
				$denied = $this->isCurrentlyDenied((int)$item->getId(), $userId);
				if ($denied === null)
				{
					return AppChatMembershipOutcome::retryable(
						'the employee status of a current member is unknown',
						chatId: $chatId,
					);
				}
				if ($denied)
				{
					$deniedUserIds[] = $userId;
				}
			}
			if ($deniedUserIds !== [])
			{
				$this->catalogUserRepository->clearArrivalPending((int)$item->getId(), $deniedUserIds);
				$deniedMembers = [];
				foreach ($deniedUserIds as $userId)
				{
					if (isset($isMember[$userId]))
					{
						$deniedMembers[] = $userId;

						continue;
					}

					$this->catalogUserRepository->forgetLeft((int)$item->getId(), $userId);
				}

				try
				{
					$this->deleteMembers($chat, $chatId, $deniedMembers);
				}
				finally
				{
					$this->newAppsCountCache->forgetMany($deniedUserIds);
				}
			}
		}

		if (count($candidates) <= self::CONVERGENCE_BATCH)
		{
			return AppChatMembershipOutcome::completed(chatId: $chatId);
		}

		return AppChatMembershipOutcome::continued(
			0,
			AppChatMembershipPhase::Members,
			max($batch),
			$chatId,
		);
	}

	private function deleteMembers(Chat $chat, int $chatId, array $userIds): void
	{
		if ($userIds === [])
		{
			return;
		}

		$catalogItemId = (int)$chat->getEntityId();
		foreach ($userIds as $userId)
		{
			$this->catalogUserRepository->forgetLeft($catalogItemId, $userId);
		}

		$this->membershipWriter->delete($chatId, $userIds);
	}

	private function isCurrentlyDenied(int $catalogItemId, int $userId): ?bool
	{
		if ($this->filterActiveUserIds([$userId]) === [])
		{
			return true;
		}

		$item = $this->itemRepository->getById($catalogItemId);
		if ($item === null || $item->isDeactivated() || $item->getType() !== CatalogItemType::Application)
		{
			return true;
		}

		$decision = $this->audienceResolver->resolveAudience(
			$item,
			[$userId],
			$this->accessRepository->getCodesForItem($catalogItemId),
		);
		if ($decision->undecided !== [])
		{
			return null;
		}

		return $decision->withAccess === [];
	}

	private function loadAppChat(CatalogItem $item): ?Chat
	{
		$chat = $this->chatLocator->find((int)$item->getAppChatId());

		return $chat !== null
			&& $chat->getEntityType() === AppChatType::ENTITY_TYPE
			&& (string)$chat->getEntityId() === (string)$item->getId()
				? $chat->withContextUser($item->getOwnerId())
				: null;
	}

	private function isMessengerAvailable(): bool
	{
		return $this->messengerAvailable !== null
			? ($this->messengerAvailable)()
			: $this->imGate->isAvailable();
	}

	private function sendItemMessage(AppChatItemSyncMessage $message): void
	{
		$message->send(
			QueueId::AppChatMembership->value,
			$this->processingParams('item.' . $message->catalogItemId),
		);
	}

	private function sendUserMessage(AppChatUserSyncMessage $message): void
	{
		$message->send(
			QueueId::AppChatMembership->value,
			$this->processingParams('user.' . $message->userId),
		);
	}

	private function processingParams(string $itemId): array
	{
		return [new ItemIdParam($itemId)];
	}
}
