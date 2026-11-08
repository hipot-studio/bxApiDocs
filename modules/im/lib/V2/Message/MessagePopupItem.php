<?php

namespace Bitrix\Im\V2\Message;

use Bitrix\Im\V2\Chat\Comment\CommentPopupItem;
use Bitrix\Im\V2\Message\Reaction\ReactionPopupItem;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Rest\PopupDataAggregatable;
use Bitrix\Im\V2\Rest\PopupDataItem;

class MessagePopupItem implements PopupDataItem, PopupDataAggregatable
{
	/**
	 * @var int[]
	 */
	private array $messageIds;
	private ?MessageCollection $messages = null;
	private bool $shortInfo;

	public function __construct(array $messageIds, bool $shortInfo = false)
	{
		$this->messageIds = array_unique($messageIds);
		$this->shortInfo = $shortInfo;
	}

	public static function getInstanceMessages(MessageCollection $messages, bool $shortInfo = false): self
	{
		$instance = new self($messages->getIds(), $shortInfo);
		$instance->messages = clone $messages;

		return $instance;
	}

	public function merge(PopupDataItem $item): PopupDataItem
	{
		if ($item instanceof self)
		{
			$this->messageIds = array_unique(array_merge($this->messageIds, $item->messageIds));
		}

		return $this;
	}

	public static function getRestEntityName(): string
	{
		return 'messages';
	}

	public function toRestFormat(array $option = []): array
	{
		$option['MESSAGE_SHORT_INFO'] = $this->shortInfo;

		return $this->getMessages()->toRestFormat($option);
	}

	public function getPopupData(array $excludedList = []): PopupData
	{
		// Comment cells are assembled strictly over the top-level collection. A nested producer
		// arrives after PopupData has already filled the top-level item, so it would re-run the
		// query and leave author profiles collected by the first pass.
		$excludedList[] = CommentPopupItem::class;

		if ($this->shortInfo)
		{
			$excludedList[] = ReactionPopupItem::class;
		}

		return $this->getMessages()->getPopupData($excludedList);
	}

	private function getMessages(): MessageCollection
	{
		$this->messages ??= new MessageCollection(array_unique($this->messageIds));

		return $this->messages;
	}
}