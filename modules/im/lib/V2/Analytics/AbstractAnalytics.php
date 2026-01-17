<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Analytics;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Main\Application;

abstract class AbstractAnalytics
{
	use ContextCustomer;

	protected Chat $chat;

	public function __construct(Chat $chat)
	{
		$this->chat = $chat;
	}

	protected function async(callable $job): void
	{
		Application::getInstance()->addBackgroundJob($job);
	}

	protected function isChatTypeAllowed(Chat $chat, ?string $event = null): bool
	{
		return
			$this->checkChatEntityTypeAvailability($chat, $event)
			&& $this->checkChatTypeAvailability($chat, $event)
		;
	}

	final protected function checkChatTypeAvailability(Chat $chat, ?string $event = null): bool
	{
		$unavailableTypes = $this->getUnavailableChatTypesForEvent($event);

		foreach ($unavailableTypes as $unavailableType)
		{
			if ($chat instanceof $unavailableType)
			{
				return false;
			}
		}

		return true;
	}

	final protected function checkChatEntityTypeAvailability(Chat $chat, ?string $event = null): bool
	{
		$unavailableEntityTypes = $this->getUnavailableChatEntityTypesForEvent($event);

		return !in_array($chat->getEntityType(), $unavailableEntityTypes, true);
	}

	protected function getUnavailableChatEntityTypesForEvent(?string $event = null): array
	{
		return [];
	}

	protected function getUnavailableChatTypesForEvent(?string $event = null): array
	{
		return [Chat\OpenLineChat::class, Chat\OpenLineLiveChat::class];
	}
}
