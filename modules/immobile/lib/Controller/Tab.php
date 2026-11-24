<?php

namespace Bitrix\ImMobile\Controller;

use Bitrix\Call\Recent;
use Bitrix\Im\Department;
use Bitrix\Im\Promotion;
use Bitrix\Im\V2\Anchor\DI\AnchorContainer;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Folder\FolderProvider;
use Bitrix\Im\V2\Folder\FolderRecentProvider;
use Bitrix\Im\V2\Folder\Query\FolderProviderParams;
use Bitrix\Im\V2\Reading\Counter\UserCountersCollector;
use Bitrix\Im\V2\Reading\View\ViewProvider;
use Bitrix\Im\V2\Recent\PortalRecentProvider;
use Bitrix\Im\V2\Recent\Query\RecentFilter;
use Bitrix\Im\V2\Recent\Query\RecentParams;
use Bitrix\Im\V2\Recent\RecentChannel;
use Bitrix\Im\V2\Recent\RecentCollab;
use Bitrix\Im\V2\Recent\RecentProvider;
use Bitrix\Im\V2\Rest\RestAdapter;
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
	 * Popup blocks the acquaintance fragment is serialized without: of the seven a recent page fills, a
	 * light refresh opens the rows and the profiles behind them. The birthday list alone costs up to a
	 * hundred full profiles of the portal on every refresh.
	 */
	private const PORTAL_FRAGMENT_EXCLUDED_BLOCKS = [
		\Bitrix\Im\V2\Message\MessagePopupItem::class,
		\Bitrix\Im\V2\Chat\ChatPopupItem::class,
		\Bitrix\Im\V2\Recent\BirthdayPopupItem::class,
		\Bitrix\Im\V2\Chat\MessagesAutoDelete\MessagesAutoDeleteConfigs::class,
		\Bitrix\Im\V2\Chat\Copilot\CopilotPopupItem::class,
		\Bitrix\Im\V2\Recent\Config\ChatRecentConfigs::class,
	];

	/**
	 * The answer of a cut-off acquaintance fragment - the three keys of a full page, empty. The client
	 * reads the fragment by them whichever branch produced it, and a shorter envelope would reach it as
	 * a malformed answer rather than as an empty page.
	 */
	private const EMPTY_PORTAL_FRAGMENT = ['users' => [], 'recentItems' => [], 'hasNextPage' => false];

	/**
	 * Popup blocks the short form of a folder page is serialized without: {@see
	 * self::PORTAL_FRAGMENT_EXCLUDED_BLOCKS} minus the recent configs. That block stays, because the
	 * composite tab "All" assembles the membership of its list out of the sections a row belongs to,
	 * and a page without it is read by the client as unusable. The two lists are deliberately not
	 * shared: the envelope of the acquaintance fragment is fixed by a guard of its own, and its rows
	 * belong to no section by construction.
	 */
	private const FOLDER_SHAPE_EXCLUDED_BLOCKS = [
		\Bitrix\Im\V2\Message\MessagePopupItem::class,
		\Bitrix\Im\V2\Chat\ChatPopupItem::class,
		\Bitrix\Im\V2\Recent\BirthdayPopupItem::class,
		\Bitrix\Im\V2\Chat\MessagesAutoDelete\MessagesAutoDeleteConfigs::class,
		\Bitrix\Im\V2\Chat\Copilot\CopilotPopupItem::class,
	];

	/**
	 * The answer of a folder page that never got read - the four keys of a full one, empty. Same reason
	 * as the constant above: every early branch has to answer the envelope the client reads a page by.
	 */
	private const EMPTY_FOLDER_SHAPE = [
		'users' => [],
		'recentItems' => [],
		'recentConfigs' => [],
		'hasNextPage' => false,
	];

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
		// the same request as chatsList right above, with the same gates inside: getOrmParams substitutes
		// Group::getGuestSharedUsersQuery() for a guest. Stripping it would leave a guest without both
		// convergences and without the pagination cursor - silently, until the app is restarted.
		'recentShape',
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
				case (AvailableMethodList::RECENT_SHAPE->value):
					$data[$method] = $this->getRecentShape();
					break;
				case (AvailableMethodList::PORTAL_RECENT_LIST->value):
					$data[$method] = $this->getPortalRecentList();
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
				case (AvailableMethodList::FOLDER_RECENT_SHAPE->value):
					$data[$method] = $this->getFolderRecentShape();
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
				'UNREAD_ONLY' => ($this->options['unreadOnly'] ?? '') === 'Y' ? 'Y' : 'N',
				'PARENT_ID' => 0,
				'OFFSET' => self::OFFSET,
				'LIMIT' => self::LIMIT,
			]
		);

		return $recentList ?: [];
	}

	/**
	 * The short form of the first page of the chat list: the same page as getChatsList(), stripped of the
	 * hydration the light refresh never reads. Both convergences of the client and the pagination cursor
	 * take only id, pinned and dateLastActivity out of a row, and SHAPE aligns the membership of the short
	 * output with the full one - so a row missing here means the row left the list, not that the short
	 * form dropped it.
	 *
	 * SHAPE is passed as a literal and not as \Bitrix\Im\Recent::SHAPE_OPTION on purpose: im and immobile
	 * ship apart, and an older im must ignore an unknown option instead of failing on a missing constant.
	 *
	 * The "Unread" filter is read here as getChatsList() reads it: under the filter the tab shows the
	 * unread rows, and the fragment that replaces its identifier set answers that same page. That it
	 * narrows while its twin getPortalRecentList() empties itself is not a discrepancy - the partition
	 * has no unread page at all, while a short form of zero rows would reach the client as "the tab has
	 * no chats left" and wipe the list.
	 */
	protected function getRecentShape(): array
	{
		$recentList = \Bitrix\Im\Recent::getList(
			null,
			[
				'JSON' => 'Y',
				'SHORT_INFO' => 'Y',
				'SHAPE' => 'Y',
				'WITH_COUNTERS' => 'N',
				'SKIP_OPENLINES' => 'Y',
				'GET_ORIGINAL_TEXT' => 'N',
				'UNREAD_ONLY' => ($this->options['unreadOnly'] ?? '') === 'Y' ? 'Y' : 'N',
				'PARENT_ID' => 0,
				'OFFSET' => self::OFFSET,
				'LIMIT' => self::LIMIT,
			]
		);

		return $recentList ?: [];
	}

	/**
	 * The page of the acquaintance partition of the portal (recent rows with USER_ID = 0): one row per
	 * employee the reader has no conversation with. The hydrated list mixes them into its own rows, so
	 * a light refresh - which replaces the id set of the tab by what it was answered - needs them from
	 * a source of their own, or the tab would lose every acquaintance it had.
	 *
	 * Both cut-offs are made here and before the query, because {@see PortalRecentProvider::getList()}
	 * does not check the reader at all: COMPANION.ACTIVE and COMPANION.IS_INTRANET_USER pick the person
	 * being shown, while $readerId only subtracts the reader himself and everyone he already has a row
	 * for. Without the gate in the wrapper the invited people of the portal reach a user outside the
	 * intranet.
	 */
	protected function getPortalRecentList(): array
	{
		// The partition holds people the reader has no conversation with, so it carries nothing unread
		// and under the "Unread" filter has no page at all - without the cut the invited people end up in
		// the filtered list. The same condition holds the other consumer of the provider
		// (\Bitrix\Im\V2\Folder\FolderRecentProvider), and the whitelist of the client lives in a module
		// that ships apart, so the rule belongs to the data too.
		if (($this->options['unreadOnly'] ?? '') === 'Y')
		{
			return self::EMPTY_PORTAL_FRAGMENT;
		}

		$userId = (int)$this->currentUser->getId();

		if (!Loader::includeModule('im') || !\Bitrix\Im\V2\Recent\Recent::areColleaguesShownTo($userId))
		{
			return self::EMPTY_PORTAL_FRAGMENT;
		}

		$recent = ServiceLocator::getInstance()
			->get(PortalRecentProvider::class)
			->getList($userId, null, self::LIMIT)
		;

		// The context is set explicitly: the meta is resolved for the reader, and a collection built by
		// the provider carries no context of its own.
		$recent->setContextUser($userId);
		$recent->applyAcquaintanceMeta();

		// Serialized here and not through toRestFormatWithPaginationData(): that method is shared with
		// folderRecentList, where every block of the popup data is read. The page flag repeats its rule -
		// a full page means a page may follow - and Recent is not tariff-filterable, so nothing narrows
		// the rows on their way out and the count of the read is the count of the answer.
		$fragment = (new RestAdapter($recent))->toRestFormat([
			'POPUP_DATA_EXCLUDE' => self::PORTAL_FRAGMENT_EXCLUDED_BLOCKS,
			'USER_SHORT_FORMAT' => true,
			'WITHOUT_ONLINE' => true,
		]);
		$fragment['hasNextPage'] = $recent->count() >= self::LIMIT;

		return $fragment;
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

	/**
	 * The short form of a folder page: the same page as {@see self::getFolderRecentList()} - the same
	 * folder, the same access check, the same provider - stripped of the hydration a light refresh
	 * never reads. Both convergences of the client and the pagination cursor take only the row out of
	 * the answer, so a row missing here means the row left the folder and not that the short form
	 * dropped it.
	 *
	 * Serialized here and not through toRestFormatWithPaginationData() for the same reason the
	 * acquaintance fragment is: that method is shared with the full folder page, where every block of
	 * the popup data is read. The page flag repeats its rule - a full page means a page may follow -
	 * and Recent is not tariff-filterable, so nothing narrows the rows on their way out and the count
	 * of the read is the count of the answer.
	 *
	 * Every early branch - no reader, no folder id, a folder that does not exist or is not the
	 * reader's - answers the envelope of a full page, empty and without an error, the way the full path
	 * does. The refusal of the provider itself travels outwards as on the full path: the error goes
	 * into the response next to the empty envelope.
	 */
	private function getFolderRecentShape(): array
	{
		$userId = (int)$this->currentUser->getId();
		$folderId = (int)($this->options['folderId'] ?? 0);
		if ($userId <= 0 || $folderId <= 0)
		{
			return self::EMPTY_FOLDER_SHAPE;
		}

		$folder = ServiceLocator::getInstance()
			->get(FolderProvider::class)
			->getById($folderId)
		;
		if ($folder === null || !$folder->checkAccess($userId)->isSuccess())
		{
			return self::EMPTY_FOLDER_SHAPE;
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

			return self::EMPTY_FOLDER_SHAPE;
		}

		$recent = $result->getResult();
		$this->fillSelfRelationsOfPage($recent, $userId);

		$fragment = (new RestAdapter($recent))->toRestFormat([
			'POPUP_DATA_EXCLUDE' => self::FOLDER_SHAPE_EXCLUDED_BLOCKS,
			'USER_SHORT_FORMAT' => true,
			'WITHOUT_ONLINE' => true,
		]);
		$fragment['hasNextPage'] = $recent->count() >= self::LIMIT;

		return $this->fillMessageViewedByOthers($fragment);
	}

	/**
	 * The one fact about the messages of a folder page the short form keeps from the messages block it
	 * drops: whether the others have read them. It is read off the same NOTIFY_READ the full page
	 * derives `viewedByOthers` from, in one batch for the whole page.
	 *
	 * A row gets `messageViewedByOthers` for its shown message and, only when it is redirected to
	 * another one, `ownMessageViewedByOthers` for its own: with equal ids the client applies the first
	 * fact to both by the id. A row without a chat, without a message or with a message that is not
	 * found gets no flag, and the client leaves its status alone.
	 */
	private function fillMessageViewedByOthers(array $fragment): array
	{
		$messageIds = [];
		foreach ($fragment['recentItems'] ?? [] as $row)
		{
			if ((int)$row['chatId'] <= 0)
			{
				continue;
			}

			foreach ([(int)$row['messageId'], (int)($row['ownMessageId'] ?? 0)] as $messageId)
			{
				if ($messageId > 0)
				{
					$messageIds[$messageId] = $messageId;
				}
			}
		}

		if ($messageIds === [])
		{
			return $fragment;
		}

		$viewedByOthers = ServiceLocator::getInstance()
			->get(ViewProvider::class)
			->getViewedByOthersStatuses(array_values($messageIds))
		;

		foreach ($fragment['recentItems'] as $index => $row)
		{
			if ((int)$row['chatId'] <= 0)
			{
				continue;
			}

			$messageId = (int)$row['messageId'];
			if (isset($viewedByOthers[$messageId]))
			{
				$fragment['recentItems'][$index]['messageViewedByOthers'] = $viewedByOthers[$messageId];
			}

			$ownMessageId = (int)($row['ownMessageId'] ?? 0);
			if ($ownMessageId !== $messageId && isset($viewedByOthers[$ownMessageId]))
			{
				$fragment['recentItems'][$index]['ownMessageViewedByOthers'] = $viewedByOthers[$ownMessageId];
			}
		}

		return $fragment;
	}

	/**
	 * The relations of the reader to the chats of a folder page, read in one batch before serialization.
	 * On the full page the chats block does it on its way out, and the sections of every row are read
	 * after it. The short form drops that block, so without this step every row of the page would read
	 * its own relation while its sections are resolved - a query per chat of the page.
	 *
	 * The chats are the same instances the popup data collects, so the preloaded relation is the one
	 * the sections are read from.
	 */
	private function fillSelfRelationsOfPage(\Bitrix\Im\V2\Recent\Recent $recent, int $userId): void
	{
		$chats = [];
		foreach ($recent as $item)
		{
			foreach ([$item->getChatId(), $item->getSourceChatId()] as $chatId)
			{
				if ($chatId > 0 && !isset($chats[$chatId]))
				{
					$chats[$chatId] = \Bitrix\Im\V2\Chat::getInstance($chatId);
				}
			}
		}

		\Bitrix\Im\V2\Chat::fillSelfRelations(array_values($chats), $userId);
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
