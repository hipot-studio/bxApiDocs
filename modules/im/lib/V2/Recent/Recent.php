<?php

namespace Bitrix\Im\V2\Recent;

use Bitrix\Im\Model\MessageParamTable;
use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserCollection;
use Bitrix\Im\V2\Entity\User\UserPopupItem;
use Bitrix\Im\V2\Message\CounterService;
use Bitrix\Im\V2\Message\MessagePopupItem;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Recent\Query\RecentParams;
use Bitrix\Im\V2\Registry;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Rest\PopupDataAggregatable;
use Bitrix\Im\V2\Rest\PopupDataItem;
use Bitrix\Im\V2\Settings\UserConfiguration;
use Bitrix\Intranet\Internals\InvitationTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/**
 * @extends Registry<RecentItem>
 */
class Recent extends Registry implements PopupDataAggregatable, PopupDataItem
{
	use ContextCustomer;

	// DTO-03. Both clients already read this option; the two channels that raise it are the portal
	// partition ({@see PortalRecentProvider}) and the reader's own "joined the portal" row below.
	public const OPTION_DEFAULT_USER_RECORD = 'defaultUserRecord';

	// The system message a person gets on joining the portal, as written by
	// im/lib/integration/intranet/user.php:322. Legacy reads the same value: im/lib/recent.php:1314.
	public const MESSAGE_CODE_USER_JOIN = 'USER_JOIN';

	// Shared by every fetch that hydrates through RecentItem::initByArray: the reader's own rows
	// and the portal partition must select the same set, or the two pages stop being mergeable.
	public const SELECT_FIELDS = [
		'ITEM_TYPE',
		'ITEM_ID',
		'ITEM_CID',
		'ITEM_MID',
		'UNREAD',
		'PINNED',
		'DATE_LAST_ACTIVITY',
		'DATE_UPDATE',
		'MARKED_ID',
		'RELATION.LAST_ID',
		'PREVIEW_SOURCE_CID',
		'PREVIEW_SOURCE_MID',
	];

	// Set by the folder tail assembly when the portal meta-stream gate (ALG-02) is open: rows without a
	// chat can then be on the page, or the client synthesizes one itself, and both need their sections.
	private bool $portalStreamAllowed = false;

	private bool $acquaintanceMetaApplied = false;

	public function markPortalStreamAllowed(): static
	{
		$this->portalStreamAllowed = true;

		return $this;
	}

	public function getPopupData(array $excludedList = []): PopupData
	{
		$messageIds = [];
		$chats = [];
		$chatIds = [];
		$userIds = [];

		foreach ($this as $item)
		{
			$messageIds[] = $item->getMessageId();

			// A row without a chat must not drag a NullChat into the response: the client would read
			// {"id":null} instead of building the stub from the person's profile.
			$chatId = $item->getChatId();
			if ($chatId > 0)
			{
				$chats[] = Chat::getInstance($chatId);
				$chatIds[] = $chatId;
			}

			$ownMessageId = $item->getOwnMessageId();
			if ($ownMessageId > 0 && $ownMessageId !== $item->getMessageId())
			{
				$messageIds[] = $ownMessageId;
			}

			$sourceChatId = $item->getSourceChatId();
			if ($sourceChatId > 0 && $sourceChatId !== $chatId)
			{
				$chats[] = Chat::getInstance($sourceChatId);
				$chatIds[] = $sourceChatId;
			}

			if ($item->getType() === RecentType::User)
			{
				$userIds[] = $item->getId();
			}
		}

		$messages = $this->getMessagesForPopupData($messageIds);

		return new PopupData([
			MessagePopupItem::getInstanceMessages($messages, true),
			new Chat\ChatPopupItem($chats),
			new BirthdayPopupItem(),
			new Chat\MessagesAutoDelete\MessagesAutoDeleteConfigs($chatIds),
			new UserPopupItem($userIds),
			Chat\Copilot\CopilotPopupItem::getInstanceByChatIdsAndMessages($messages, $chatIds),
			new Config\ChatRecentConfigs($chats, $this->portalStreamAllowed),
		], $excludedList);
	}

	/**
	 * DTO-03 channel M2 and DTO-04, both resolved for the whole page at once: the reader's own row whose
	 * last message is the system "joined the portal" one gets the acquaintance marker, and the invitation
	 * flag is filled from the journal - what the old list does in the same two places
	 * (im/lib/recent.php:1229-1238 for the row, :1123-1142 for the journal).
	 *
	 * Called by the page that needs the meta ({@see \Bitrix\Im\V2\Folder\FolderRecentProvider}) and not
	 * by the serializer: every other recent endpoint would pay for the two queries without reading them.
	 * The other side of the same switches - the "joined the portal" row leaving the page when colleagues
	 * are hidden - is a condition of the query ({@see Query\JoinedPortalRowFilter}), so no caller has to
	 * think about the composition here.
	 *
	 * Only rows of a private dialog are touched, as in legacy. Channel and collab sections carry none
	 * by construction of their section condition, so they never reach a query.
	 */
	public function applyAcquaintanceMeta(): void
	{
		if ($this->acquaintanceMetaApplied)
		{
			return;
		}

		$this->acquaintanceMetaApplied = true;

		$readerId = $this->getContext()->getUserId();
		if ($readerId <= 0)
		{
			return;
		}

		$companionItems = $this->getCompanionItems();
		if ($companionItems === [])
		{
			return;
		}

		foreach ($this->findJoinedPortalRows($companionItems) as $key)
		{
			$item = $companionItems[$key];
			$item->setOptions($item->getOptions() + [self::OPTION_DEFAULT_USER_RECORD => true]);
		}

		$this->fillInvited($companionItems);
	}

	/**
	 * The part of ALG-02 that does not depend on the page: the portal-wide switch, the reader being an
	 * employee and the personal switch. Every reading of the gate - the flags on the rows, the rows the
	 * folder tail asks for, the condition that hides the "joined the portal" row - goes through here, so
	 * they cannot drift apart. Reads go cheapest first: a closed portal switch must not cost a settings
	 * read. What the old list calls $withoutCommonUsers (im/lib/recent.php:1033).
	 */
	public static function areColleaguesShownTo(int $readerId): bool
	{
		if (Option::get('im', 'view_common_users', 'Y') === 'N')
		{
			return false;
		}

		if (!Loader::includeModule('intranet') || !\Bitrix\Intranet\Util::isIntranetUser($readerId))
		{
			return false;
		}

		return (new UserConfiguration($readerId))->getGeneralSettings()['viewCommonUsers'] === true;
	}

	/**
	 * @return array<int, RecentItem> collection key => row of a private dialog
	 */
	private function getCompanionItems(): array
	{
		$items = [];

		foreach ($this as $key => $item)
		{
			if ($item->getType() === RecentType::User && $item->getId() > 0)
			{
				$items[$key] = $item;
			}
		}

		return $items;
	}

	/**
	 * One query per page over the params of the rows' last messages, read for the marker only. The
	 * mirror case - the same rows leaving the page when colleagues are hidden - is decided by the query
	 * instead ({@see Query\JoinedPortalRowFilter}), where the page can still be filled up to the limit.
	 *
	 * @param array<int, RecentItem> $companionItems
	 * @return int[] collection keys of the rows whose own last message is "joined the portal"
	 */
	private function findJoinedPortalRows(array $companionItems): array
	{
		$keysByMessageId = [];
		foreach ($companionItems as $key => $item)
		{
			// The row's own last message, the way the legacy join reads ITEM_MID: a preview-source
			// overlay may have moved messageId elsewhere.
			$messageId = $item->getOwnMessageId();
			if ($messageId > 0)
			{
				$keysByMessageId[$messageId][] = $key;
			}
		}

		if ($keysByMessageId === [])
		{
			return [];
		}

		$rows = MessageParamTable::query()
			->setSelect(['MESSAGE_ID'])
			->whereIn('MESSAGE_ID', array_keys($keysByMessageId))
			->where('PARAM_NAME', Params::CODE)
			->where('PARAM_VALUE', self::MESSAGE_CODE_USER_JOIN)
			->fetchAll()
		;

		$keys = [];
		foreach ($rows as $row)
		{
			array_push($keys, ...$keysByMessageId[(int)$row['MESSAGE_ID']]);
		}

		return $keys;
	}

	/**
	 * DTO-04. A person counts as invited while they have never been active and the journal knows who
	 * invited them; the invitation can be sent again once they have an email. Both the acquaintance
	 * rows and the reader's own rows get the flag, as in legacy where the journal is joined for the
	 * whole list (AC-B8-11).
	 *
	 * @param array<int, RecentItem> $companionItems
	 */
	private function fillInvited(array $companionItems): void
	{
		$companionIds = [];
		foreach ($companionItems as $item)
		{
			$companionIds[$item->getId()] = $item->getId();
		}

		$originators = $this->getInvitationOriginators($companionIds);
		if ($originators === [])
		{
			return;
		}

		// Profiles carry the email; the activity date needs the bulk online-data fill, one query for
		// the whole set. Both are reused by the users[] hydration below, so neither is read twice.
		$users = UserCollection::initByUserIds(array_keys($originators));
		$users->fillOnlineData();

		foreach ($companionItems as $item)
		{
			$originatorId = $originators[$item->getId()] ?? 0;
			if ($originatorId <= 0)
			{
				continue;
			}

			$user = $users->getById($item->getId());
			if (!$user instanceof User || $user->getLastActivityDate() !== null)
			{
				continue;
			}

			$item->setInvited([
				'originatorId' => $originatorId,
				'canResend' => $user->getEmail() !== '',
			]);
		}
	}

	/**
	 * @param int[] $companionIds
	 * @return array<int, int> invited person => who invited them, positive originators only, as legacy
	 *   reads the joined value for truth (im/lib/recent.php:1240-1247)
	 */
	private function getInvitationOriginators(array $companionIds): array
	{
		if (!Loader::includeModule('intranet'))
		{
			return [];
		}

		$rows = InvitationTable::query()
			->setSelect(['USER_ID', 'ORIGINATOR_ID'])
			->whereIn('USER_ID', $companionIds)
			->fetchAll()
		;

		$originators = [];
		foreach ($rows as $row)
		{
			$originatorId = (int)$row['ORIGINATOR_ID'];
			if ($originatorId > 0)
			{
				$originators[(int)$row['USER_ID']] = $originatorId;
			}
		}

		return $originators;
	}

	final public static function getRestEntityName(): string
	{
		return 'recentItems';
	}

	public function toRestFormat(array $option = []): array
	{
		$rest = [];

		foreach ($this as $item)
		{
			$rest[] = $item->toRestFormat();
		}

		return $rest;
	}

	public function merge(PopupDataItem $item): PopupDataItem
	{
		return $this;
	}

	public static function getOrder(int $userId): array
	{
		// TODO: Pin sort by cost (`pinnedChatSort='byCost'`, the default) is temporarily
		//   disabled — always sort by PINNED + DATE_LAST_ACTIVITY regardless of user
		//   preference. Reason: `PIN_SORT` is never reset on unpin. `RecentUpdater::setPinned`
		//   only flips `PINNED` to 'N' and updates `DATE_UPDATE`, leaving the stale
		//   `PIN_SORT` value on the row. With `ORDER BY PIN_SORT ASC` MySQL puts NULLs
		//   first, so any chat that was once pinned ends up after every never-pinned
		//   chat in the unpinned section, silently dropping out of the first page.
		return [
			'PINNED' => 'DESC',
			'DATE_LAST_ACTIVITY' => 'DESC',
		];

		$userSettings = (new UserConfiguration($userId))->getGeneralSettings();

		if (isset($userSettings['pinnedChatSort']) && $userSettings['pinnedChatSort'] === 'byCost')
		{
			return [
				'PINNED' => 'DESC',
				'PIN_SORT' => 'ASC',
				'DATE_LAST_ACTIVITY' => 'DESC',
			];
		}

		return [
			'PINNED' => 'DESC',
			'DATE_LAST_ACTIVITY' => 'DESC',
		];
	}

	protected function getMessagesForPopupData(array $messageIds): MessageCollection
	{
		return
			(new MessageCollection($messageIds))
				->fillParams()
		;
	}

	public static function getRecentEntities(RecentParams $recentParams): array
	{
		if (isset($recentParams->filter) && !$recentParams->filter->isPossible())
		{
			return [];
		}

		$query = RecentTable::query();

		$query->setSelect(self::SELECT_FIELDS);

		$recentParams->apply($query);

		return $query->fetchAll();
	}

	public static function initByArray(array $recentArray): static
	{
		$recent = new static();

		foreach ($recentArray as $entity)
		{
			$recent[] = RecentItem::initByArray($entity);
		}

		return $recent;
	}

	/**
	 * Overlays each collab row's preview-source pointer onto $messageId (and source chat id), so the row
	 * shows the freshest child-chat message instead of its own last message. No-op when the feature is off.
	 *
	 * $userId is unused here; kept for signature compatibility with the RecentSync override.
	 */
	public function enrichCollabPreviewSources(int $userId): void
	{
		unset($userId);

		if (!Features::isCollabPreviewSourceEnabled())
		{
			return;
		}

		foreach ($this as $item)
		{
			$sourceMessageId = $item->getPreviewSourceMid();
			if ($sourceMessageId <= 0)
			{
				continue;
			}

			$item->setMessageId($sourceMessageId);

			$sourceChatId = $item->getPreviewSourceCid();
			if ($sourceChatId > 0)
			{
				$item->setSourceChatId($sourceChatId);
			}
		}
	}
}
