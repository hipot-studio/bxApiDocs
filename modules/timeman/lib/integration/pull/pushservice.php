<?php

declare(strict_types=1);

namespace Bitrix\Timeman\Integration\Pull;

use Bitrix\Main;
use Bitrix\Pull\Event;
use Bitrix\Timeman\Integration\Intranet\Settings;

class PushService
{
	public function sendEvent(PushEvent $event): void
	{
		if (!Main\Loader::includeModule('pull'))
		{
			return;
		}
		if (!(new Settings())->isToolAvailable(Settings::TOOLS['worktime']))
		{
			return;
		}

		($event->isTag())
			? \CPullWatch::AddToStack($event->getTag(), $event->getParams())
			: Event::add($event->getRecipients(), $event->getParams())
		;
	}

	public function subscribeByTag(string $tag, int $userId): void
	{
		if (!Main\Loader::includeModule('pull'))
		{
			return;
		}
		if (!(new Settings())->isToolAvailable(Settings::TOOLS['worktime']))
		{
			return;
		}

		\CPullWatch::Add(
			userId: $userId,
			tag: $tag,
			immediate: true
		);
	}
}
