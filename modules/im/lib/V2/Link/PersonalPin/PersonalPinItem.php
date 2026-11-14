<?php

namespace Bitrix\Im\V2\Link\PersonalPin;

use Bitrix\Im\Model\EO_LinkPinPersonal;
use Bitrix\Im\Model\LinkPinPersonalTable;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Entity\User\UserPopupItem;
use Bitrix\Im\V2\Link\BaseLinkItem;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Service\Context;

/**
 * @method Message getEntity()
 */
class PersonalPinItem extends BaseLinkItem
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
		return LinkPinPersonalTable::class;
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
		return 'personalPin';
	}

	/**
	 * The message is set only when the read brought it along: an unfilled reference reads back as
	 * null instead of sending the ORM after the message.
	 */
	public static function initByEntity(EO_LinkPinPersonal $entity): self
	{
		$pin = new static($entity);

		if ($entity->isFilled('MESSAGE') && $entity->getMessage() !== null)
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

	public static function getByMessageAndOwner(Message $message, int $authorId): ?self
	{
		if ($message->getMessageId() === null)
		{
			return null;
		}

		$entity = LinkPinPersonalTable::query()
			->setSelect(['ID', 'CHAT_ID', 'AUTHOR_ID', 'DATE_CREATE', 'MESSAGE_ID'])
			->where('MESSAGE_ID', $message->getMessageId())
			->where('AUTHOR_ID', $authorId)
			->setLimit(1)
			->fetchObject()
		;

		if ($entity === null)
		{
			return null;
		}

		return static::initByEntity($entity)->setEntity($message);
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

	/**
	 * The record is addressed by 'id' and not by the 'linkId' of the link family: the client drops
	 * a personal pin by the same key it reads it with.
	 */
	public function toRestFormatIdOnly(): array
	{
		return [
			'id' => $this->getPrimaryId(),
			'chatId' => $this->getChatId(),
			'messageId' => $this->getMessageId(),
		];
	}

	public function getMessageId(): ?int
	{
		return $this->getEntityId();
	}

	/**
	 * Author of a personal pin is its owner, so the author of the message is not copied into it.
	 */
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
