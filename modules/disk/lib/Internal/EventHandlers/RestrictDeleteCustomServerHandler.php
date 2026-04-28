<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\EventHandlers;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Internal\Interface\CustomServerInterface;
use Bitrix\Disk\Public\Event\DeletingCustomServerEvent;
use Bitrix\Main\EventResult;

class RestrictDeleteCustomServerHandler
{
	/**
	 * @param DeletingCustomServerEvent $deletingCustomServerEvent
	 * @return void
	 */
	public static function handle(DeletingCustomServerEvent $deletingCustomServerEvent): void
	{
		/** @var CustomServerInterface|null $customServer */
		$customServer = $deletingCustomServerEvent->getParameter('customServer');

		if (!$customServer instanceof CustomServerInterface)
		{
			return;
		}

		$customConfigType = Configuration::getDefaultViewerCustomConfigType();

		if ($customConfigType !== $customServer->getType())
		{
			return;
		}

		$deletingCustomServerEvent->addResult(new EventResult(
			type: EventResult::ERROR,
			parameters: [
				'isUsed' => true,
			],
		));
	}
}
