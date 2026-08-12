<?php

namespace Bitrix\Im\V2\Recent;

use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Entity\User\UserPopupItem;
use Bitrix\Im\V2\Message\CounterService;
use Bitrix\Im\V2\Message\MessagePopupItem;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Recent\Query\RecentParams;
use Bitrix\Im\V2\Registry;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Rest\PopupDataAggregatable;
use Bitrix\Im\V2\Rest\PopupDataItem;
use Bitrix\Im\V2\Settings\UserConfiguration;

/**
 * @extends Registry<RecentItem>
 */
class Recent extends Registry implements PopupDataAggregatable, PopupDataItem
{
	use ContextCustomer;

	public function getPopupData(array $excludedList = []): PopupData
	{
		$messageIds = [];
		$chats = [];
		$chatIds = [];
		$userIds = [];

		foreach ($this as $item)
		{
			$messageIds[] = $item->getMessageId();
			$chats[] = Chat::getInstance($item->getChatId());
			$chatIds[] = $item->getChatId();

			$ownMessageId = $item->getOwnMessageId();
			if ($ownMessageId > 0 && $ownMessageId !== $item->getMessageId())
			{
				$messageIds[] = $ownMessageId;
			}

			$sourceChatId = $item->getSourceChatId();
			if ($sourceChatId > 0 && $sourceChatId !== $item->getChatId())
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
		], $excludedList);
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

		$query->setSelect([
			'ITEM_CID',
			'ITEM_MID',
			'UNREAD',
			'PINNED',
			'DATE_LAST_ACTIVITY',
			'DATE_UPDATE',
			'RELATION.LAST_ID',
			'PREVIEW_SOURCE_CID',
			'PREVIEW_SOURCE_MID',
		]);

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
