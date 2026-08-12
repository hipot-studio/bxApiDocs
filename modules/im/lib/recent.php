<?php

namespace Bitrix\Im;

use Bitrix\Im\Model\MessageParamTable;
use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat\Background\Background;
use Bitrix\Im\V2\Chat\Copilot\CopilotPopupItem;
use Bitrix\Im\V2\Chat\Copilot\CopilotTitle;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Chat\EntityLink;
use Bitrix\Im\V2\Chat\Param\Params;
use Bitrix\Im\V2\Chat\MessagesAutoDelete\MessagesAutoDeleteConfigs;
use Bitrix\Im\V2\Chat\PrivateChat;
use Bitrix\Im\V2\Chat\TextField\TextFieldEnabled;
use Bitrix\Im\V2\Integration\Socialnetwork\Collab\Collab;
use Bitrix\Im\V2\Entity\User\NullUser;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Im\V2\Permission;
use Bitrix\Im\V2\Integration\AI\RoleManager;
use Bitrix\Im\V2\Integration\Socialnetwork\Group;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Entity\File\FileCollection;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Im\V2\Message\Param;
use Bitrix\Im\V2\Pull\Event\ChatPin;
use Bitrix\Im\V2\Pull\Event\RecentUpdate;
use Bitrix\Im\V2\Reading\Counter\CountersProvider;
use Bitrix\Im\V2\Reading\Counter\Entity\ChatsCounterMap;
use Bitrix\Im\V2\Reading\RecentReader;
use Bitrix\Im\V2\Recent\Config\RecentConfigManager;
use Bitrix\Im\V2\Recent\PreviewSource\CollabPreviewSourcePiggyback;
use Bitrix\Im\V2\Relation;
use Bitrix\Im\V2\RelationCollection;
use Bitrix\Im\V2\Settings\UserConfiguration;
use Bitrix\Im\V2\Sync;
use Bitrix\Imbot\Bot\CopilotChatBot;
use Bitrix\Main\Application, Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Bitrix\Pull\Event;

Loc::loadMessages(__FILE__);

class Recent
{
	private const PINNED_CHATS_LIMIT = 45;
	private const MAX_RAISE_DEPTH = 2;

	static private bool $limitError = false;

	public static function get($userId = null, $options = [])
	{
		$onlyOpenlinesOption = $options['ONLY_OPENLINES'] ?? null;
		$onlyCopilotOption = $options['ONLY_COPILOT'] ?? null;
		$skipOpenlinesOption = $options['SKIP_OPENLINES'] ?? null;
		$skipChat = $options['SKIP_CHAT'] ?? null;
		$skipDialog = $options['SKIP_DIALOG'] ?? null;
		$byChatIds = isset($options['CHAT_IDS']);
		$withCounters = ($options['WITH_COUNTERS'] ?? 'Y') === 'Y';

		if (isset($options['FORCE_OPENLINES']) && $options['FORCE_OPENLINES'] === 'Y')
		{
			$forceOpenlines = 'Y';
		}
		else
		{
			$forceOpenlines = 'N';
		}

		$userId = \Bitrix\Im\Common::getUserId($userId);
		if (!$userId)
		{
			return false;
		}

		$showOpenlines = (
			\Bitrix\Main\Loader::includeModule('imopenlines')
			&& ($onlyOpenlinesOption === 'Y' || $skipOpenlinesOption !== 'Y')
		);

		if (
			$showOpenlines
			&& $forceOpenlines !== 'Y'
			&& class_exists('\Bitrix\ImOpenLines\Recent')
		)
		{
			return \Bitrix\ImOpenLines\Recent::getRecent($userId, $options);
		}

		$generalChatId = \CIMChat::GetGeneralChatId();

		$ormParams = self::getOrmParams([
			'USER_ID' => $userId,
			'SHOW_OPENLINES' => $showOpenlines,
			'WITHOUT_COMMON_USERS' => true,
			'CHAT_IDS' => $options['CHAT_IDS'] ?? null,
		]);

		$lastSyncDateOption = $options['LAST_SYNC_DATE'] ?? null;
		if ($lastSyncDateOption)
		{
			$maxLimit = (new \Bitrix\Main\Type\DateTime())->add('-7 days');
			if ($maxLimit > $options['LAST_SYNC_DATE'])
			{
				$options['LAST_SYNC_DATE'] = $maxLimit;
			}
			$ormParams['filter']['>=DATE_UPDATE'] = $options['LAST_SYNC_DATE'];
		}
		else if ($options['ONLY_OPENLINES'] !== 'Y' && !$byChatIds)
		{
			$ormParams['filter']['>=DATE_UPDATE'] = (new \Bitrix\Main\Type\DateTime())->add('-30 days');
		}

		$skipTypes = [];
		if ($onlyCopilotOption === 'Y')
		{
			$ormParams['filter'][] = [
				'=ITEM_TYPE' => \Bitrix\Im\V2\Chat::IM_TYPE_COPILOT
			];
		}
		elseif ($onlyOpenlinesOption === 'Y')
		{
			$ormParams['filter'][] = [
				'=ITEM_TYPE' => IM_MESSAGE_OPEN_LINE
			];
		}
		elseif (!$byChatIds)
		{
			if (!CopilotChat::isHistoryAvailable())
			{
				$skipTypes[] = \Bitrix\Im\V2\Chat::IM_TYPE_COPILOT;
			}
			if ($options['SKIP_OPENLINES'] === 'Y')
			{
				$skipTypes[] = IM_MESSAGE_OPEN_LINE;
			}
			if ($skipChat === 'Y')
			{
				$skipTypes[] = IM_MESSAGE_OPEN;
				$skipTypes[] = IM_MESSAGE_CHAT;
			}
			if ($skipDialog === 'Y')
			{
				$skipTypes[] = IM_MESSAGE_PRIVATE;
			}
			if (!empty($skipTypes))
			{
				$ormParams['filter'][] = [
					'!@ITEM_TYPE' => $skipTypes
				];
			}
		}

		if (!isset($options['LAST_SYNC_DATE']))
		{
			if (isset($options['OFFSET']))
			{
				$ormParams['offset'] = $options['OFFSET'];
			}
			if (isset($options['LIMIT']))
			{
				$ormParams['limit'] = $options['LIMIT'];
			}
			if (isset($options['ORDER']))
			{
				$ormParams['order'] = $options['ORDER'];
			}
		}

		$result = [];
		$orm = \Bitrix\Im\Model\RecentTable::getList($ormParams);
		$rows = $orm->fetchAll();
		$rows = self::prepareRows($rows, $userId, withCounters: $withCounters);
		foreach ($rows as $row)
		{
			$isUser = $row['ITEM_TYPE'] == IM_MESSAGE_PRIVATE;
			$id = $isUser? (int)$row['ITEM_ID']: 'chat'.$row['ITEM_ID'];

			if ($isUser)
			{
				if (isset($result[$id]) && !$row['ITEM_MID'])
				{
					continue;
				}
			}
			else if (isset($result[$id]))
			{
				continue;
			}

			$item = self::formatRow($row, [
				'GENERAL_CHAT_ID' => $generalChatId,
				'GET_ORIGINAL_TEXT' => $options['GET_ORIGINAL_TEXT'] ?? null,
				'WITH_COUNTERS' => $withCounters,
			]);
			if (!$item)
			{
				continue;
			}

			$result[$id] = $item;
		}
		$result = array_values($result);

		if (
			$showOpenlines
			&& !$options['ONLY_OPENLINES']
			&& class_exists('\Bitrix\ImOpenLines\Recent')
		)
		{
			$options['ONLY_IN_QUEUE'] = true;
			$chatsInQueue = \Bitrix\ImOpenLines\Recent::getRecent($userId, $options);
			$result = array_merge($result, $chatsInQueue);
		}

		\Bitrix\Main\Type\Collection::sortByColumn(
			$result,
			['PINNED' => SORT_DESC, 'MESSAGE' => SORT_DESC, 'ID' => SORT_DESC],
			[
				'ID' => function($row) {
					return $row;
				},
				'MESSAGE' => function($row) {
					return $row['DATE'] instanceof \Bitrix\Main\Type\DateTime ? $row['DATE']->getTimeStamp() : 0;
				},
			]
		);

		if ($options['JSON'])
		{
			foreach ($result as $index => $item)
			{
				$result[$index] = self::jsonRow($item);
			}
		}

		return $result;
	}

	public static function getList($userId = null, $options = [])
	{
		$userId = \Bitrix\Im\Common::getUserId($userId);
		if (!$userId)
		{
			return false;
		}

		$generalChatId = \CIMChat::GetGeneralChatId();

		$viewCommonUsers = Option::get('im', 'view_common_users', 'Y') === 'N'
			? false
			: (bool)\CIMSettings::GetSetting(\CIMSettings::SETTINGS, 'viewCommonUsers')
		;

		$onlyOpenlinesOption = $options['ONLY_OPENLINES'] ?? null;
		$onlyCopilotOption = $options['ONLY_COPILOT'] ?? null;
		$onlyChannelOption = $options['ONLY_CHANNEL'] ?? null;
		$canManageMessagesOption = $options['CAN_MANAGE_MESSAGES'] ?? null;
		$skipChatOption = $options['SKIP_CHAT'] ?? null;
		$skipDialogOption = $options['SKIP_DIALOG'] ?? null;
		$skipCollabOption = Collab::isAvailable() ? ($options['SKIP_COLLAB'] ?? null) : 'Y';
		$lastMessageDateOption = $options['LAST_MESSAGE_DATE'] ?? null;
		$withoutCommonUsers = !$viewCommonUsers || $onlyOpenlinesOption === 'Y';
		$unreadOnly = isset($options['UNREAD_ONLY']) && $options['UNREAD_ONLY'] === 'Y';
		$shortInfo = isset($options['SHORT_INFO']) && $options['SHORT_INFO'] === 'Y';
		$parseText = $options['PARSE_TEXT'] ?? null;
		$withCounters = ($options['WITH_COUNTERS'] ?? 'Y') === 'Y';

		$showOpenlines = (
			\Bitrix\Main\Loader::includeModule('imopenlines')
			&& (
				$onlyOpenlinesOption === 'Y'
				|| $options['SKIP_OPENLINES'] !== 'Y'
			)
		);

		$ormParams = self::getOrmParams([
			'USER_ID' => $userId,
			'SHOW_OPENLINES' => $showOpenlines,
			'WITHOUT_COMMON_USERS' => $withoutCommonUsers,
			'UNREAD_ONLY' => $unreadOnly,
			'SHORT_INFO' => $shortInfo,
		]);

		if ($onlyCopilotOption === 'Y')
		{
			$ormParams['filter'][] = [
				'=ITEM_TYPE' => \Bitrix\Im\V2\Chat::IM_TYPE_COPILOT
			];
		}
		elseif ($onlyOpenlinesOption === 'Y')
		{
			$ormParams['filter'][] = [
				'=ITEM_TYPE' => IM_MESSAGE_OPEN_LINE
			];
		}
		elseif ($onlyChannelOption === 'Y')
		{
			$ormParams['filter'][] = [
				'=ITEM_TYPE' => [\Bitrix\Im\V2\Chat::IM_TYPE_OPEN_CHANNEL, \Bitrix\Im\V2\Chat::IM_TYPE_CHANNEL],
			];
		}
		else
		{
			$skipTypes = [];
			if (!CopilotChat::isHistoryAvailable())
			{
				$skipTypes[] = \Bitrix\Im\V2\Chat::IM_TYPE_COPILOT;
			}
			if ($options['SKIP_OPENLINES'] === 'Y')
			{
				$skipTypes[] = IM_MESSAGE_OPEN_LINE;
			}
			if ($skipChatOption === 'Y')
			{
				$skipTypes[] = IM_MESSAGE_OPEN;
				$skipTypes[] = IM_MESSAGE_CHAT;
			}
			if ($skipDialogOption === 'Y')
			{
				$skipTypes[] = IM_MESSAGE_PRIVATE;
			}
			if ($skipCollabOption === 'Y')
			{
				$skipTypes[] = \Bitrix\Im\V2\Chat::IM_TYPE_COLLAB;
				$skipTypes[] = \Bitrix\Im\V2\Chat::IM_TYPE_OPEN_COLLAB;
			}
			if (!RecentConfigManager::EXTERNAL_CHAT_USE_DEFAULT_RECENT_SECTION)
			{
				$skipTypes[] = \Bitrix\Im\V2\Chat::IM_TYPE_EXTERNAL;
			}
			if (!empty($skipTypes))
			{
				$ormParams['filter'][] = [
					'!@ITEM_TYPE' => $skipTypes
				];
			}
		}

		if ($lastMessageDateOption instanceof \Bitrix\Main\Type\DateTime)
		{
			$ormParams['filter']['<=DATE_LAST_ACTIVITY'] = $lastMessageDateOption;
		}
		else if (isset($options['OFFSET']))
		{
			$ormParams['offset'] = $options['OFFSET'];
		}

		if (isset($options['LIMIT']))
		{
			$ormParams['limit'] = (int)$options['LIMIT'];
		}
		else
		{
			$ormParams['limit'] = 50;
		}

//		TODO: Pin sort by cost (`pinnedChatSort='byCost'`, the default) is temporarily
//		   disabled — always sort by PINNED + DATE_LAST_ACTIVITY regardless of user
//		   preference. Reason: `PIN_SORT` is never reset on unpin. `RecentUpdater::setPinned`
//		   only flips `PINNED` to 'N' and updates `DATE_UPDATE`, leaving the stale
//		   `PIN_SORT` value on the row. With `ORDER BY PIN_SORT ASC` MySQL puts NULLs
//		   first, so any chat that was once pinned ends up after every never-pinned
//		   chat in the unpinned section, silently dropping out of the first page.
		$ormParams['order'] = [
				'PINNED' => 'DESC',
				'DATE_LAST_ACTIVITY' => 'DESC',
			];
//		$sortOption = (new UserConfiguration((int)$userId))->getGeneralSettings()['pinnedChatSort'];
//		if ($sortOption === 'byCost')
//		{
//			$ormParams['order'] = [
//				'PINNED' => 'DESC',
//				'PIN_SORT' => 'ASC',
//				'DATE_LAST_ACTIVITY' => 'DESC',
//			];
//		}
//		else
//		{
//			$ormParams['order'] = [
//				'PINNED' => 'DESC',
//				'DATE_LAST_ACTIVITY' => 'DESC',
//			];
//		}

		if ($canManageMessagesOption === 'Y')
		{
			$ormParams = Permission\Filter::getRoleGetListFilter($ormParams, Permission\ActionGroup::ManageMessages, 'RELATION', 'CHAT');
		}

		$query = RecentTable::query()
			->setSelect($ormParams['select'])
			->setFilter($ormParams['filter'])
		;
		foreach ($ormParams['runtime'] as $rt)
		{
			$query->registerRuntimeField($rt);
		}
		if (isset($ormParams['limit']))
		{
			$query->setLimit($ormParams['limit']);
		}
		if (isset($ormParams['offset']))
		{
			$query->setOffset($ormParams['offset']);
		}
		if (isset($ormParams['order']))
		{
			$query->setOrder($ormParams['order']);
		}
		if ($unreadOnly)
		{
			\Bitrix\Main\DI\ServiceLocator::getInstance()
				->get(\Bitrix\Im\V2\Chat\Tree\ChatTreeFilterFactory::class)
				->forUnread($userId)
				->apply($query);
		}

		$orm = $query->exec();

		$counter = 0;
		$result = [];
		$messageIdsWithCopilotRole = [];
		$copilotData = [];
		$chatsIds = [];
		$messagesAutoDeleteConfigs = [];
		$collabPreviewEntries = [];

		$rows = $orm->fetchAll();
		$rows = self::prepareRows($rows, $userId, $shortInfo, $withCounters);
		foreach ($rows as $row)
		{
			$counter++;
			$isUser = $row['ITEM_TYPE'] == IM_MESSAGE_PRIVATE;
			$id = $isUser? (int)$row['ITEM_ID']: 'chat'.$row['ITEM_ID'];

			if ($isUser)
			{
				if (isset($result[$id]) && !$row['ITEM_MID'])
				{
					continue;
				}
			}
			else if (isset($result[$id]))
			{
				continue;
			}

			$item = self::formatRow($row, [
				'GENERAL_CHAT_ID' => $generalChatId,
				'WITHOUT_COMMON_USERS' => $withoutCommonUsers,
				'GET_ORIGINAL_TEXT' => $options['GET_ORIGINAL_TEXT'] ?? null,
				'SHORT_INFO' => $shortInfo,
				'PARSE_TEXT' => $parseText,
				'WITH_COUNTERS' => $withCounters,
			]);
			if (!$item)
			{
				continue;
			}

			if ($row['ITEM_TYPE'] === \Bitrix\Im\V2\Chat::IM_TYPE_COPILOT)
			{
				$copilotData['chats'][$item['ID']] = (int)$item['CHAT_ID'];
			}

			if (!$shortInfo)
			{
				$chatsIds[] = (int)$item['CHAT_ID'];
			}

			if (
				!$shortInfo
				&& (isset($item['USER']['BOT']) && $item['USER']['BOT'] === true)
				&& Loader::includeModule('imbot')
				&& (int)$item['MESSAGE']['AUTHOR_ID'] === CopilotChatBot::getBotId()
			)
			{
				$copilotData['messages'][(int)$item['MESSAGE']['ID']] = true;
			}

			$isCollabRow = in_array(
				$row['ITEM_TYPE'],
				[\Bitrix\Im\V2\Chat::IM_TYPE_COLLAB, \Bitrix\Im\V2\Chat::IM_TYPE_OPEN_COLLAB],
				true
			);
			if ($isCollabRow)
			{
				// Keep collab rows raw (UPPER_CASE) so post-loop enrichment can rewrite the displayed
				// MESSAGE to the source message before any snake_case JSON conversion.
				$collabPreviewEntries[$id] = [
					'collabChatId' => (int)$row['ITEM_CID'],
					'previewSourceCid' => (int)($row['PREVIEW_SOURCE_CID'] ?? 0),
					'previewSourceMid' => (int)($row['PREVIEW_SOURCE_MID'] ?? 0),
					// Own last message id, captured before the redirect overwrites MESSAGE with the source.
					'ownMessageId' => (int)($row['ITEM_MID'] ?? 0),
				];
				$result[$id] = $item;
			}
			elseif ($shortInfo && $options['JSON'])
			{
				$result[$id] = self::jsonRow($item);
			}
			else
			{
				$result[$id] = $item;
			}
		}

		$collabOwnMessages = [];
		$collabOwnIds = [];
		$collabPreviewSources = self::enrichCollabPreviewSources(
			$result,
			$collabPreviewEntries,
			[
				'GET_ORIGINAL_TEXT' => $options['GET_ORIGINAL_TEXT'] ?? null,
				'PARSE_TEXT' => $parseText,
			],
			$shortInfo,
			$collabOwnMessages,
			$collabOwnIds
		);

		$copilotData = self::prepareCopilotData($copilotData, $userId, $shortInfo);

		if ($showOpenlines && !$onlyCopilotOption && Loader::includeModule('imopenlines'))
		{
			if (!isset($options['SKIP_UNDISTRIBUTED_OPENLINES']) || $options['SKIP_UNDISTRIBUTED_OPENLINES'] !== 'Y')
			{
				$recentOpenLines = \Bitrix\ImOpenLines\Recent::getRecent($userId, ['ONLY_IN_QUEUE' => true]);

				if (is_array($recentOpenLines))
				{
					$result = array_merge($result, $recentOpenLines);
				}
			}
		}

		if (!$shortInfo)
		{
			$messagesAutoDeleteConfigs = (new MessagesAutoDeleteConfigs($chatsIds))->toRestFormat();
		}

		$result = array_values($result);

		if ($options['JSON'])
		{
			foreach ($result as $index => $item)
			{
				// Short path already jsonRow'd everything except the deferred collab rows kept raw above.
				if (!$shortInfo || self::isRawCollabResultItem($item))
				{
					$result[$index] = self::jsonRow($item);
				}
			}

			self::injectCollabPreviewSources($result, $collabPreviewSources, false, $collabOwnMessages, $collabOwnIds);

			$objectToReturn = [
				'items' => $result,
				'hasMorePages' => $ormParams['limit'] == $counter, // TODO remove this later
				'hasMore' => $ormParams['limit'] == $counter,
				'copilot' => !empty($copilotData) ? $copilotData : null,
				'messagesAutoDeleteConfigs' => $messagesAutoDeleteConfigs,
			];

			if (!isset($options['LAST_MESSAGE_DATE']) && !$unreadOnly)
			{
				$objectToReturn['birthdayList'] = \Bitrix\Im\Integration\Intranet\User::getBirthdayForToday();
			}

			return $objectToReturn;
		}

		self::injectCollabPreviewSources($result, $collabPreviewSources, true, $collabOwnMessages, $collabOwnIds);

		$converter = new Converter(Converter::TO_SNAKE | Converter::TO_UPPER | Converter::KEYS);

		return [
			'ITEMS' => $result,
			'HAS_MORE_PAGES' => $ormParams['limit'] == $counter, // TODO remove this later
			'HAS_MORE' => $ormParams['limit'] == $counter,
			'COPILOT' => !empty($copilotData) ? $converter->process($copilotData) : null,
			'MESSAGES_AUTO_DELETE_CONFIGS' => $converter->process($messagesAutoDeleteConfigs) ?? [],
		];
	}

	/**
	 * Redirects the displayed MESSAGE of collab rows to the per-user preview source (batch resolve) and
	 * builds the inline nestedChat identity for rows whose source is a child chat. The collab row stays
	 * the root row; only its MESSAGE payload is redirected. nestedChat is emitted only for a child source;
	 * for a main-chat source the row's own CHAT block already describes it.
	 *
	 * @param array $result keyed by recent result id; collab rows are stored raw (UPPER_CASE)
	 * @param array<int|string,array{collabChatId:int,previewSourceCid:int,previewSourceMid:int,ownMessageId:int}> $collabEntries
	 * @param array<int,array{ownMessageId:int,ownMessage:array}> $ownByCollab OUT: collabChatId => own last
	 *        message id + the own MESSAGE block (UPPER_CASE, pre-jsonRow), child-source rows only
	 * @param array<int,int> $ownIdByCollab OUT: collabChatId => own last message id, for EVERY collab row
	 *        with a positive own message (the redirect-independent ownMessageId pointer the client reads).
	 * @return array<int,array> collabChatId => nestedChat identity array (camelCase)
	 */
	private static function enrichCollabPreviewSources(
		array &$result,
		array $collabEntries,
		array $messageOptions,
		bool $shortInfo,
		array &$ownByCollab = [],
		array &$ownIdByCollab = []
	): array
	{
		$ownByCollab = [];
		$ownIdByCollab = [];

		// Always expose the row's own last message id (redirect-independent) for every collab row, mirroring
		// the V2 contract. The client reads this only on the collab fixed-row forceOwnMessage path.
		foreach ($collabEntries as $entry)
		{
			$collabChatId = (int)($entry['collabChatId'] ?? 0);
			$ownMessageId = (int)($entry['ownMessageId'] ?? 0);
			if ($collabChatId > 0 && $ownMessageId > 0)
			{
				$ownIdByCollab[$collabChatId] = $ownMessageId;
			}
		}

		if (empty($collabEntries) || !\Bitrix\Im\V2\Application\Features::isCollabPreviewSourceEnabled())
		{
			return [];
		}

		$sourceMessageIds = [];
		foreach ($collabEntries as $entry)
		{
			$mid = (int)($entry['previewSourceMid'] ?? 0);
			if ($mid > 0)
			{
				$sourceMessageIds[$mid] = $mid;
			}
		}

		if (empty($sourceMessageIds))
		{
			return [];
		}

		$messageBlocks = self::buildCollabPreviewMessageRows(array_values($sourceMessageIds), $messageOptions, $shortInfo);

		$nestedChatByCollab = [];
		foreach ($collabEntries as $resultKey => $entry)
		{
			$collabChatId = (int)($entry['collabChatId'] ?? 0);
			$sourceMessageId = (int)($entry['previewSourceMid'] ?? 0);
			$sourceChatId = (int)($entry['previewSourceCid'] ?? 0);

			// 0 = source is the row itself (main chat): leave the row untouched.
			if ($sourceMessageId <= 0 || !isset($result[$resultKey]))
			{
				continue;
			}

			// Snapshot the row's own last message BEFORE the redirect overwrites MESSAGE with the source block.
			$ownMessageId = (int)($entry['ownMessageId'] ?? 0);
			$ownMessageBlock = $result[$resultKey]['MESSAGE'] ?? null;

			$redirectOccurred = isset($messageBlocks[$sourceMessageId]);
			if ($redirectOccurred)
			{
				$result[$resultKey]['MESSAGE'] = $messageBlocks[$sourceMessageId];
			}

			// Inline nestedChat identity is needed ONLY for a child source: when the source is the collab
			// main chat the row's own CHAT block already describes it.
			if ($sourceChatId <= 0 || $sourceChatId === $collabChatId)
			{
				continue;
			}

			// Carry the captured own message inline alongside nestedChat. The $redirectOccurred guard avoids
			// duplication: without a redirect the displayed MESSAGE already IS the own message (own == message).
			if ($redirectOccurred && $ownMessageId > 0 && $ownMessageId !== $sourceMessageId && is_array($ownMessageBlock))
			{
				$ownByCollab[$collabChatId] = [
					'ownMessageId' => $ownMessageId,
					'ownMessage' => $ownMessageBlock,
				];
			}

			$sourceChat = \Bitrix\Im\V2\Chat::getInstance($sourceChatId);
			if ($sourceChat instanceof \Bitrix\Im\V2\Chat\NullChat)
			{
				continue;
			}

			$nestedChatByCollab[$collabChatId] = [
				'id' => $sourceChatId,
				'dialogId' => (string)$sourceChat->getDialogId(),
				'type' => $sourceChat->getExtendedType(),
				'name' => (string)$sourceChat->getTitle(),
				'avatar' => $sourceChat->getAvatar(),
				'color' => $sourceChat->getColor(true),
				'extranet' => $sourceChat->getExtranet() ?? false,
				'entityType' => $sourceChat->getEntityType() ?? '',
				'parentChatId' => $sourceChat->getParentChatId(),
			];
		}

		return $nestedChatByCollab;
	}

	/**
	 * Loads several preview-source messages at once and formats each one into the same MESSAGE block
	 * shape used for the row's own last message (so the client renders it identically).
	 *
	 * @param int[] $messageIds
	 * @return array<int,array> messageId => formatted MESSAGE block (UPPER_CASE, pre-jsonRow)
	 */
	private static function buildCollabPreviewMessageRows(array $messageIds, array $messageOptions, bool $shortInfo): array
	{
		if (empty($messageIds))
		{
			return [];
		}

		$messageRows = \Bitrix\Im\Model\MessageTable::query()
			->setSelect(['ID', 'CHAT_ID', 'AUTHOR_ID', 'MESSAGE', 'DATE_CREATE', 'NOTIFY_READ', 'UUID_VALUE' => 'UUID.UUID'])
			->whereIn('ID', $messageIds)
			->fetchAll()
		;

		$params = $shortInfo ? [] : self::getMessageParams($messageIds);

		$blocks = [];
		foreach ($messageRows as $messageRow)
		{
			$messageId = (int)$messageRow['ID'];
			$param = $params[$messageId] ?? [];

			$row = [
				'ITEM_MID' => $messageId,
				'MESSAGE_ID' => $messageId,
				// Source chat id: lets the mobile client resolve the source chat via message.chatId.
				'MESSAGE_CHAT_ID' => (int)($messageRow['CHAT_ID'] ?? 0),
				'MESSAGE_TEXT' => $messageRow['MESSAGE'] ?? '',
				'MESSAGE_AUTHOR_ID' => (int)($messageRow['AUTHOR_ID'] ?? 0),
				'DATE_MESSAGE' => $messageRow['DATE_CREATE'] ?? null,
				'DATE_UPDATE' => $messageRow['DATE_CREATE'] ?? null,
				'DATE_LAST_ACTIVITY' => $messageRow['DATE_CREATE'] ?? null,
				'CHAT_LAST_MESSAGE_STATUS' => ($messageRow['NOTIFY_READ'] ?? 'N') === 'Y'
					? \IM_MESSAGE_STATUS_DELIVERED
					: \IM_MESSAGE_STATUS_RECEIVED,
				'MESSAGE_UUID_VALUE' => $messageRow['UUID_VALUE'] ?? null,
				'MESSAGE_CODE' => $param['CODE'] ?? null,
				'MESSAGE_ATTACH' => $param['ATTACH']['VALUE'] ?? null,
				'MESSAGE_ATTACH_JSON' => $param['ATTACH']['JSON'] ?? null,
				'MESSAGE_FILE' => $param['MESSAGE_FILE'] ?? false,
				'MESSAGE_STICKER' => $param['STICKER'] ?? null,
				'BLOCK' => $param['BLOCK'] ?? false,
			];

			$blocks[$messageId] = self::formatMessage($row, $messageOptions, $shortInfo);
		}

		return $blocks;
	}

	/**
	 * Detects a deferred collab row on the SHORT_INFO+JSON path by its surviving UPPER_CASE key (non-collab
	 * rows were already jsonRow'd in the loop). Must not be gated by the nestedChat map: that map is empty
	 * for a main-chat source (e.g. feature flag off), yet the raw row must still be converted.
	 */
	private static function isRawCollabResultItem(array $item): bool
	{
		return array_key_exists('CHAT_ID', $item);
	}

	/**
	 * Injects the inline nestedChat identity and the additive own last message into matching collab rows
	 * AFTER any snake_case jsonRow conversion, so the camelCase nested keys reach the client verbatim.
	 * Key names follow the path casing: camelCase on the JSON path, UPPER_CASE on the non-JSON path.
	 *
	 * @param array<int,array> $collabPreviewSources collabChatId => nestedChat identity array
	 * @param bool $upperKeys true for the non-JSON path (UPPER_CASE item keys), false for JSON output
	 * @param array<int,array{ownMessageId:int,ownMessage:array}> $collabOwnMessages collabChatId => own last
	 *        message id + own MESSAGE block (UPPER_CASE, pre-jsonRow), child-source rows only (redirect path)
	 * @param array<int,int> $ownIdByCollab collabChatId => own last message id, for EVERY collab row; the
	 *        ownMessageId pointer is emitted unconditionally so the client never derives it.
	 */
	private static function injectCollabPreviewSources(
		array &$result,
		array $collabPreviewSources,
		bool $upperKeys,
		array $collabOwnMessages = [],
		array $ownIdByCollab = []
	): void
	{
		if (empty($collabPreviewSources) && empty($collabOwnMessages) && empty($ownIdByCollab))
		{
			return;
		}

		$chatIdKey = $upperKeys ? 'CHAT_ID' : 'chat_id';
		$nestedChatKey = $upperKeys ? 'NESTED_CHAT' : 'nestedChat';
		$ownMessageIdKey = $upperKeys ? 'OWN_MESSAGE_ID' : 'ownMessageId';
		$ownMessageKey = $upperKeys ? 'OWN_MESSAGE' : 'ownMessage';

		foreach ($result as $index => $item)
		{
			if (!is_array($item) || !isset($item[$chatIdKey]))
			{
				continue;
			}

			$collabChatId = (int)$item[$chatIdKey];
			if (isset($collabPreviewSources[$collabChatId]))
			{
				$result[$index][$nestedChatKey] = $collabPreviewSources[$collabChatId];
			}

			// Always emit the ownMessageId id (redirect-independent). The ownMessage OBJECT below stays
			// gated by the redirect path, where own != displayed message.
			if (isset($ownIdByCollab[$collabChatId]))
			{
				$result[$index][$ownMessageIdKey] = (int)$ownIdByCollab[$collabChatId];
			}

			if (isset($collabOwnMessages[$collabChatId]))
			{
				$own = $collabOwnMessages[$collabChatId];
				$ownMessageBlock = $own['ownMessage'];
				// On the JSON path convert the own block too, so its inner keys match the displayed message.
				if (!$upperKeys)
				{
					$ownMessageBlock = self::jsonRow($ownMessageBlock);
				}

				$result[$index][$ownMessageKey] = $ownMessageBlock;
			}
		}
	}

	private static function fillCopilotMessageRoles(array $messageIdsWithCopilotRole): array
	{
		$copilotMessageRoles = [];

		$collection = Param::getDataClass()::query()
			->setSelect(['MESSAGE_ID', 'PARAM_VALUE'])
			->whereIn('MESSAGE_ID', $messageIdsWithCopilotRole)
			->where('PARAM_NAME', \Bitrix\Im\V2\Message\Params::COPILOT_ROLE)
			->fetchCollection()
		;

		foreach ($collection as $item)
		{
			$copilotMessageRoles[(int)$item->getMessageId()] = $item->getParamValue();
		}

		return $copilotMessageRoles;
	}

	private static function prepareCopilotData(array $copilotData, int $userId, bool $shortInfo): array
	{
		if (!empty($copilotData['chats']))
		{
			$copilotData['chats'] = self::prepareCopilotChats($copilotData['chats']);
		}

		if (!$shortInfo && !empty($copilotData['messages']))
		{
			$copilotData['messages'] = self::prepareCopilotMessages($copilotData['messages']);
		}

		$roleManager = (new RoleManager())->setContextUser($userId);
		$recentCopilotRoles = !$shortInfo ? $roleManager->getRecentKeyRoles() : [];

		$chatRoles = [];
		if (!empty($copilotData['chats']))
		{
			$originalKeys = array_keys($copilotData['chats']);
			$chatRoles = array_column($copilotData['chats'], 'role');
			$chatRoles = array_combine($originalKeys, $chatRoles);
		}

		$copilotRoles = array_values(
			array_merge(
				$chatRoles,
				$copilotData['messages'] ?? [],
				$recentCopilotRoles
			)
		);

		$chats = CopilotPopupItem::convertArrayDataForChats($copilotData['chats'] ?? []);
		$messages = CopilotPopupItem::convertArrayDataForMessages($copilotData['messages'] ?? []);
		$roles =
			$shortInfo
				? $roleManager->getRolesShort($copilotRoles)
				: $roleManager->getRoles($copilotRoles)
		;
		return [
			'chats' => !empty($chats) ? $chats : null,
			'messages' => !empty($messages) ? $messages : null,
			'roles' => !empty($roles) ? $roles : null,
			'recommendedRoles' => !empty($recentCopilotRoles) ? $recentCopilotRoles : null,
		];
	}

	private static function prepareCopilotChats(?array $copilotChats): array
	{
		if (empty($copilotChats))
		{
			return [];
		}

		$roleManager = new RoleManager();
		$chats = [];

		foreach ($copilotChats as $itemId => $chatId)
		{
			$chat = \Bitrix\Im\V2\Chat::getInstance($chatId);
			$copilotChatRole = $roleManager->getMainRole($chatId);

			if (isset($copilotChatRole))
			{
				$chats[$itemId] = [
					'role' => $copilotChatRole,
					'engine' => $chat instanceof CopilotChat ? $chat->getEngineCode() : null,
					'titleIsCustom' => (
						$chat instanceof CopilotChat
						&& (new CopilotTitle($chatId))->isCustom()
					),
				];
			}
		}

		return $chats;
	}

	private static function prepareCopilotMessages(array $copilotMessages): array
	{
		if (empty($copilotMessages))
		{
			return [];
		}

		$messageIds = array_keys($copilotMessages);
		$messageRoles = self::fillCopilotMessageRoles($messageIds);

		$messages = [];
		foreach ($copilotMessages as $messageId => $value)
		{
			$messages[$messageId] = $messageRoles[$messageId] ?? RoleManager::getDefaultRoleCode();
		}

		return $messages;
	}

	public static function getElement($itemType, $itemId, $userId = null, $options = [])
	{
		$userId = \Bitrix\Im\Common::getUserId($userId);
		if (!$userId)
		{
			return false;
		}

		$generalChatId = \CIMChat::GetGeneralChatId();

		$ormParams = self::getOrmParams([
			'USER_ID' => $userId,
			'SHOW_OPENLINES' => $itemType === IM_MESSAGE_OPEN_LINE,
			'WITHOUT_COMMON_USERS' => true,
		]);

		$ormParams['filter']['=ITEM_TYPE'] = $itemType;
		$ormParams['filter']['=ITEM_ID'] = $itemId;

		$orm = \Bitrix\Im\Model\RecentTable::getList([
			'select' => $ormParams['select'],
			'filter' => $ormParams['filter'],
			'runtime' => $ormParams['runtime'],
		]);

		$result = null;
		$rows = $orm->fetchAll();
		$rows = self::prepareRows($rows, $userId);
		foreach ($rows as $row)
		{
			$isUser = $row['ITEM_TYPE'] == IM_MESSAGE_PRIVATE;
			if ($isUser)
			{
				if ($result && !$row['ITEM_MID'])
				{
					continue;
				}
			}
			else if ($result)
			{
				continue;
			}

			$item = self::formatRow($row, [
				'GENERAL_CHAT_ID' => $generalChatId,
			]);
			if (!$item)
			{
				continue;
			}

			$result = $item;
		}
		$result = self::prepareRows([$result], $userId)[0];

		if ($options['JSON'])
		{
			$result = self::jsonRow($result);
		}

		return $result;
	}

	private static function getOrmParams($params)
	{
		$userId = (int)$params['USER_ID'];
		$showOpenlines = \Bitrix\Main\Loader::includeModule('imopenlines') && $params['SHOW_OPENLINES'] !== false;
		$isIntranetInstalled = \Bitrix\Main\Loader::includeModule('intranet');
		$isIntranet = $isIntranetInstalled && \Bitrix\Intranet\Util::isIntranetUser($userId);
		$withoutCommonUsers = $params['WITHOUT_COMMON_USERS'] === true || !$isIntranet;
		$unreadOnly = isset($params['UNREAD_ONLY']) && $params['UNREAD_ONLY'] === true;
		$shortInfo = isset($params['SHORT_INFO']) && $params['SHORT_INFO'] === true;
		$chatIds = $params['CHAT_IDS'] ?? null;

		$shortInfoFields = [
			'USER_ID',
			'ITEM_TYPE',
			'ITEM_ID',
			'ITEM_MID',
			'ITEM_CID',
			'PREVIEW_SOURCE_CID',
			'PREVIEW_SOURCE_MID',
			'PINNED',
			'UNREAD',
			'DATE_MESSAGE',
			'DATE_LAST_ACTIVITY',
			'PIN_SORT',
			'RELATION_ID' => 'RELATION.ID',
			'RELATION_NOTIFY_BLOCK' => 'RELATION.NOTIFY_BLOCK',
			'RELATION_IS_MANAGER' => 'RELATION.MANAGER',
			'CHAT_ID' => 'CHAT.ID',
			'CHAT_TITLE' => 'CHAT.TITLE',
			'CHAT_TYPE' => 'CHAT.TYPE',
			'CHAT_PARENT_ID' => 'CHAT.PARENT_ID',
			'CHAT_AVATAR' => 'CHAT.AVATAR',
			'CHAT_AUTHOR_ID' => 'CHAT.AUTHOR_ID',
			'CHAT_COLOR' => 'CHAT.COLOR',
			'CHAT_ENTITY_TYPE' => 'CHAT.ENTITY_TYPE',
			'CHAT_CAN_POST' => 'CHAT.CAN_POST',
			'CHAT_EXTRANET' => 'CHAT.EXTRANET',
			'USER_LAST_ACTIVITY_DATE' => 'USER.LAST_ACTIVITY_DATE',
			'USER_ACTIVE' => 'USER.ACTIVE',
		];

		$additionalInfoFields = [
			'ITEM_OLID',
			'DATE_UPDATE',
			'MESSAGE_ID' => 'MESSAGE.ID',
			'MESSAGE_AUTHOR_ID' => 'MESSAGE.AUTHOR_ID',
			'MESSAGE_TEXT' => 'MESSAGE.MESSAGE',
			'MESSAGE_USER_LAST_ACTIVITY_DATE' => 'MESSAGE.AUTHOR.LAST_ACTIVITY_DATE',
			'USER_EMAIL' => 'USER.EMAIL',
			'MESSAGE_UUID_VALUE' => 'MESSAGE_UUID.UUID',
			'CHAT_MANAGE_USERS_ADD' => 'CHAT.MANAGE_USERS_ADD',
			'CHAT_MANAGE_USERS_DELETE' => 'CHAT.MANAGE_USERS_DELETE',
			'CHAT_MANAGE_UI' => 'CHAT.MANAGE_UI',
			'CHAT_MANAGE_SETTINGS' => 'CHAT.MANAGE_SETTINGS',
			'CHAT_LAST_MESSAGE_STATUS_BOOL' => 'MESSAGE.NOTIFY_READ',
			'RELATION_LAST_ID' => 'RELATION.LAST_ID',
			'CHAT_PARENT_MID' => 'CHAT.PARENT_MID',
			'CHAT_ENTITY_ID' => 'CHAT.ENTITY_ID',
			'CHAT_ENTITY_DATA_1' => 'CHAT.ENTITY_DATA_1',
			'CHAT_ENTITY_DATA_2' => 'CHAT.ENTITY_DATA_2',
			'CHAT_ENTITY_DATA_3' => 'CHAT.ENTITY_DATA_3',
			'CHAT_DATE_CREATE' => 'CHAT.DATE_CREATE',
			'CHAT_USER_COUNT' => 'CHAT.USER_COUNT',
		];

		$shortRuntime = [
			new \Bitrix\Main\Entity\ReferenceField(
				'USER',
				'\Bitrix\Main\UserTable',
				array("=this.ITEM_TYPE" => new \Bitrix\Main\DB\SqlExpression("?s", IM_MESSAGE_PRIVATE), "=ref.ID" => "this.ITEM_ID"),
				array("join_type"=>"LEFT")
			),
		];

		if ($shortInfo)
		{
			$shortRuntime[] = new \Bitrix\Main\Entity\ReferenceField(
				'CODE',
				'\Bitrix\Im\Model\MessageParamTable',
				[
					"=ref.MESSAGE_ID" => "this.ITEM_MID",
					"=ref.PARAM_NAME" => new \Bitrix\Main\DB\SqlExpression("?s", "CODE")
				],
				["join_type" => "LEFT"]
			);
			$shortInfoFields['MESSAGE_CODE'] = 'CODE.PARAM_VALUE';
		}

		$select = $shortInfo ? $shortInfoFields : array_merge($shortInfoFields, $additionalInfoFields);
		$runtime = $shortRuntime;

		if (!$withoutCommonUsers)
		{
			$select['INVITATION_ORIGINATOR_ID'] = 'INVITATION.ORIGINATOR_ID';
		}
		if ($showOpenlines)
		{
			$select['LINES_ID'] = 'LINES.ID';
			$select['LINES_STATUS'] = 'LINES.STATUS';
			$select['LINES_DATE_CREATE'] = 'LINES.DATE_CREATE';
		}

		if (!$withoutCommonUsers)
		{
			$runtime[] = new \Bitrix\Main\Entity\ReferenceField(
				'INVITATION',
				'\Bitrix\Intranet\Internals\InvitationTable',
				array("=this.ITEM_TYPE" => new \Bitrix\Main\DB\SqlExpression("?s", IM_MESSAGE_PRIVATE), "=ref.USER_ID" => "this.ITEM_ID"),
				array("join_type"=>"LEFT")
			);
		}
		if ($showOpenlines && !$shortInfo)
		{
			$runtime[] = new \Bitrix\Main\Entity\ReferenceField(
				'LINES',
				'\Bitrix\ImOpenlines\Model\SessionTable',
				[">this.ITEM_OLID" => new \Bitrix\Main\DB\SqlExpression("?i", 0), "=ref.ID" => "this.ITEM_OLID"],
				["join_type" => "LEFT"]
			);
		}

		if ($withoutCommonUsers)
		{
			$filter = ['=USER_ID' => $userId];
		}
		else
		{
			$filter = ['@USER_ID' => [$userId, 0]];
		}

		if ($isIntranetInstalled && !$isIntranet)
		{
			$subQuery = User::getInstance($userId) instanceof UserGuest
				? self::getGuestSharedUsersQuery($userId)
				: Group::getExtranetAccessibleUsersQuery($userId)
			;
			if ($subQuery !== null)
			{
				$filter[] = [
					'LOGIC' => 'OR',
					['!=ITEM_TYPE' => 'P'],
					['@USER.ID' => new SqlExpression($subQuery->getQuery())],
				];
			}
		}

		if ($chatIds)
		{
			$filter['@ITEM_CID'] = $chatIds; // todo: add index
		}
		else
		{
			$filter['=CHAT_PARENT_ID'] = 0;
		}

		return [
			'select' => $select,
			'filter' => $filter,
			'runtime' => $runtime,
		];
	}

	private static function getGuestSharedUsersQuery(int $userId): Query
	{
		$query = RelationTable::query();
		$query->addSelect(new ExpressionField('DISTINCT_USER_ID', 'DISTINCT %s', 'OTHER.USER_ID'));
		$query->registerRuntimeField(
			new Reference(
				'OTHER',
				RelationTable::class,
				Join::on('this.CHAT_ID', 'ref.CHAT_ID'),
				['join_type' => Join::TYPE_INNER]
			)
		);
		$query->where('USER_ID', $userId);
		$query->whereNot('MESSAGE_TYPE', \IM_MESSAGE_PRIVATE);

		return $query;
	}

	private static function formatRow($row, $options = []): ?array
	{
		$generalChatId = (int)$options['GENERAL_CHAT_ID'];
		$withoutCommonUsers = isset($options['WITHOUT_COMMON_USERS']) && $options['WITHOUT_COMMON_USERS'] === true;
		$shortInfo = isset($options['SHORT_INFO']) && $options['SHORT_INFO'];
		$withCounters = $options['WITH_COUNTERS'] ?? true;

		$isUser = $row['ITEM_TYPE'] == IM_MESSAGE_PRIVATE;
		$id = $isUser? (int)$row['ITEM_ID']: 'chat'.$row['ITEM_ID'];
		$row['MESSAGE_ID'] ??= null;

		if (!$isUser && ((!$row['MESSAGE_ID'] && !$shortInfo) || !$row['RELATION_ID'] || !$row['CHAT_ID']))
		{
			return null;
		}

		$item = self::formatItem($row,$options, $shortInfo, $isUser, $id, $withCounters);

		if ($isUser)
		{
			if (
				$withoutCommonUsers
				&& ($row['USER_ID'] == 0 || $row['MESSAGE_CODE'] === 'USER_JOIN')
			)
			{
				return null;
			}

			$row['INVITATION_ORIGINATOR_ID'] ??= null;
			if (!$row['USER_LAST_ACTIVITY_DATE'] && $row['INVITATION_ORIGINATOR_ID'])
			{
				$item['INVITED'] = [
					'ORIGINATOR_ID' => (int)$row['INVITATION_ORIGINATOR_ID'],
					'CAN_RESEND' => !empty($row['USER_EMAIL'])
				];
			}
			$item['USER'] = [
				'ID' => (int)$row['ITEM_ID'],
			];

			$muteList = [];
			if ($row['RELATION_NOTIFY_BLOCK'] == 'Y')
			{
				$muteList = [$row['RELATION_USER_ID'] => true];
			}

			$item['CHAT']['TEXT_FIELD_ENABLED'] = self::getTextFieldEnabled((int)$row['ITEM_CID']);
			$item['CHAT']['BACKGROUND_ID'] = self::getBackgroundId((int)$row['ITEM_CID']);
			$item['CHAT']['MUTE_LIST'] = $muteList;
		}
		else
		{
			$item['CHAT'] = self::formatChat($row, $shortInfo, $generalChatId);

			if (!$shortInfo)
			{
				$item['AVATAR'] = [
					'URL' => $item['CHAT']['AVATAR'],
					'COLOR' => $item['CHAT']['COLOR'],
				];
				$item['TITLE'] = $row['CHAT_TITLE'];
			}

			if ($row["CHAT_ENTITY_TYPE"] == 'LINES')
			{
				$item['LINES'] = [
					'ID' => (int)$row['LINES_ID'],
					'STATUS' => (int)$row['LINES_STATUS'],
					'DATE_CREATE' => $row['LINES_DATE_CREATE'] ?? $row['DATE_UPDATE'],
				];
			}
			$item['USER'] = [
				'ID' => (int)($row['MESSAGE_AUTHOR_ID'] ?? 0),
			];
		}

		if ($item['USER']['ID'] > 0)
		{
			$user = self::formatUser($row, $item, $shortInfo);

			if ($user === null)
			{
				return null;
			}

			$item['USER'] = $user;

			if (
				!$shortInfo
				&& $item['TYPE'] == 'user'
				&& !empty($user)
			)
			{
				$item['AVATAR'] = [
					'URL' => $user['AVATAR'],
					'COLOR' => $user['COLOR']
				];
				$item['TITLE'] = $user['NAME'];
			}
		}

		$item['OPTIONS'] = [];
		if ($row['USER_ID'] == 0 || $row['MESSAGE_CODE'] === 'USER_JOIN')
		{
			$item['OPTIONS']['DEFAULT_USER_RECORD'] = true;
		}

		return $item;
	}

	private static function formatMessage(array $row, array $options, bool $shortInfo): array
	{
		$row['DATE_MESSAGE'] ??= null;

		// Emitted only when the caller pinned an explicit source chat id; absent for the own last message,
		// keeping the legacy shape unchanged.
		$messageChatId = isset($row['MESSAGE_CHAT_ID']) ? (int)$row['MESSAGE_CHAT_ID'] : null;

		if ($shortInfo)
		{
			$shortMessage = [
				'ID' => (int)($row['ITEM_MID'] ?? 0),
				'DATE' => $row['DATE_MESSAGE'] ?: $row['DATE_LAST_ACTIVITY'],
			];
			if ($messageChatId !== null)
			{
				$shortMessage['CHAT_ID'] = $messageChatId;
			}

			return $shortMessage;
		}

		if (!$row['ITEM_MID'] || !$row['MESSAGE_ID'])
		{
			$emptyMessage = [
				'ID' => (int)($row['ITEM_MID'] ?? 0),
				'TEXT' => "",
				'FILE' => false,
				'AUTHOR_ID' =>  0,
				'ATTACH' => false,
				'BLOCK' => false,
				'STICKER' => null,
				'DATE' => $row['DATE_MESSAGE']?: $row['DATE_UPDATE'],
				'STATUS' => $row['CHAT_LAST_MESSAGE_STATUS'],
			];
			if ($messageChatId !== null)
			{
				$emptyMessage['CHAT_ID'] = $messageChatId;
			}

			return $emptyMessage;
		}

		$attach = false;
		if ($row['MESSAGE_ATTACH'] || $row["MESSAGE_ATTACH_JSON"])
		{
			if (preg_match('/^(\d+)$/', $row['MESSAGE_ATTACH']))
			{
				$attach = true;
			}
			else if ($row['MESSAGE_ATTACH'] === \CIMMessageParamAttach::FIRST_MESSAGE)
			{
				try
				{
					$value = Json::decode($row["MESSAGE_ATTACH_JSON"]);
					$attachRestored = \CIMMessageParamAttach::PrepareAttach($value);
					$attach = $attachRestored['DESCRIPTION'];
				}
				catch (\Bitrix\Main\SystemException $e)
				{
					$attach = true;
				}
			}
			else if (!empty($row['MESSAGE_ATTACH']))
			{
				$attach = $row['MESSAGE_ATTACH'];
			}
			else
			{
				$attach = true;
			}
		}

		$text = $row['MESSAGE_TEXT'] ?? '';

		$getOriginalTextOption = $options['GET_ORIGINAL_TEXT'] ?? null;
		$parseText = $options['PARSE_TEXT'] ?? null;
		if ($parseText === 'Y')
		{
			$text = Text::parse($text);
		}
		elseif ($getOriginalTextOption === 'Y')
		{
			$text = preg_replace_callback("/\[USER=([0-9]+|all)\]\[\/USER\]/i",['\Bitrix\Im\Text', 'modifyShortUserTag'], $text);
		}
		else
		{
			$text = Text::removeBbCodes(
				str_replace("\n", " ", $text),
				$row['MESSAGE_FILE'] > 0,
				$attach
			);
		}

		$sticker = self::getStickerParams($row['MESSAGE_STICKER'] ?? null);

		$message = [
			'ID' => (int)$row['ITEM_MID'],
			'TEXT' => $text,
			'FILE' => $row['MESSAGE_FILE'],
			'AUTHOR_ID' =>  (int)$row['MESSAGE_AUTHOR_ID'],
			'ATTACH' => $attach,
			'BLOCK' => $row['BLOCK'],
			'STICKER' => $sticker,
			'DATE' => $row['DATE_MESSAGE']?: $row['DATE_UPDATE'],
			'STATUS' => $row['CHAT_LAST_MESSAGE_STATUS'],
			'UUID' => $row['MESSAGE_UUID_VALUE'],
		];
		if ($messageChatId !== null)
		{
			$message['CHAT_ID'] = $messageChatId;
		}

		return $message;
	}

	private static function formatItem(
		array $row,
		array $options,
		bool $shortInfo,
		bool $isUser,
		mixed $id,
		bool $withCounters
	): array
	{
		$message = self::formatMessage($row, $options, $shortInfo);

		if ($shortInfo)
		{
			$item = [
				'ID' => $id,
				'CHAT_ID' => (int)$row['CHAT_ID'],
				'TYPE' => $isUser ? 'user' : 'chat',
				'MESSAGE' => $message,
				'PINNED' => $row['PINNED'] === 'Y',
				'UNREAD' => $row['UNREAD'] === 'Y',
				'DATE_LAST_ACTIVITY' => $row['DATE_LAST_ACTIVITY'],
			];
			if ($withCounters)
			{
				$item['COUNTER'] = (int)$row['COUNTER'];
			}

			return $item;
		}

		$item = [
			'ID' => $id,
			'CHAT_ID' => (int)$row['CHAT_ID'],
			'TYPE' => $isUser ? 'user' : 'chat',
			'AVATAR' => [],
			'TITLE' => [],
			'MESSAGE' => $message,
			'LAST_ID' => (int)($row['RELATION_LAST_ID'] ?? 0),
			'PINNED' => $row['PINNED'] === 'Y',
			'UNREAD' => $row['UNREAD'] === 'Y',
			'HAS_REMINDER' => isset($row['HAS_REMINDER']) && $row['HAS_REMINDER'] === 'Y',
			'DATE_UPDATE' => $row['DATE_UPDATE'],
			'DATE_LAST_ACTIVITY' => $row['DATE_LAST_ACTIVITY'],
		];
		if ($withCounters)
		{
			$item['COUNTER'] = (int)$row['COUNTER'];
		}

		return $item;
	}

	private static function formatChat(array $row, bool $shortInfo, int $generalChatId): array
	{
		$avatar = \CIMChat::GetAvatarImage($row['CHAT_AVATAR'], 200, false);
		$color = $row['CHAT_COLOR'] <> ''
			? Color::getColor($row['CHAT_COLOR'])
			: Color::getColorByNumber(
				$row['ITEM_ID']
			);
		$chatType = \Bitrix\Im\Chat::getType($row);

		if ($generalChatId == $row['ITEM_ID'])
		{
			$row["CHAT_ENTITY_TYPE"] = 'GENERAL';
		}

		$muteList = [];
		if ($row['RELATION_NOTIFY_BLOCK'] == 'Y')
		{
			$muteList = [$row['RELATION_USER_ID'] => true];
		}

		if ($shortInfo)
		{
			return [
				'ID' => (int)$row['ITEM_CID'],
				'NAME' => $row['CHAT_TITLE'],
				'EXTRANET' => $row['CHAT_EXTRANET'] == 'Y',
				'CONTAINS_COLLABER' => self::containsCollaber((int)$row['ITEM_CID']),
				'AVATAR' => $avatar,
				'COLOR' => $color,
				'TYPE' => $chatType,
				'ENTITY_TYPE' => (string)$row['CHAT_ENTITY_TYPE'],
				'MUTE_LIST' => $muteList,
				'ROLE' => self::getRole($row),
				'TEXT_FIELD_ENABLED' => self::getTextFieldEnabled((int)$row['ITEM_CID']),
				'BACKGROUND_ID' => self::getBackgroundId((int)$row['ITEM_CID']),
				'PERMISSIONS' => [
					'MANAGE_MESSAGES' => mb_strtolower($row['CHAT_CAN_POST'] ?? ''),
				],
			];
		}

		$publicOption = null;
		if ($row["CHAT_ENTITY_TYPE"] === \Bitrix\Im\V2\Chat::ENTITY_TYPE_VIDEOCONF)
		{
			$publicOption = \Bitrix\Im\V2\Chat::getInstance((int)$row['ITEM_CID'])->getPublicOption();
		}

		$managerList = [];
		if ($row['CHAT_OWNER'] ?? null == $row['RELATION_USER_ID'] || $row['RELATION_IS_MANAGER'] == 'Y')
		{
			$managerList = [(int)$row['RELATION_USER_ID']];
		}

		$chatOptions = \CIMChat::GetChatOptions();
		$restrictions = $chatOptions['DEFAULT'];
		if ($row['CHAT_ENTITY_TYPE'] && array_key_exists($row['CHAT_ENTITY_TYPE'], $chatOptions))
		{
			$restrictions = $chatOptions[$row['CHAT_ENTITY_TYPE']];
		}

		return [
			'ID' => (int)$row['ITEM_CID'],
			'PARENT_CHAT_ID' => (int)$row['CHAT_PARENT_ID'],
			'PARENT_MESSAGE_ID' => (int)$row['CHAT_PARENT_MID'],
			'NAME' => $row['CHAT_TITLE'],
			'OWNER' => (int)$row['CHAT_AUTHOR_ID'],
			'EXTRANET' => $row['CHAT_EXTRANET'] == 'Y',
			'CONTAINS_COLLABER' => self::containsCollaber((int)$row['ITEM_CID']),
			'AVATAR' => $avatar,
			'COLOR' => $color,
			'TYPE' => $chatType,
			'ENTITY_TYPE' => (string)$row['CHAT_ENTITY_TYPE'],
			'ENTITY_ID' => (string)$row['CHAT_ENTITY_ID'],
			'ENTITY_DATA_1' => (string)$row['CHAT_ENTITY_DATA_1'],
			'ENTITY_DATA_2' => (string)$row['CHAT_ENTITY_DATA_2'],
			'ENTITY_DATA_3' => (string)$row['CHAT_ENTITY_DATA_3'],
			'MUTE_LIST' => $muteList,
			'MANAGER_LIST' => $managerList,
			'DATE_CREATE' => $row['CHAT_DATE_CREATE'],
			'MESSAGE_TYPE' => $row["CHAT_TYPE"],
			'USER_COUNTER' => (int)$row['CHAT_USER_COUNT'],
			'RESTRICTIONS' => $restrictions,
			'ROLE' => self::getRole($row),
			'TEXT_FIELD_ENABLED' => self::getTextFieldEnabled((int)$row['ITEM_CID']),
			'BACKGROUND_ID' => self::getBackgroundId((int)$row['ITEM_CID']),
			'ENTITY_LINK' => EntityLink::getInstance(\CIMChat::initChatByArray($row))->toArray(),
			'PERMISSIONS' => [
				'MANAGE_USERS_ADD' => mb_strtolower($row['CHAT_MANAGE_USERS_ADD'] ?? ''),
				'MANAGE_USERS_DELETE' => mb_strtolower($row['CHAT_MANAGE_USERS_DELETE'] ?? ''),
				'MANAGE_UI' => mb_strtolower($row['CHAT_MANAGE_UI'] ?? ''),
				'MANAGE_SETTINGS' => mb_strtolower($row['CHAT_MANAGE_SETTINGS'] ?? ''),
				'MANAGE_MESSAGES' => mb_strtolower($row['CHAT_CAN_POST'] ?? ''),
				'CAN_POST' => mb_strtolower($row['CHAT_CAN_POST'] ?? ''),
			],
			'PUBLIC' => $publicOption ?? '',
		];
	}

	private static function containsCollaber(int $chatId): bool
	{
		if ($chatId <= 0)
		{
			return false;
		}

		$paramsService = Params::getInstance($chatId);

		return (bool)$paramsService->get(Params::CONTAINS_COLLABER)?->getValue();
	}

	private static function getTextFieldEnabled(int $chatId): bool
	{
		return (new TextFieldEnabled($chatId))->get();
	}

	private static function getBackgroundId(int $chatId): ?string
	{
		return (new Background($chatId))->get();
	}

	private static function getChatMessagesAutoDeleteConfigs(int $chatId): array
	{
		$config = (new MessagesAutoDeleteConfigs([$chatId]))->toRestFormat();

		return (new Converter(Converter::TO_SNAKE | Converter::TO_UPPER | Converter::KEYS))->process($config);
	}

	private static function formatUser(array $row, array $item, bool $shortInfo): ?array
	{
		$userObject = \Bitrix\Im\V2\Entity\User\User::getInstance($item['USER']['ID']);
		if ($userObject instanceof NullUser)
		{
			return [];
		}

		$user = $userObject->getArray(['WITHOUT_ONLINE' => true, 'USER_SHORT_FORMAT' => $shortInfo]);

		if ($shortInfo)
		{
			if (!$userObject->isActive())
			{
				return null;
			}

			return $user;
		}

		if ($item['TYPE'] == 'user')
		{
			if (
				!empty($user['BOT_DATA'])
				&& Loader::includeModule('imbot')
				&& $user['BOT_DATA']['code'] === CopilotChatBot::BOT_CODE
			)
			{
				return null;
			}

			if (
				(!$user['ACTIVE'] && (int)$row['COUNTER'] <= 0 && !$item['UNREAD'])
				&& !$user['BOT']
				&& !$user['CONNECTOR']
				&& !$user['NETWORK']
			)
			{
				return null;
			}
		}

		if ($item['TYPE'] == 'user')
		{
			$lastActivityDate = $row['USER_LAST_ACTIVITY_DATE'] ?? null;
		}
		else
		{
			$lastActivityDate = $row['MESSAGE_USER_LAST_ACTIVITY_DATE'] ?? null;
		}

		$user['LAST_ACTIVITY_DATE'] = $lastActivityDate ?: false;
		$user['DESKTOP_LAST_DATE'] = false;
		$user['MOBILE_LAST_DATE'] = false;
		$user['IDLE'] = false;

		return $user;
	}

	private static function jsonRow($item)
	{
		if (!is_array($item))
		{
			return $item;
		}

		foreach ($item as $key => $value)
		{
			if ($value instanceof \Bitrix\Main\Type\DateTime)
			{
				$item[$key] = date('c', $value->getTimestamp());
			}
			else if (is_array($value))
			{
				foreach ($value as $subKey => $subValue)
				{
					if ($subValue instanceof \Bitrix\Main\Type\DateTime)
					{
						$value[$subKey] = date('c', $subValue->getTimestamp());
					}
					else if (
						is_string($subValue)
						&& $subValue
						&& in_array($subKey, ['URL', 'AVATAR'])
						&& mb_strpos($subValue, 'http') !== 0
					)
					{
						$value[$subKey] = \Bitrix\Im\Common::getPublicDomain().$subValue;
					}
					else if (is_array($subValue))
					{
						$value[$subKey] = array_change_key_case($subValue, CASE_LOWER);
					}
				}
				$item[$key] = array_change_key_case($value, CASE_LOWER);
			}
		}

		return array_change_key_case($item, CASE_LOWER);
	}

	public static function pin($dialogId, $pin, $userId = null)
	{
		$userId = \Bitrix\Im\Common::getUserId($userId);
		if (!$userId)
		{
			return false;
		}

		self::$limitError = false;

		if (mb_substr((string)$dialogId, 0, 4) === 'chat')
		{
			$chatId = (int)mb_substr((string)$dialogId, 4);
		}
		else
		{
			$chatId = (int)\Bitrix\Im\Dialog::getChatId($dialogId, $userId);
		}

		if ($chatId <= 0)
		{
			return false;
		}

		$chat = \Bitrix\Im\V2\Chat::getInstance($chatId);
		if (!$chat || !$chat->getChatId())
		{
			return false;
		}

		$service = ServiceLocator::getInstance()->get(\Bitrix\Im\V2\Folder\Pin\PinService::class);

		// folderId=null → legacy global path inside Pin\PinService:
		// PINNED='Y'/'N' + shadow fan-out across matching folders + single chatPin pull.
		$result = $pin
			? $service->pinChat((int)$userId, $chat, null)
			: $service->unpinChat((int)$userId, $chat, null);

		if (!$result->isSuccess())
		{
			foreach ($result->getErrors() as $error)
			{
				if ($error->getCode() === \Bitrix\Im\V2\Folder\Error\FolderError::FOLDER_PINS_LIMIT_EXCEEDED)
				{
					self::$limitError = true;
					break;
				}
			}

			return false;
		}

		return true;
	}

	private static function increasePinSortCost(int $userId): void
	{
		$caseField = new SqlExpression('?# + 1', 'PIN_SORT');

		RecentTable::updateByFilter(
			[
				'=PINNED' => 'Y',
				'=USER_ID' => $userId,
				'>=PIN_SORT' => 1,
			],
			['PIN_SORT' => $caseField]
		);
	}

	private static function decreasePinSortCost(int $userId, ?int $pinSort)
	{
		if (!isset($pinSort))
		{
			return;
		}

		$caseField = new SqlExpression('?# - 1', 'PIN_SORT');

		RecentTable::updateByFilter(
			[
				'=PINNED' => 'Y',
				'=USER_ID' => $userId,
				'>PIN_SORT' => $pinSort,
			],
			['PIN_SORT' => $caseField]
		);
	}

	public static function sortPin(\Bitrix\Im\V2\Chat $chat, int $newPosition, int $userId): void
	{
		$connection = Application::getConnection();
		$connection->lock("PIN_SORT_CHAT_{$userId}", 10);

		$query = RecentTable::query()
			->setSelect(['PIN_SORT'])
			->setLimit(1)
			->where('USER_ID', $userId)
			->where('ITEM_CID', (int)$chat->getChatId())
			->where('PINNED', 'Y')
			->fetch()
		;

		if (!$query)
		{
			$connection->unlock("PIN_SORT_CHAT_{$userId}");

			return;
		}
		$currentCost = (int)$query['PIN_SORT'];

		$query = RecentTable::query()
			->setSelect(['PIN_SORT'])
			->setOrder(['PIN_SORT'])
			->setOffset($newPosition - 1)
			->setLimit(1)
			->where('PINNED', 'Y')
			->where('USER_ID', $userId)
			->fetch()
		;

		if (!$query)
		{
			$connection->unlock("PIN_SORT_CHAT_{$userId}");

			return;
		}
		$newCost = (int)$query['PIN_SORT'];

		if ($currentCost === $newCost)
		{
			$connection->unlock("PIN_SORT_CHAT_{$userId}");

			return;
		}

		if ($currentCost < $newCost)
		{
			$caseField = new SqlExpression(
				"CASE WHEN ?# = ?i THEN ?i WHEN ?# > ?i AND ?# <= ?i THEN ?# - 1 END",
				'PIN_SORT',
				$currentCost,
				$newCost,
				'PIN_SORT',
				$currentCost,
				'PIN_SORT',
				$newCost,
				'PIN_SORT'
			);

			$filter = [
				'=PINNED' => 'Y',
				'=USER_ID' => $userId,
				'>=PIN_SORT' => $currentCost,
				'<=PIN_SORT' => $newCost,
			];
		}
		else
		{
			$caseField = new SqlExpression(
				"CASE WHEN ?# = ?i THEN ?i WHEN ?# >= ?i AND ?# < ?i THEN ?# + 1 END",
				'PIN_SORT',
				$currentCost,
				$newCost,
				'PIN_SORT',
				$newCost,
				'PIN_SORT',
				$currentCost,
				'PIN_SORT'
			);

			$filter = [
				'=PINNED' => 'Y',
				'=USER_ID' => $userId,
				'>=PIN_SORT' => $newCost,
				'<=PIN_SORT' => $currentCost,
			];
		}

		RecentTable::updateByFilter(
			$filter,
			['PIN_SORT' => $caseField]
		);

		$connection->unlock("PIN_SORT_CHAT_{$userId}");
	}

	public static function getPinLimit(): int
	{
		return self::PINNED_CHATS_LIMIT ?? 25;
	}

	public static function updatePinSortCost(int $userId): void
	{
		$connection = Application::getConnection();
		$connection->lock("PIN_SORT_CHAT_{$userId}", 10);

		$caseField = new SqlExpression('?#', 'ITEM_MID');

		RecentTable::updateByFilter(
			[
				'=PINNED' => 'Y',
				'=USER_ID' => $userId
			],
			['PIN_SORT' => $caseField]
		);

		$connection->unlock("PIN_SORT_CHAT_{$userId}");
	}

	public static function updateByFilter(array $filter, array $fields): void
	{
		RecentTable::updateByFilter($filter, $fields);
	}

	public static function raiseChat(
		\Bitrix\Im\V2\Chat $chat,
		RelationCollection $relations,
		?DateTime $lastActivity = null,
		int $depth = 0,
		?CollabPreviewSourcePiggyback $previewSource = null
	): void
	{
		$userIds = $relations->getUserIds();
		if (empty($userIds))
		{
			return;
		}
		$message = new Message($chat->getLastMessageId());
		$dateMessage = $message->getDateCreate() ?? new DateTime();
		$dateCreate = $lastActivity ?? $dateMessage;
		$fields = [];

		// When this raised level is the collab parent the message belongs to, piggyback the preview-source
		// pointer onto the same MERGE: into both the insert fields and the update set (the collab row almost
		// always already exists, so the update set is what maintains the pointer on send).
		$previewUpdate = [];
		if ($previewSource !== null && $previewSource->appliesTo((int)$chat->getId()))
		{
			$previewUpdate = $previewSource->getColumns() ?? [];
		}

		foreach ($relations as $relation)
		{
			$userId = $relation->getUserId();
			if ($userId)
			{
				$fields[] = [
					'USER_ID' => $userId,
					'ITEM_TYPE' => $chat->getType(),
					'ITEM_ID' => $chat->getId(), // Todo: invalid ITEM_ID for PrivateChat
					'ITEM_MID' => $chat->getLastMessageId(),
					'ITEM_CID' => $chat->getId(),
					'ITEM_RID' => $relation->getId(),
					'DATE_MESSAGE' => $dateMessage,
					'DATE_UPDATE' => $dateCreate,
					'DATE_LAST_ACTIVITY' => $dateCreate,
				] + $previewUpdate;
			}
		}

		static::merge(
			$fields,
			['DATE_LAST_ACTIVITY' => $dateCreate, 'DATE_UPDATE' => $dateCreate] + $previewUpdate
		);

		// raiseChat writes RecentTable directly via multiplyMerge and bypasses addRecent, so the
		// request-scoped RecentItemCache would keep stale RecentItems for the affected users/chat.
		$recentItemCache = ServiceLocator::getInstance()->get(\Bitrix\Im\V2\Recent\Internal\RecentItemCache::class);
		foreach ($userIds as $affectedUserId)
		{
			$recentItemCache->remove((int)$affectedUserId, $chat->getId());
		}

		Sync\Logger::getInstance()->add(
			new Sync\Event(Sync\Event::ADD_EVENT, Sync\Event::CHAT_ENTITY, $chat->getId()),
			$userIds,
			$chat
		);

		// Pass the in-process direct-send hint to RecentUpdate only at the collab parent level, so it builds
		// the preview from the on-hand source objects and skips the per-recipient DB re-hydration. Other
		// levels get no hint and keep the existing DB read path.
		$levelPreviewHint =
			$previewSource !== null
			&& $previewSource->appliesTo((int)$chat->getId())
			&& $previewSource->hasObjectHint()
				? $previewSource
				: null
		;

		(new RecentUpdate($chat, $userIds, $dateCreate, $levelPreviewHint))->send();

		if ($chat->hasParent() && $depth < self::MAX_RAISE_DEPTH)
		{
			$parentChat = $chat->getParentChat();
			$parentRelations = $parentChat->getRelationsByUserIds($userIds);
			static::raiseChat($parentChat, $parentRelations, $lastActivity, $depth + 1, $previewSource);
		}
	}

	public static function addRecent(\Bitrix\Im\V2\Chat $chat, Relation $relation, ?DateTime $lastActivity = null): void
	{
		$userId = $relation->getUserId();
		if (!$userId)
		{
			return;
		}

		$message = new Message($chat->getLastMessageId());
		$dateMessage = $message->getDateCreate() ?? new DateTime();
		$dateCreate = $lastActivity ?? $dateMessage;

		$itemId = $chat->getChatId();
		if ($chat instanceof PrivateChat)
		{
			$itemId = $chat->getCompanionId($userId);
		}

		static::merge(
			[[
				'USER_ID' => $userId,
				'ITEM_TYPE' => $chat->getType(),
				'ITEM_ID' => $itemId,
				'ITEM_MID' => $chat->getLastMessageId(),
				'ITEM_CID' => $chat->getId(),
				'ITEM_RID' => $relation->getId(),
				'DATE_MESSAGE' => $dateMessage,
				'DATE_UPDATE' => $dateCreate,
				'DATE_LAST_ACTIVITY' => $dateCreate,
			]],
			[
				'ITEM_MID' => $chat->getLastMessageId(),
				'ITEM_CID' => $chat->getId(),
				'ITEM_RID' => $relation->getId(),
				'DATE_MESSAGE' => $dateMessage,
				'DATE_LAST_ACTIVITY' => $dateCreate,
				'DATE_UPDATE' => $dateCreate
			],
		);

		\Bitrix\Main\DI\ServiceLocator::getInstance()
			->get(\Bitrix\Im\V2\Recent\Internal\RecentItemCache::class)
			->remove($userId, $chat->getId())
		;

		Sync\Logger::getInstance()->add(
			new Sync\Event(Sync\Event::ADD_EVENT, Sync\Event::CHAT_ENTITY, $chat->getId()),
			[$userId],
			$chat
		);
	}

	public static function merge(array $fields, array $update): void
	{
		RecentTable::multiplyMerge($fields, $update, ['USER_ID', 'ITEM_TYPE', 'ITEM_ID']);
	}

	public static function getUsersOutOfRecent(\Bitrix\Im\V2\Chat $chat): array
	{
		$relations = $chat->getUsersToNotify();
		$users = $relations->getUserIds();
		$usersAlreadyInRecentRows = RecentTable::query()
			->setSelect(['USER_ID'])
			->where('ITEM_CID', $chat->getId())
			->whereIn('USER_ID', $users)
			->fetchAll()
		;
		foreach ($usersAlreadyInRecentRows as $row)
		{
			$userId = (int)$row['USER_ID'];
			unset($users[$userId]);
		}

		return $users;
	}

	public static function unread($dialogId, $unread, $userId = null, ?int $markedId = null, ?string $itemTypes = null)
	{
		$userId = \Bitrix\Im\Common::getUserId($userId);
		if (!$userId)
		{
			return false;
		}

		$chatId = Dialog::getChatId($dialogId, $userId);
		$reader = ServiceLocator::getInstance()->get(RecentReader::class);
		$result =
			$unread
				? $reader->unread($userId, $chatId, $markedId ?? 0)
				: $reader->read($userId, $chatId)
		;

		return $result->isSuccess();
	}

	public static function isUnread(int $userId, string $itemType, string $dialogId): bool
	{
		$id = mb_strpos($dialogId, 'chat') === 0 ? mb_substr($dialogId, 4) : $dialogId;
		$element = \Bitrix\Im\Model\RecentTable::getList([
			'select' => ['USER_ID', 'ITEM_TYPE', 'ITEM_ID', 'UNREAD', 'MUTED' => 'RELATION.NOTIFY_BLOCK', 'ITEM_CID'],
			'filter' => [
				'=USER_ID' => $userId,
				'=ITEM_TYPE' => $itemType,
				'=ITEM_ID' => $id
			]
		])->fetch();
		if (!$element)
		{
			return false;
		}

		return ($element['UNREAD'] ?? 'N') === 'Y';
	}

	public static function getUnread(string $itemType, string $dialogId): array
	{
		$id = mb_strpos($dialogId, 'chat') === 0 ? mb_substr($dialogId, 4) : $dialogId;
		$queryResult = \Bitrix\Im\Model\RecentTable::getList([
			'select' => ['USER_ID', 'UNREAD',],
			'filter' => [
				'=ITEM_TYPE' => $itemType,
				'=ITEM_ID' => $id
			]
		])->fetchAll();

		$result = [];

		foreach ($queryResult as $row)
		{
			$result[(int)$row['USER_ID']] = ($row['UNREAD'] ?? 'N') === 'Y';
		}

		return $result;
	}

	public static function getMarkedId(int $userId, string $itemType, string $dialogId): int
	{
		$id = mb_strpos($dialogId, 'chat') === 0 ? mb_substr($dialogId, 4) : $dialogId;
		$element = \Bitrix\Im\Model\RecentTable::getList([
			'select' => ['MARKED_ID'],
			'filter' => [
				'=USER_ID' => $userId,
				'=ITEM_TYPE' => $itemType,
				'=ITEM_ID' => $id
			]
		])->fetch();
		if (!$element)
		{
			return 0;
		}

		return (int)($element['MARKED_ID'] ?? 0);
	}

	public static function getMarkedIdByChatIds(int $userId, array $chatIds): array
	{
		if (empty($chatIds))
		{
			return [];
		}

		$markedIdByChatIds = [];

		$result = RecentTable::query()
			->setSelect(['ITEM_CID', 'MARKED_ID'])
			->where('USER_ID', $userId)
			->whereIn('ITEM_CID', $chatIds)
			->fetchAll()
		;

		foreach ($result as $row)
		{
			$markedIdByChatIds[(int)$row['ITEM_CID']] = (int)$row['MARKED_ID'];
		}

		return $markedIdByChatIds;
	}

	public static function hide($dialogId, $userId = null)
	{
		return \CIMContactList::DialogHide($dialogId, $userId);
	}

	public static function show($dialogId, $options = [], $userId = null)
	{
		$userId = Common::getUserId($userId);
		if (!$userId)
		{
			return false;
		}

		$chatId = Dialog::getChatId($dialogId, $userId);
		if (Common::isChatId($dialogId))
		{
			$entityId = $chatId;
		}
		else
		{
			$entityId = (int)$dialogId;
		}

		$relation = \Bitrix\Im\Model\RelationTable::getList([
			'select' => [
				'ID',
				'TYPE' => 'CHAT.TYPE',
				'LAST_MESSAGE_ID' => 'CHAT.LAST_MESSAGE_ID',
				'LAST_MESSAGE_DATE' => 'MESSAGE.DATE_CREATE'
			],
			'filter' => [
				'=CHAT_ID' => $chatId,
				'=USER_ID' => $userId
			],
			'runtime' => [
				new \Bitrix\Main\Entity\ReferenceField(
					'MESSAGE',
					'\Bitrix\Im\Model\MessageTable',
					["=ref.ID" => "this.CHAT.LAST_MESSAGE_ID"],
					["join_type" => "LEFT"]
				),
			]
		])->fetch();

		if ($relation)
		{
			$relationId = $relation['ID'];
			$entityType = $relation['TYPE'];
			$messageId = $relation['LAST_MESSAGE_ID'];
			$messageDate = $relation['LAST_MESSAGE_DATE'];
		}
		else if (
			isset($options['CHAT_DATA']['TYPE'])
			&& isset($options['CHAT_DATA']['LAST_MESSAGE_ID'])
		)
		{
			$relationId = 0;
			$entityType = $options['CHAT_DATA']['TYPE'];
			$messageId = $options['CHAT_DATA']['LAST_MESSAGE_ID'];
			$messageDate = $options['CHAT_DATA']['LAST_MESSAGE_DATE'];
		}
		else
		{
			$chat = \Bitrix\Im\Model\ChatTable::getList([
				'select' => [
					'TYPE',
					'LAST_MESSAGE_ID',
					'LAST_MESSAGE_DATE' => 'MESSAGE.DATE_CREATE'
				],
				'filter' => [
					'=ID' => $chatId,
				],
				'runtime' => [
					new \Bitrix\Main\Entity\ReferenceField(
						'MESSAGE',
						'\Bitrix\Im\Model\MessageTable',
						["=ref.ID" => "this.LAST_MESSAGE_ID"],
						["join_type" => "LEFT"]
					),
				]
			])->fetch();
			if (!$chat)
			{
				return false;
			}

			$relationId = 0;
			$entityType = $chat['TYPE'];
			$messageId = $chat['LAST_MESSAGE_ID'];
			$messageDate = $chat['LAST_MESSAGE_DATE'];
		}

		$sessionId = 0;
		if ($entityType == IM_MESSAGE_OPEN_LINE)
		{
			if (isset($options['SESSION_ID']))
			{
				$sessionId = (int)$options['SESSION_ID'];
			}
			else if (\Bitrix\Main\Loader::includeModule('imopenlines'))
			{
				$session = \Bitrix\ImOpenLines\Model\SessionTable::getList([
					'select' => ['ID'],
					'filter' => ['=CHAT_ID' => $chatId],
					'order' => ['ID' => 'DESC'],
					'limit' => 1,
				])->fetch();
				if ($session)
				{
					$sessionId = $session['ID'];
				}
			}
		}

		\CIMContactList::SetRecent($temp = [
			'ENTITY_TYPE' => $entityType,
			'ENTITY_ID' => $entityId,
			'MESSAGE_ID' => $messageId,
			'MESSAGE_DATE' => $messageDate,
			'CHAT_ID' => $chatId,
			'RELATION_ID' => $relationId,
			'SESSION_ID' => $sessionId,
			'USER_ID' => $userId,
		]);

		if (!\Bitrix\Main\Loader::includeModule("pull"))
		{
			return true;
		}

		$data = \Bitrix\Im\Recent::getElement($entityType, $entityId, $userId, ['JSON' => true]);
		if ($data)
		{
			if (
				!isset($data['message'])
				&& $entityType === Chat::TYPE_OPEN_LINE
				&& class_exists('\Bitrix\ImOpenLines\Recent')
			)
			{
				$data = \Bitrix\ImOpenLines\Recent::getElement(
					(int)$entityId,
					(int)$userId,
					[
						'JSON' => true,
						'fakeCounter' => 1
					]
				);
			}
			Event::add($userId, [
				'module_id' => 'im',
				'command' => 'chatShow',
				'params' => $data,
				'extra' => \Bitrix\Im\Common::getPullExtra()
			]);
		}

		return true;
	}

	public static function clearCache($userId = null)
	{
		$cache = Application::getInstance()->getCache();
		$cache->cleanDir('/bx/imc/recent'.($userId ? Common::getCacheUserPostfix($userId) : ''));
	}

	protected static function prepareRows(array $rows, int $userId, bool $shortInfo = false, bool $withCounters = true): array
	{
		[$messageIds, $chatIds] = self::getKeysForFetchAdditionalEntities($rows);
		$counters = ChatsCounterMap::fromArray([]);
		if ($withCounters)
		{
			$counters = ServiceLocator::getInstance()->get(CountersProvider::class)->getForUserByChatIds($userId, $chatIds);
		}
		elseif (!$shortInfo)
		{
			$inactiveChatIds = self::getInactiveChatIds($rows);
			$counters =
				ServiceLocator::getInstance()
					->get(CountersProvider::class)
					->getForUserByChatIds($userId, $inactiveChatIds)
			;
		}
		$params = $shortInfo ? [] : self::getMessageParams($messageIds);

		return self::fillRows($rows, $params, $counters, $userId);
	}

	protected static function getKeysForFetchAdditionalEntities(array $rows): array
	{
		$messageIds = [];
		$chatIds = [];

		foreach ($rows as $row)
		{
			if (isset($row['ITEM_MID']) && $row['ITEM_MID'] > 0)
			{
				$messageIds[] = (int)$row['ITEM_MID'];
			}

			if (isset($row['ITEM_CID']) && $row['ITEM_CID'] > 0)
			{
				$chatIds[] = (int)$row['ITEM_CID'];
			}
		}

		return [$messageIds, $chatIds];
	}

	/**
	 * @param array $rows
	 * @return array
	 */
	protected static function getInactiveChatIds(array $rows): array
	{
		$inactiveChatIds = [];

		foreach ($rows as $row)
		{
			if (
				isset($row['CHAT_ID']) && $row['CHAT_ID'] > 0
				&& isset($row['USER_ACTIVE']) && $row['USER_ACTIVE'] === 'N'
			)
			{
				$inactiveChatIds[] = (int)$row['CHAT_ID'];
			}
		}

		return $inactiveChatIds;
	}

	protected static function getMessageParams(array $messageIds): array
	{
		$result = [];
		$fileIds = [];

		if (empty($messageIds))
		{
			return $result;
		}

		$rows = MessageParamTable::query()
			->setSelect(['*'])
			->whereIn('MESSAGE_ID', $messageIds)
			->exec()
		;

		foreach ($rows as $item)
		{
			$messageId = (int)$item['MESSAGE_ID'];
			$paramName = $item['PARAM_NAME'];

			if ($paramName === 'STICKER_PARAMS')
			{
				$result[$messageId]['STICKER'] = $item['PARAM_JSON'] ?? null;
			}

			if ($paramName === 'CODE')
			{
				$result[$messageId]['CODE'] = $item['PARAM_VALUE'];
			}
			elseif ($paramName === 'ATTACH')
			{
				$result[$messageId]['ATTACH'] = [
					'VALUE' => $item['PARAM_VALUE'],
					'JSON' => $item['PARAM_JSON'],
				];
			}
			elseif ($paramName === 'URL_ID')
			{
				$result[$messageId]['ATTACH'] = [
					'VALUE' => "",
					'JSON' => true,
				];
			}
			elseif ($paramName === 'FILE_ID')
			{
				$fileIds[$messageId] = (int)$item['PARAM_VALUE'];
				$result[$messageId]['MESSAGE_FILE'] = true;
			}
			elseif ($paramName === 'BLOCK')
			{
				$result[$messageId]['BLOCK'] = true;
			}
		}

		return self::fillFiles($result, $fileIds);
	}

	protected static function fillFiles(array $params, array $fileIds): array
	{
		if (empty($fileIds))
		{
			return $params;
		}

		if (Settings::isLegacyChatActivated())
		{
			return $params;
		}

		$files = FileCollection::initByDiskFilesIds($fileIds);

		foreach ($fileIds as $messageId => $fileId)
		{
			$file = $files->getById($fileId);
			if (!$file instanceof FileItem)
			{
				$params[$messageId]['MESSAGE_FILE'] = false;
			}
			else
			{
				$params[$messageId]['MESSAGE_FILE'] = [
					'ID' => $file->getId(),
					'TYPE' => $file->getContentType(),
					'NAME' => $file->getDiskFile()->getName(),
				];
			}
		}

		return $params;
	}

	protected static function fillRows(array $rows, array $params, ChatsCounterMap $counters, int $userId): array
	{
		foreach ($rows as $key => $row)
		{
			$chatId = (int)($row['ITEM_CID'] ?? 0);
			$messageId = (int)($row['ITEM_MID'] ?? 0);
			$boolStatus = $row['CHAT_LAST_MESSAGE_STATUS_BOOL'] ?? 'N';

			$rows[$key]['COUNTER'] = $counters->getByChatId($chatId);
			$rows[$key]['CHAT_LAST_MESSAGE_STATUS'] = $boolStatus === 'Y' ? \IM_MESSAGE_STATUS_DELIVERED : \IM_MESSAGE_STATUS_RECEIVED;
			$rows[$key]['MESSAGE_CODE'] = $rows[$key]['MESSAGE_CODE'] ?? $params[$messageId]['CODE'] ?? null;
			$rows[$key]['MESSAGE_ATTACH'] = $params[$messageId]['ATTACH']['VALUE'] ?? null;
			$rows[$key]['MESSAGE_ATTACH_JSON'] = $params[$messageId]['ATTACH']['JSON'] ?? null;
			$rows[$key]['MESSAGE_FILE'] = $params[$messageId]['MESSAGE_FILE'] ?? false;
			$rows[$key]['RELATION_USER_ID'] = $row['RELATION_ID'] ? $userId : null;
			$rows[$key]['MESSAGE_STICKER'] = $params[$messageId]['STICKER'] ?? null;
			$rows[$key]['BLOCK'] = $params[$messageId]['BLOCK'] ?? false;
		}

		return $rows;
	}

	/**
	 * @see \Bitrix\Im\V2\Chat::getRole()
	 * @param array $row
	 * @return string
	 */
	protected static function getRole(array $row): string
	{
		if (!isset($row['RELATION_USER_ID']))
		{
			return \Bitrix\Im\V2\Chat::ROLE_GUEST;
		}
		if ((int)$row['CHAT_AUTHOR_ID'] === (int)$row['RELATION_USER_ID'])
		{
			return \Bitrix\Im\V2\Chat::ROLE_OWNER;
		}
		if ($row['RELATION_IS_MANAGER'] === 'Y')
		{
			return \Bitrix\Im\V2\Chat::ROLE_MANAGER;
		}

		return \Bitrix\Im\V2\Chat::ROLE_MEMBER;
	}

	public static function isLimitError(): bool
	{
		return self::$limitError;
	}

	private static function getStickerParams(?string $stickerData): ?array
	{
		if (empty($stickerData))
		{
			return null;
		}

		$sticker = null;

		try
		{
			$sticker = Json::decode($stickerData);
		}
		catch (\Bitrix\Main\SystemException $e)
		{}

		return is_array($sticker) ? $sticker : null;
	}
}
