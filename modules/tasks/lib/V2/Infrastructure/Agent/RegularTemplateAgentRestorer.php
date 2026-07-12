<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Agent;

use Bitrix\Main\Update\Stepper;
use Bitrix\Tasks\Internals\Log\LogFacade;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateRepositoryInterface;
use Bitrix\Tasks\V2\Public\Command\Template\Replication\RecalculateAgentCommand;
use CAgent;
use Throwable;

final class RegularTemplateAgentRestorer extends Stepper
{
	private const LIMIT = 250;
	private const AGENT_NAME_PREFIX = 'CTasks::RepeatTaskByTemplateId(';

	protected static $moduleId = 'tasks';

	private TemplateRepositoryInterface $templateRepository;

	public function __construct()
	{
		$this->templateRepository = Container::getInstance()->get(TemplateRepositoryInterface::class);
	}

	public function execute(array &$option): bool
	{
		$lastId = (int)($option['lastId'] ?? 0);

		$ids = $this->templateRepository->getReplicableTemplateIds($lastId, self::LIMIT);
		if (empty($ids))
		{
			return self::FINISH_EXECUTION;
		}

		foreach ($ids as $templateId)
		{
			if ($this->hasAgent($templateId))
			{
				continue;
			}

			try
			{
				(new RecalculateAgentCommand($templateId))->run();
			}
			catch (Throwable $t)
			{
				LogFacade::logThrowable($t);
			}
		}

		$option['lastId'] = (int)end($ids);

		return self::CONTINUE_EXECUTION;
	}

	private function hasAgent(int $templateId): bool
	{
		$agentName = self::AGENT_NAME_PREFIX . $templateId . ');';

		return (bool)CAgent::GetList([], ['NAME' => $agentName])->Fetch();
	}
}
