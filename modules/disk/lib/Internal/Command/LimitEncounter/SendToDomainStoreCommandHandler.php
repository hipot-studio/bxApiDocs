<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Command\LimitEncounter;

use Bitrix\Disk\Internal\Service\DomainStoreEventSender;
use Bitrix\Main\Application;
use Bitrix\Main\Result;

class SendToDomainStoreCommandHandler
{
	public function __construct(
		private readonly DomainStoreEventSender $domainStoreSender,
	)
	{
	}

	public function __invoke(SendToDomainStoreCommand $command): Result
	{
		Application::getInstance()->addBackgroundJob(function () use ($command) {
			$this->domainStoreSender->performRequest($command->action, [
				'domain' => $command->domain,
				'action' => $command->action,
			]);
		});

		return new Result();
	}
}