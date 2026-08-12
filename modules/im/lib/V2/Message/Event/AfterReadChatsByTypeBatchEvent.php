<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Event;

use Bitrix\Im\V2\Chat\Type\ChatsByType;
use Bitrix\Im\V2\Common\Event\BaseEvent;

class AfterReadChatsByTypeBatchEvent extends BaseEvent
{
	public function __construct(int $userId, ChatsByType $chatsByType)
	{
		$parameters = [
			'userId' => $userId,
			'chatsByType' => clone $chatsByType,
		];

		$typeForEventName = ucfirst($chatsByType->type->getExtendedType());

		parent::__construct('OnAfterReadChatsByTypeBatch' . $typeForEventName, $parameters);
	}

	public function getUserId(): int
	{
		return $this->parameters['userId'];
	}

	public function getChatType(): string
	{
		return $this->getChatsByType()->type->extendedType;
	}

	/**
	 * @return int[]
	 */
	public function getChatIds(): array
	{
		return $this->getChatsByType()->getChatIds();
	}

	private function getChatsByType(): ChatsByType
	{
		return $this->parameters['chatsByType'];
	}
}
