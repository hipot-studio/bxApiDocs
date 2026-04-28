<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Public\Command\Push\Google;

use Bitrix\Calendar\Synchronization\Internal\Exception\SynchronizerException;
use Bitrix\Calendar\Synchronization\Internal\Service\Vendor\Google\Push\PushManager;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\ServiceLocator;

class SubscribeSectionCommandHandler
{
	private PushManager $pushManager;

	public function __construct()
	{
		$this->pushManager = ServiceLocator::getInstance()->get(PushManager::class);
	}

	/**
	 * @throws ArgumentException
	 * @throws SynchronizerException
	 */
	public function __invoke(SubscribeSectionCommand $command): void
	{
		$this->pushManager->subscribeSection($command->sectionConnection);
	}
}
