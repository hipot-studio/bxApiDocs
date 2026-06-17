<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Integration\Im;

use Bitrix\BIConnector\Access\Model\UserAccessItem;
use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupBindingTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Entity\SupersetDashboardChat;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardChatRepository;
use Bitrix\Bitrix24\Feature;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class DashboardDiscussionChatAccessSyncService
{
	private SupersetDashboardChatRepository $dashboardChatRepository;
	private DashboardDiscussionChatService $dashboardDiscussionChatService;
	private ?bool $rightsFeatureEnabled = null;

	public function __construct()
	{
		$this->dashboardChatRepository = ServiceLocator::getInstance()->get(SupersetDashboardChatRepository::class);
		$this->dashboardDiscussionChatService = ServiceLocator::getInstance()->get('biconnector.service.dashboardDiscussionChat');
	}

	public function syncAll(): Result
	{
		return $this->syncDashboardChats($this->dashboardChatRepository->getAll());
	}

	public function syncByDashboardIds(array $dashboardIds): Result
	{
		return $this->syncDashboardChats($this->dashboardChatRepository->getByDashboardIds($dashboardIds));
	}

	/**
	 * @param SupersetDashboardChat[] $dashboardChats
	 */
	private function syncDashboardChats(array $dashboardChats): Result
	{
		$result = new Result();
		$syncedDashboardIds = [];
		$removedUsersByChat = [];
		$dashboardIds = [];
		$chatIds = [];

		foreach ($dashboardChats as $dashboardChat)
		{
			$dashboardId = $dashboardChat->getDashboardId();
			$chatId = $dashboardChat->getChatId();
			if ($dashboardId <= 0 || $chatId <= 0)
			{
				continue;
			}

			$dashboardIds[$dashboardId] = $dashboardId;
			$chatIds[$chatId] = $chatId;
		}

		$dashboardAccessContexts = $this->loadDashboardAccessContexts(array_values($dashboardIds));
		$chatUserIdsResult = $this->dashboardDiscussionChatService->getChatUserIdsMap(array_values($chatIds));
		if (!$chatUserIdsResult->isSuccess())
		{
			$result->addErrors($chatUserIdsResult->getErrors());
		}

		$chatUserIdsByChat = $chatUserIdsResult->getData()['userIdsByChat'] ?? [];

		foreach ($dashboardChats as $dashboardChat)
		{
			$dashboardId = $dashboardChat->getDashboardId();
			$chatId = $dashboardChat->getChatId();
			if ($dashboardId <= 0 || $chatId <= 0)
			{
				continue;
			}

			$chatUserIds = $chatUserIdsByChat[$chatId] ?? null;
			if ($chatUserIds === null)
			{
				continue;
			}

			$userIdsToRemove = [];
			foreach ($chatUserIds as $userId)
			{
				if (!$this->hasDashboardViewAccess($userId, $dashboardAccessContexts[$dashboardId] ?? null))
				{
					$userIdsToRemove[] = $userId;
				}
			}

			if (!empty($userIdsToRemove))
			{
				$removeUsersResult = $this->dashboardDiscussionChatService->removeUsersFromChat($chatId, $userIdsToRemove);
				if (!$removeUsersResult->isSuccess())
				{
					$result->addErrors($removeUsersResult->getErrors());
					continue;
				}

				$removedUsersByChat[$chatId] = $userIdsToRemove;
			}

			$syncedDashboardIds[$dashboardId] = $dashboardId;
		}

		$result->setData([
			'dashboardIds' => array_values($syncedDashboardIds),
			'removedUsersByChat' => $removedUsersByChat,
		]);

		return $result;
	}

	private function normalizeIds(array $ids): array
	{
		$normalizedIds = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$normalizedIds[$id] = $id;
			}
		}

		return array_values($normalizedIds);
	}

	/**
	 * @return array<int, array{requiredPermissionCode:int, groupIds:int[]}>
	 */
	private function loadDashboardAccessContexts(array $dashboardIds): array
	{
		$dashboardIds = $this->normalizeIds($dashboardIds);
		if (empty($dashboardIds))
		{
			return [];
		}

		$dashboardRows = SupersetDashboardTable::getList([
			'select' => ['ID', 'STATUS'],
			'filter' => ['@ID' => $dashboardIds],
		])->fetchAll();

		$dashboardAccessContexts = [];
		foreach ($dashboardRows as $dashboardRow)
		{
			$dashboardId = (int)($dashboardRow['ID'] ?? 0);
			if ($dashboardId <= 0)
			{
				continue;
			}

			$dashboardAccessContexts[$dashboardId] = [
				'requiredPermissionCode' => ($dashboardRow['STATUS'] ?? null) === SupersetDashboardTable::DASHBOARD_STATUS_DRAFT
					? PermissionDictionary::BIC_DASHBOARD_EDIT
					: PermissionDictionary::BIC_DASHBOARD_VIEW,
				'groupIds' => [],
			];
		}

		if (empty($dashboardAccessContexts))
		{
			return [];
		}

		$groupRows = SupersetDashboardGroupBindingTable::getList([
			'select' => ['DASHBOARD_ID', 'GROUP_ID'],
			'filter' => ['@DASHBOARD_ID' => array_keys($dashboardAccessContexts)],
		]);
		while ($groupRow = $groupRows->fetch())
		{
			$dashboardId = (int)($groupRow['DASHBOARD_ID'] ?? 0);
			$groupId = (int)($groupRow['GROUP_ID'] ?? 0);
			if ($dashboardId <= 0 || $groupId <= 0 || !isset($dashboardAccessContexts[$dashboardId]))
			{
				continue;
			}

			$dashboardAccessContexts[$dashboardId]['groupIds'][$groupId] = $groupId;
		}

		foreach ($dashboardAccessContexts as $dashboardId => $dashboardAccessContext)
		{
			$dashboardAccessContexts[$dashboardId]['groupIds'] = array_values($dashboardAccessContext['groupIds']);
		}

		return $dashboardAccessContexts;
	}

	/**
	 * @param array{requiredPermissionCode:int, groupIds:int[]}|null $dashboardAccessContext
	 */
	private function hasDashboardViewAccess(int $userId, ?array $dashboardAccessContext): bool
	{
		$user = UserAccessItem::createFromId($userId);
		if ($user->isAdmin() || !$this->isRightsFeatureEnabled())
		{
			return true;
		}

		if ($dashboardAccessContext === null)
		{
			return false;
		}

		foreach ($dashboardAccessContext['groupIds'] as $groupId)
		{
			$groupPermissionValues = $user->getPermissionMulti(
				PermissionDictionary::getDashboardGroupPermissionId($groupId),
			);
			if (
				!empty($groupPermissionValues)
				&& (
					$groupPermissionValues[0] === PermissionDictionary::VALUE_VARIATION_ALL
					|| in_array($dashboardAccessContext['requiredPermissionCode'], $groupPermissionValues, true)
				)
			)
			{
				return true;
			}
		}

		return false;
	}

	private function isRightsFeatureEnabled(): bool
	{
		if ($this->rightsFeatureEnabled !== null)
		{
			return $this->rightsFeatureEnabled;
		}

		if (!Loader::includeModule('bitrix24'))
		{
			$this->rightsFeatureEnabled = true;

			return $this->rightsFeatureEnabled;
		}

		$this->rightsFeatureEnabled = Feature::isFeatureEnabled('bi_constructor_rights');

		return $this->rightsFeatureEnabled;
	}
}
