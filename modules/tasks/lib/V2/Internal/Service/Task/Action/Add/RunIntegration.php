<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\LoggerInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Config\AddConfig;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Integration\RunMail;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Integration\RunMessenger;

class RunIntegration
{
	public function __construct(
		private readonly AddConfig $config,
		private readonly LoggerInterface $logger,
	)
	{
	}

	public function __invoke(array $fields, ?Task\Source $source = null): void
	{
		(new RunMail($this->config, $this->logger))((int)($fields['ID'] ?? 0), $source);
		(new RunMessenger($this->config))($fields, $source);
	}
}
