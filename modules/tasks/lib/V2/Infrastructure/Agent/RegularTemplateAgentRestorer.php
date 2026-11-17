<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Agent;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Update\Stepper;
use Bitrix\Tasks\Integration\Bitrix24;
use Bitrix\Tasks\Integration\Bitrix24\FeatureDictionary;
use Bitrix\Tasks\Internals\Log\LogFacade;
use Bitrix\Tasks\Replication\Replicator\RegularTemplateTaskReplicator;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateRepositoryInterface;
use Bitrix\Tasks\V2\Public\Command\Template\Replication\RecalculateAgentCommand;
use CAgent;
use Closure;
use Throwable;

final class RegularTemplateAgentRestorer extends Stepper
{
	private const LIMIT = 250;
	private const SYSTEM_USER_ID = 0;
	private const FAILURE_MARKER = 'TASKS_REGULAR_TEMPLATE_AGENT_RESTORE_FAILED';
	private const RESTRICTED_MARKER = 'TASKS_REPLICATION_RESTRICTED';

	protected static $moduleId = 'tasks';

	private TemplateRepositoryInterface $templateRepository;
	private Closure $agentExists;
	private Closure $recalculateAgent;
	private Closure $logFailure;
	private Closure $tariffChecker;

	public function __construct(
		?TemplateRepositoryInterface $templateRepository = null,
		?Closure $agentExists = null,
		?Closure $recalculateAgent = null,
		?Closure $logFailure = null,
		?Closure $tariffChecker = null,
	)
	{
		$this->templateRepository =
			$templateRepository ?? Container::getInstance()->get(TemplateRepositoryInterface::class);
		$this->agentExists = $agentExists ?? static fn (string $agentName): bool => (bool)CAgent::GetList(
			[],
			[
				'=NAME' => $agentName,
				'USER_ID' => self::SYSTEM_USER_ID,
			],
		)->Fetch();
		$this->recalculateAgent =
			$recalculateAgent ?? static fn (int $templateId): Result => (new RecalculateAgentCommand($templateId))->run();
		$this->logFailure = $logFailure ?? static function (array $data, string $marker): void {
			LogFacade::log($data, $marker);
		};
		$this->tariffChecker = $tariffChecker ?? static fn (): bool =>
			Bitrix24::checkFeatureEnabled(FeatureDictionary::TASK_RECURRING_TASKS);
	}

	public function execute(array &$option): bool
	{
		$lastId = (int)($option['lastId'] ?? 0);

		// Restricted by tariff: such an agent deletes itself on the first run, so there is nothing to restore.
		if (!($this->tariffChecker)())
		{
			($this->logFailure)(['lastId' => $lastId], self::RESTRICTED_MARKER);

			return self::FINISH_EXECUTION;
		}

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
				$result = ($this->recalculateAgent)($templateId);
				if (!$result->isSuccess())
				{
					$this->logRecalculationFailure($templateId, $this->getErrorsData($result->getErrors()));
				}
			}
			catch (Throwable $t)
			{
				$this->logRecalculationFailure(
					$templateId,
					[
						[
							'message' => $t->getMessage(),
							'code' => (string)$t->getCode(),
							'type' => $t::class,
						],
					],
				);
			}
		}

		$option['lastId'] = (int)end($ids);

		return self::CONTINUE_EXECUTION;
	}

	private function hasAgent(int $templateId): bool
	{
		$agentName = RegularTemplateTaskReplicator::getAgentName($templateId);

		return ($this->agentExists)($agentName);
	}

	private function logRecalculationFailure(int $templateId, array $errors): void
	{
		($this->logFailure)(
			[
				'templateId' => $templateId,
				'errors' => $errors,
			],
			self::FAILURE_MARKER,
		);
	}

	/**
	 * @param Error[] $errors
	 */
	private function getErrorsData(array $errors): array
	{
		return array_map(
			static fn (Error $error): array => [
				'message' => $error->getMessage(),
				'code' => (string)$error->getCode(),
			],
			$errors,
		);
	}
}
