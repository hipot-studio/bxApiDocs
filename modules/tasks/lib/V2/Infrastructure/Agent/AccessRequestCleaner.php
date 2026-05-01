<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Agent;

use Bitrix\Tasks\Update\AgentInterface;
use Bitrix\Tasks\Update\AgentTrait;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Public\Command\Task\Access\ClearAccessRequestsCommand;

final class AccessRequestCleaner implements AgentInterface
{
	use AgentTrait;

	public static function execute(): string
	{
		$agent = new self();

		$agent->run();

		return $agent::getAgentName(false);
	}

	private function run(): void
	{
		$result = (new ClearAccessRequestsCommand())
			->run();

		if (!$result->isSuccess())
		{
			Container::getInstance()->getLogger()->logError($result->getError());
		}
	}
}
