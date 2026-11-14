<?php

namespace Bitrix\Im\V2\Link\Pin;

use Bitrix\Im\Model\EO_LinkPin;
use Bitrix\Im\Model\LinkPinTable;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Entity\File\FilePopupItem;
use Bitrix\Im\V2\Entity\User\UserPopupItem;
use Bitrix\Im\V2\Link\BaseLinkItem;
use Bitrix\Im\V2\Link\Reminder\ReminderPopupItem;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Main\Application;

/**
 * @method Message getEntity()
 */
class PinItem extends BaseLinkItem
{
	use ContextCustomer;

	public function __construct($source = null)
	{
		$this->initByDefault();

		if (!empty($source))
		{
			$this->load($source);
		}
	}

	public static function getDataClass(): string
	{
		return LinkPinTable::class;
	}

	protected static function getEntityIdFieldName(): string
	{
		return 'MESSAGE_ID';
	}

	public static function getEntityClassName(): string
	{
		return Message::class;
	}

	public static function getRestEntityName(): string
	{
		return 'pin';
	}

	public static function initByEntity(EO_LinkPin $entity): self
	{
		$pin = new static($entity);

		if ($entity->getMessage() !== null)
		{
			$pin->setEntity(new Message($entity->getMessage()));
		}

		return $pin;
	}

	public static function createFromMessage(Message $message, ?Context $context = null): self
	{
		$pin = new static();
		$pin->setContext($context);

		$pin
			->setEntity($message)
			->setAuthorId($pin->getContext()->getUserId())
			->setChatId($message->getChatId())
		;

		return $pin;
	}

	public static function getByMessage(Message $message): ?self
	{
		if ($message->getMessageId() === null)
		{
			return null;
		}

		$entity = LinkPinTable::query()
			->setSelect(['ID', 'CHAT_ID', 'AUTHOR_ID', 'DATE_CREATE', 'MESSAGE_ID'])
			->where('MESSAGE_ID', $message->getMessageId())
			->setLimit(1)
			->fetchObject()
		;

		if ($entity === null)
		{
			return null;
		}

		return static::initByEntity($entity)->setEntity($message);
	}

	/**
	 * Removes the row with a single DELETE on the table, bypassing the ORM entity: no entity events,
	 * no cache cleanup and no removal from the registry, only markDrop() on this object. Returns
	 * whether this very call removed the row, so under concurrent requests only one caller gets true
	 * and a once-only transition can be bound to it. Meant for the addressed unpin of a pin loaded on
	 * its own; a pin held by a registry or a collection has to go through delete().
	 */
	public function tryDelete(): bool
	{
		if ($this->getPrimaryId() === null || $this->getMessageId() === null || !isset($this->chatId))
		{
			return false;
		}

		$filter = [
			'=ID' => $this->getPrimaryId(),
			'=MESSAGE_ID' => $this->getMessageId(),
			'=CHAT_ID' => $this->getChatId(),
		];

		$connection = Application::getConnection();
		LinkPinTable::deleteBatch($filter);
		$deleted = $connection->getAffectedRowsCount() > 0;

		$this->markDrop();

		return $deleted;
	}

	public function toRestFormat(array $option = []): array
	{
		return [
			'id' => $this->getPrimaryId(),
			'messageId' => $this->getMessageId(),
			'chatId' => $this->getChatId(),
			'authorId' => $this->getAuthorId(),
			'dateCreate' => $this->getDateCreate()->format('c'),
		];
	}

	public function getMessageId(): ?int
	{
		return $this->getEntityId();
	}

	public function setMessageInfo(Message $message): BaseLinkItem
	{
		$this->setEntity($message);

		return $this;
	}

	public function setMessageId(?int $messageId): BaseLinkItem
	{
		$this->setEntityId($messageId);

		return parent::setMessageId($messageId);
	}

	public function getPopupData(array $excludedList = []): PopupData
	{
		return new PopupData(
			[new Message\AdditionalMessagePopupItem([$this->getEntityId()]), new UserPopupItem([$this->getAuthorId()])],
			$excludedList
		);
	}
}