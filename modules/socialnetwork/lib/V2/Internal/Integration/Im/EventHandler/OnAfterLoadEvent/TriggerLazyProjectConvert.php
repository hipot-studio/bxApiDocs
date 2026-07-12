<?php
declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\EventHandler\OnAfterLoadEvent;

use Bitrix\Im\V2\Chat\CollabChat;
use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterLoadEvent;
use Bitrix\Im\V2\Chat\WorkgroupChat;
use Bitrix\Main\Application;
use Bitrix\Socialnetwork\V2\Feature;
use Bitrix\Socialnetwork\V2\Public\Command\Convert\ConvertToProjectCommand;
use Throwable;

class TriggerLazyProjectConvert
{
	private static array $seen = [];

	public static function execute(AfterLoadEvent $event): void
	{
		$chat = $event->getChat();

		if (!($chat instanceof CollabChat) && !($chat instanceof WorkgroupChat))
		{
			return;
		}

		if (!Feature::isNewProjectsOn())
		{
			return;
		}

		$groupId = (int)$chat->getEntityId();
		if ($groupId <= 0 || isset(self::$seen[$groupId]))
		{
			return;
		}
		self::$seen[$groupId] = true;

		try
		{
			(new ConvertToProjectCommand($groupId, $event->getUserId()))->run();
		}
		catch (Throwable $t)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($t);
		}
	}
}
