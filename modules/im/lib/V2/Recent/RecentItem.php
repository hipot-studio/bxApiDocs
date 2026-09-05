<?php

namespace Bitrix\Im\V2\Recent;

use Bitrix\Im\Model\EO_Recent;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Rest\RestConvertible;
use Bitrix\Main\Type\DateTime;

class RecentItem implements RestConvertible
{
	protected string $dialogId;
	protected int $chatId;
	protected int $messageId;
	protected int $id = 0;
	protected RecentType $type = RecentType::Chat;
	protected int $lastReadMessageId = 0;
	protected int $markedId = 0;
	protected bool $pinned = false;
	protected bool $unread = false;
	protected ?DateTime $dateUpdate = null;
	protected ?DateTime $dateLastActivity = null;
	protected array $options = [];
	protected array $invited = [];
	// Internal only, not serialized: which source chat to add to the normalized chats[] collection.
	protected int $sourceChatId = 0;
	// Preview-source pointers loaded verbatim from the recent row. A zero value means the source is the row itself.
	protected int $previewSourceCid = 0;
	protected int $previewSourceMid = 0;
	// The row's own last message id, captured before the read-path overlay may redirect $messageId to a preview source.
	protected int $ownMessageId = 0;

	public static function initByEntity(EO_Recent $entity): self
	{
		$recentItem = new static();
		// Derive fields from the entity's own data (see initByArray): type from ITEM_TYPE, a user-type item resolves to
		// the companion (ITEM_ID); any chat-type item resolves to 'chat'.ITEM_CID, id 0.
		$type = RecentType::tryFromChatType($entity->getItemType());
		$isUser = $type === RecentType::User;
		$recentItem
			->setMessageId($entity->getItemMid())
			->setOwnMessageId($entity->getItemMid())
			->setChatId($entity->getItemCid())
			->setDialogId($isUser ? self::formDialogId($entity->getItemId(), $entity->getItemType()) : 'chat' . $entity->getItemCid())
			->setType($type)
			->setId($isUser ? $entity->getItemId() : 0)
			->setUnread($entity->getUnread())
			->setPinned($entity->getPinned())
			->setLastReadMessageId((int)$entity->getRelation()?->getLastId())
			->setDateUpdate($entity->getDateUpdate())
			->setDateLastActivity($entity->getDateLastActivity())
			->setPreviewSourceCid((int)$entity->getPreviewSourceCid())
			->setPreviewSourceMid((int)$entity->getPreviewSourceMid())
		;

		return $recentItem;
	}

	public static function initByArray(array $entity): self
	{
		$recentItem = new static();

		// Derive dialogId/type/id from the row's own data when we have it (ITEM_TYPE + ITEM_ID present); otherwise fall
		// back to the exact pre-existing (master) behavior. type is derived from ITEM_TYPE for EVERY type via
		// RecentType::tryFromChatType (private -> User, all others -> Chat). Only a user-type item resolves to a person:
		// dialogId = companion userId (ITEM_ID; self-chat -> own userId), id = ITEM_ID. Any chat-type item — and any
		// source lacking ITEM_TYPE/ITEM_ID (sibling fetches / RecentChannel) — resolves to dialogId = 'chat'.ITEM_CID
		// (chatId, NOT ITEM_ID: no dependency on the ITEM_ID == chatId invariant, so open lines/channels/collabs are
		// byte-identical to master), type = Chat, id = 0.
		// NOTE: id is not serialized — it only feeds Recent::getPopupData's User branch (companion hydration).
		$type = isset($entity['ITEM_TYPE'], $entity['ITEM_ID'])
			? RecentType::tryFromChatType((string)$entity['ITEM_TYPE'])
			: RecentType::Chat;
		$isUser = $type === RecentType::User;

		$recentItem
			->setMessageId($entity["ITEM_MID"] ?? 0)
			->setOwnMessageId($entity["ITEM_MID"] ?? 0)
			->setChatId($entity["ITEM_CID"] ?? 0)
			->setDialogId($isUser ? self::formDialogId((int)$entity['ITEM_ID'], (string)$entity['ITEM_TYPE']) : 'chat' . $entity["ITEM_CID"])
			->setType($type)
			->setId($isUser ? (int)$entity['ITEM_ID'] : 0)
			->setUnread($entity["UNREAD"] === 'Y')
			->setPinned($entity["PINNED"] === 'Y')
			->setLastReadMessageId((int)$entity['RELATION.LAST_ID'])
			->setDateUpdate($entity['DATE_UPDATE'])
			->setDateLastActivity($entity['DATE_LAST_ACTIVITY'])
			->setPreviewSourceCid((int)($entity['PREVIEW_SOURCE_CID'] ?? 0))
			->setPreviewSourceMid((int)($entity['PREVIEW_SOURCE_MID'] ?? 0))
		;

		return $recentItem;
	}

	public function getDialogId(): string
	{
		return $this->dialogId;
	}

	public function setDialogId(string $dialogId): RecentItem
	{
		$this->dialogId = $dialogId;
		return $this;
	}

	public function getChatId(): int
	{
		return $this->chatId;
	}

	public function setChatId(int $chatId): RecentItem
	{
		$this->chatId = $chatId;
		return $this;
	}

	public function getMessageId(): int
	{
		return $this->messageId;
	}

	public function setMessageId(int $messageId): RecentItem
	{
		$this->messageId = $messageId;
		return $this;
	}

	public function getOwnMessageId(): int
	{
		return $this->ownMessageId;
	}

	public function setOwnMessageId(int $ownMessageId): RecentItem
	{
		$this->ownMessageId = $ownMessageId;
		return $this;
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function setId(int $id): RecentItem
	{
		$this->id = $id;
		return $this;
	}

	public function getType(): RecentType
	{
		return $this->type;
	}

	public function setType(RecentType $type): RecentItem
	{
		$this->type = $type;
		return $this;
	}

	public function isPinned(): bool
	{
		return $this->pinned;
	}

	public function setPinned(bool $pinned): RecentItem
	{
		$this->pinned = $pinned;
		return $this;
	}

	public function isUnread(): bool
	{
		return $this->unread;
	}

	public function setUnread(bool $unread): RecentItem
	{
		$this->unread = $unread;
		return $this;
	}

	public function getOptions(): array
	{
		return $this->options;
	}

	public function setOptions(array $options): RecentItem
	{
		$this->options = $options;
		return $this;
	}

	public function getInvited(): array
	{
		return $this->invited;
	}

	public function setInvited(array $invited): RecentItem
	{
		$this->invited = $invited;
		return $this;
	}

	public function getLastReadMessageId(): int
	{
		return $this->lastReadMessageId;
	}

	public function setLastReadMessageId(int $lastReadMessageId): RecentItem
	{
		$this->lastReadMessageId = $lastReadMessageId;
		return $this;
	}

	public function getDateUpdate(): ?DateTime
	{
		return $this->dateUpdate;
	}

	public function setDateUpdate(?DateTime $dateUpdate): RecentItem
	{
		$this->dateUpdate = $dateUpdate;
		return $this;
	}

	public function getDateLastActivity(): ?DateTime
	{
		return $this->dateLastActivity;
	}

	public function setDateLastActivity(?DateTime $dateLastActivity): RecentItem
	{
		$this->dateLastActivity = $dateLastActivity;
		return $this;
	}

	public function getSourceChatId(): int
	{
		return $this->sourceChatId;
	}

	public function setSourceChatId(int $sourceChatId): self
	{
		$this->sourceChatId = $sourceChatId;
		return $this;
	}

	public function getPreviewSourceCid(): int
	{
		return $this->previewSourceCid;
	}

	public function setPreviewSourceCid(int $previewSourceCid): self
	{
		$this->previewSourceCid = $previewSourceCid;
		return $this;
	}

	public function getPreviewSourceMid(): int
	{
		return $this->previewSourceMid;
	}

	public function setPreviewSourceMid(int $previewSourceMid): self
	{
		$this->previewSourceMid = $previewSourceMid;
		return $this;
	}

	public function getMarkedId(): int
	{
		return $this->markedId;
	}

	public function setMarkedId(int $markedId): self
	{
		$this->markedId = $markedId;
		return $this;
	}

	protected static function formDialogId(int $id, string $type): string
	{
		if ($type === Chat::IM_TYPE_PRIVATE)
		{
			return (string)$id;
		}

		return "chat{$id}";
	}

	public static function getRestEntityName(): string
	{
		return 'recentItem';
	}

	public function toRestFormat(array $option = []): array
	{
		$rest = [
			'dialogId' => $this->dialogId,
			'chatId' => $this->chatId,
			'messageId' => $this->messageId,
			'type' => $this->type,
			'pinned' => $this->pinned,
			'unread' => $this->unread,
			'options' => $this->options,
			'invited' => $this->invited,
			'lastReadMessageId' => $this->lastReadMessageId,
			'dateUpdate' => $this->dateUpdate,
			'dateLastActivity' => $this->dateLastActivity,
		];

		// Always emit ownMessageId (the row's own last message) so the client never has to derive it.
		// For non-redirected rows it equals messageId; that is harmless and only the collab fixed-row
		// forceOwnMessage path reads it.
		if ($this->ownMessageId > 0)
		{
			$rest['ownMessageId'] = $this->ownMessageId;
		}

		return $rest;
	}
}
