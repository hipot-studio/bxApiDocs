<?php

namespace Bitrix\ImMobile\Controller;

use Bitrix\Call\Recent;
use Bitrix\Im\Department;
use Bitrix\Im\Promotion;
use Bitrix\Im\V2\Anchor\DI\AnchorContainer;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Folder\FolderProvider;
use Bitrix\Im\V2\Folder\FolderRecentProvider;
use Bitrix\Im\V2\Folder\Query\FolderProviderParams;
use Bitrix\Im\V2\Reading\Counter\UserCountersCollector;
use Bitrix\Im\V2\Recent\Query\RecentFilter;
use Bitrix\Im\V2\Recent\Query\RecentParams;
use Bitrix\Im\V2\Recent\RecentChannel;
use Bitrix\Im\V2\Recent\RecentCollab;
use Bitrix\Im\V2\Recent\RecentProvider;
use Bitrix\Im\V2\TariffLimit\Limit;
use Bitrix\ImMobile\NavigationTab\Tab\AvailableMethodList;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use CIMMessenger;
use CUserCounter;

abstract class Tab extends BaseController
{
	protected const PROMO_TYPE ='mobile';
	protected const OFFSET = 0;
	protected const LIMIT = 50;

	/**
	 * Methods a guest user is allowed to request via loadAction.
	 *
	 * Whitelist principle:
	 * - Own-user / public-flag data (counters of self, active calls of self, mobile promotions,
	 *   tariff restrictions, desktop status of self) — allowed.
	 * - Workspace-wide listings (channels, collabs, openlines, tasks, copilot, folders, nested,
	 *   department colleagues) — stripped: guest has no legitimate access to portal-wide entities,
	 *   and per-method handlers don't all gate on user type internally.
	 */
	private const GUEST_ALLOWED_METHODS = [
		'recentList',
		'userData',
		'imCounters',
		'mobileRevision',
		'serverTime',
		'anchors',
		'chatsList',
		'tariffRestriction',
		'portalCounters',
		'activeCalls',
		'promotion',
		'desktopStatus',
	];

	protected array $options;
	protected CurrentUser $currentUser;

	public function getPrimaryAutoWiredParameter()
	{
		return new ExactParameter(
			\Bitrix\Im\V2\Chat::class,
			'chat',
			function ($className, $id) {
				return \Bitrix\Im\V2\Chat::getInstance((int)$id);
			}
		);
	}

	public function loadAction(array $methodList, CurrentUser $currentUser, $options = []): array
	{
		$this->options = $options;
		$this->currentUser = $currentUser;

		$methodList = $this->filterMethodListByUserType($methodList);

		$data = [];
		foreach ($methodList as $method)
		{
			switch ($method)
			{
				case (AvailableMethodList::RECENT_LIST->value):
					$data[$method] = $this->getRecentList();
					break;
				case (AvailableMethodList::USER_DATA->value):
					$data[$method] = $this->getUserData();
					break;
				case (AvailableMethodList::PORTAL_COUNTERS->value):
					$data[$method] = $this->getPortalCounters();
					break;
				case (AvailableMethodList::IM_COUNTERS->value):
					$data[$method] = $this->getImCounters();
					break;
				case (AvailableMethodList::ANCHORS->value):
					$data[$method] = $this->getAnchors();
					break;
				case (AvailableMethodList::MOBILE_REVISION->value):
					$data[$method] = $this->getRevision();
					break;
				case (AvailableMethodList::SERVER_TIME->value):
					$data[$method] = $this->getServerTime();
					break;
				case (AvailableMethodList::DESKTOP_STATUS->value):
					$data[$method] = $this->getDesktopStatus();
					break;
				case (AvailableMethodList::PROMOTION->value):
					$data[$method] = Promotion::getActive(self::PROMO_TYPE);
					break;
				case (AvailableMethodList::DEPARTMENT_COLLEAGUES->value):
					$data[$method] = $this->getDepartmentColleagues();
					break;
				case (AvailableMethodList::TARIFF_RESTRICTION->value):
					$data[$method] = $this->getTariffRestriction();
					break;
				case (AvailableMethodList::CHATS_LIST->value):
					$data[$method] = $this->getChatsList();
					break;
				case (AvailableMethodList::COPILOT_LIST->value):
					$data[$method] = $this->getCopilotList();
					break;
				case (AvailableMethodList::CHANNEL_LIST->value):
					$data[$method] = $this->getChannelList();
					break;
				case (AvailableMethodList::COLLAB_LIST->value):
					$data[$method] = $this->getCollabList();
					break;
				case (AvailableMethodList::OPEN_LINES_LIST->value):
					$data[$method] = $this->getOpenlinesList();
					break;
				case (AvailableMethodList::TASK_LIST->value):
					$data[$method] = $this->getTaskList();
					break;
				case (AvailableMethodList::NESTED_LIST->value):
					$data[$method] = $this->getNestedList();
					break;
				case (AvailableMethodList::FOLDER_LIST->value):
					$data[$method] = $this->getFolderList();
					break;
				case (AvailableMethodList::FOLDER_RECENT_LIST->value):
					$data[$method] = $this->getFolderRecentList();
					break;
				case (AvailableMethodList::ACTIVE_CALLS->value):
					$data[$method] = [];
					if (Loader::includeModule('call'))
					{
						$data[$method] = Recent::getActiveCalls();
					}

					break;
			}
		}

		return $data;
	}

	protected function getRevision(): int
	{
		return \Bitrix\Im\Revision::getMobile();
	}

	protected function getServerTime(): string
	{
		return date('c');
	}

	protected function getPortalCounters(): array
	{
		$time = microtime(true);
		$siteId = $this->options['siteId'] ?? SITE_ID;

		$counters = [$siteId => CUserCounter::GetValues($this->currentUser->getId(), $siteId)];
		$counters = CUserCounter::getGroupedCounters($counters);

		return [
			'result' => $counters,
			'time' => $time,
		];
	}

	protected function getDesktopStatus(): array
	{
		return [
			'isOnline' => CIMMessenger::CheckDesktopStatusOnline(),
			'version' => CIMMessenger::GetDesktopVersion(),
		];
	}

	protected function getUserData(): array
	{
		$userData = \Bitrix\Im\User::getInstance($this->currentUser->getId())->getArray(['JSON' => 'Y']);

		$userData['desktop_last_date'] = \CIMMessenger::GetDesktopStatusOnline($this->currentUser->getId());
		$userData['desktop_last_date'] = $userData['desktop_last_date']
			? date('c', $userData['desktop_last_date'])
			: false
		;

		return $userData;
	}

	protected function getDepartmentColleagues(): array
	{
		$user = User::getInstance($this->currentUser->getId());

		if (!$user->isExist() || $user->isExtranet() || $user->isBot())
		{
			return [];
		}

		//Todo: delete this in next updates
		if (!method_exists(Department::class, 'getColleaguesSimple'))
		{
			$params = [
				'OFFSET' => self::OFFSET,
				'LIMIT' => self::LIMIT,
			];
			$colleagues = Department::getColleagues(
				null, ['JSON' => 'Y', 'USER_DATA' => 'Y', 'LIST' => $params]
			);

			return $colleagues['result'] ?? [];
		}

		return Department::getColleaguesSimple($user, self::LIMIT);
	}

	protected function getImCounters(): array
	{
		$countersCollection = ServiceLocator::getInstance()
			->get(UserCountersCollector::class)
			->get((int)$this->getCurrentUser()?->getId())
		;

		return [
			'messengerCounters' => $countersCollection->toRestFormat(),
			'notifyCounters' => $countersCollection->getNotificationCounter(),
		];
	}

	protected function getAnchors(): array
	{
		$anchorProvider = AnchorContainer::getInstance()
			->getAnchorProvider()
			->setContextUser($this->getCurrentUser()?->getId());

		return $anchorProvider->getUserAnchors();
	}

	protected function getTariffRestriction(): array
	{
		return Limit::getInstance()->getRestrictions();
	}

	protected function getChatsList(): array
	{
		$recentList = \Bitrix\Im\Recent::getList(
			null,
			[
				'JSON' => 'Y',
				'SKIP_OPENLINES' => 'Y',
				'GET_ORIGINAL_TEXT' => 'N',
				'WITH_COUNTERS' => $this->options['withCounters'] ?? 'N',
				'UNREAD_ONLY' => $this->options['unreadOnly'] === 'Y' ? 'Y' : 'N',
				'PARENT_ID' => 0,
				'OFFSET' => self::OFFSET,
				'LIMIT' => self::LIMIT,
			]
		);

		return $recentList ?: [];
	}

	protected function getCopilotList(): array
	{
		$recentList = \Bitrix\Im\Recent::getList(
			null,
			[
				'JSON' => 'Y',
				'SKIP_OPENLINES' => 'Y',
				'GET_ORIGINAL_TEXT' => 'N',
				'WITH_COUNTERS' => $this->options['withCounters'] ?? 'N',
				'OFFSET' => self::OFFSET,
				'LIMIT' => self::LIMIT,
				'PARENT_ID' => 0,
				'ONLY_COPILOT' => 'Y',
			]
		);

		return $recentList ?: [];
	}

	protected function getChannelList(): array
	{
		$recentList = RecentChannel::getOpenChannels(self::LIMIT);

		return $this->toRestFormatWithPaginationData(
			[$recentList],
			self::LIMIT,
			$recentList->count()
		);
	}

	protected function getCollabList(): array
	{
		$recentList = RecentCollab::getCollabs(self::LIMIT);

		return $this->toRestFormatWithPaginationData(
			[$recentList],
			self::LIMIT,
			$recentList->count()
		);
	}

	protected function getTaskList(): array
	{
		// TODO: task-tab remove class_exists after merge feature
		if (!\Bitrix\ImMobile\Settings::isTasksRecentListAvailable() || !class_exists('\Bitrix\Im\V2\Recent\RecentExternalChat'))
		{
			return $this->toRestFormatWithPaginationData(
				[],
				self::LIMIT,
				0
			);
		}

		$filter = [];
		if (($this->options['unreadOnly'] ?? '') === 'Y')
		{
			$filter['unread'] = 'Y';
		}

		$recentList = \Bitrix\Im\V2\Recent\RecentExternalChat::getExternalChats('tasksTask', self::LIMIT, $filter);

		return $this->toRestFormatWithPaginationData(
			[$recentList],
			self::LIMIT,
			$recentList->count()
		);
	}

	protected function getOpenlinesList(): array
	{
		if (!Loader::includeModule('imopenlines'))
		{
			return $this->toRestFormatWithPaginationData(
				[],
				self::LIMIT,
				0
			);
		}
		$recentList = \Bitrix\ImOpenLines\V2\Recent\Recent::getOpenLines(
			$this->currentUser,
			new \Bitrix\ImOpenLines\V2\Recent\Cursor(),
			self::LIMIT
		);

		return $this->toRestFormatWithPaginationData(
			[$recentList],
			self::LIMIT,
			$recentList->count()
		);
	}

	protected function getNestedList(): array
	{
		$params = $this->buildNestedListParams();
		if ($params === null)
		{
			return $this->toRestFormatWithPaginationData([], self::LIMIT, 0);
		}

		$recentProvider = ServiceLocator::getInstance()->get(RecentProvider::class);

		return $recentProvider->getSection($params)->toRestFormat();
	}

	private function buildNestedListParams(): ?RecentParams
	{
		$filter = $this->options['filter'] ?? [];
		$limit  = (int)($this->options['limit'] ?? self::LIMIT);

		if (isset($filter['lastMessageDate']))
		{
			$filter['lastMessageDate'] = $this->getDateOrSetError($filter['lastMessageDate']);
			if ($filter['lastMessageDate'] === null)
			{
				return null;
			}
		}

		$allowedFilters = ['lastMessageDate', 'recentSection', 'parentId', 'unread'];
		$filter = array_intersect_key($filter, array_flip($allowedFilters));
		$filter['userId'] = (int)$this->currentUser->getId();

		return new RecentParams(
			filter: RecentFilter::fromArray($filter),
			limit: $limit,
			order: \Bitrix\Im\V2\Recent\Recent::getOrder((int)$this->currentUser->getId()),
		);
	}

	private function getFolderList(): array
	{
		if (!Features::get()->isChatFoldersAvailable)
		{
			return [];
		}

		$userId = (int)$this->currentUser->getId();
		if ($userId <= 0)
		{
			return [];
		}

		return ServiceLocator::getInstance()
			->get(\Bitrix\Im\V2\Folder\FolderProvider::class)
			->getByUser($userId)
			->onlyAvailable($userId)
			->toRestFormat()
		;
	}

	private function getFolderRecentList(): array
	{
		if (!Features::get()->isChatFoldersAvailable)
		{
			return $this->toRestFormatWithPaginationData([], self::LIMIT, 0);
		}

		$userId = (int)$this->currentUser->getId();
		$folderId = (int)($this->options['folderId'] ?? 0);
		if ($userId <= 0 || $folderId <= 0)
		{
			return $this->toRestFormatWithPaginationData([], self::LIMIT, 0);
		}

		$folder = ServiceLocator::getInstance()
			->get(FolderProvider::class)
			->getById($folderId)
		;
		if ($folder === null || !$folder->checkAccess($userId)->isSuccess())
		{
			return $this->toRestFormatWithPaginationData([], self::LIMIT, 0);
		}

		$filter = $this->options['filter'] ?? [];
		$params = FolderProviderParams::fromArray($filter, self::LIMIT);

		$result = ServiceLocator::getInstance()
			->get(FolderRecentProvider::class)
			->getTail($folder, $params, self::LIMIT)
		;
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return $this->toRestFormatWithPaginationData([], self::LIMIT, 0);
		}

		$recent = $result->getResult();

		return $this->toRestFormatWithPaginationData([$recent], self::LIMIT, $recent->count());
	}

	abstract protected function getRecentList(): array;

	private function filterMethodListByUserType(array $methodList): array
	{
		$user = User::getInstance((int)$this->currentUser->getId());
		if (!$user->isGuest())
		{
			return $methodList;
		}

		return array_values(array_intersect($methodList, self::GUEST_ALLOWED_METHODS));
	}
}
