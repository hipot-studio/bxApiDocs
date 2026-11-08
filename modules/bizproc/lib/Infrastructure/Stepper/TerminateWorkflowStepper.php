<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Stepper;

use Bitrix\Bizproc\Internal\Repository\WorkflowInstanceRepository\WorkflowInstanceRepository;
use Bitrix\Bizproc\Public\Command\Workflow\TerminateWorkflowCommand\TerminateWorkflowCommand;
use Bitrix\Bizproc\Public\Command\Workflow\TerminateWorkflowCommand\TerminateWorkflowCommandResult;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Update\Stepper;

/**
 * Terminates the processes of one template that were started before it was published in the format that stops
 * the outdated ones.
 *
 * The job is described entirely by its own agent parameters - a template and a cut-off boundary. The
 * persistent state of a step is not used for anything that tells one job from another: its key is the class
 * name, so two publications of different templates would overwrite each other there.
 *
 * The knowledge of which instances are meant lives here and nowhere below: a step folds the filter out of the
 * template and the boundary, reads a page through the repository, hands it to the command and decides by the
 * result of the command whether to go on. Neither the repository nor the command knows any of that semantics.
 */
class TerminateWorkflowStepper extends Stepper
{
	protected static $moduleId = 'bizproc';

	private const BATCH_SIZE_OPTION = 'termination_batch_size';
	private const DEFAULT_BATCH_SIZE = 100;

	/**
	 * Delay the job is bound with, in seconds: the smallest positive value.
	 *
	 * Zero is not allowed. On a delay of zero or less the platform calls execAgent inside bind itself
	 * ({@see Stepper::bindClass()}), and the first batch of terminations would land in the request that
	 * publishes, while publishing has to answer at once: inside a termination run the subscribers of other
	 * modules, and their cost is not knowable here. A large delay is harmful the other way round - until it
	 * runs out no number of portal hits moves the job, and every republication of the template starts the
	 * countdown anew. So the only thing asked of the delay is to be greater than zero.
	 */
	private const BIND_DELAY_SECONDS = 1;

	public function execute(array &$option): bool
	{
		$outerParams = $this->getOuterParams();
		$templateId = (int)($outerParams[0] ?? 0);
		// the boundary travels as a whole number of seconds: the arguments of the self-call are built out of
		// integers and strings alone ({@see Stepper::makeArguments()}), a date object would turn into garbage
		$cutoff = DateTime::createFromTimestamp((int)($outerParams[1] ?? 0));

		$batchSize = self::batchSize();

		$instanceIds = (new WorkflowInstanceRepository())->getIdsPage(
			[
				'=WORKFLOW_TEMPLATE_ID' => $templateId,
				'<STARTED' => $cutoff,
			],
			$batchSize,
		);

		if ($instanceIds->ids === [])
		{
			return time() < $cutoff->getTimestamp()
				? self::CONTINUE_EXECUTION
				: self::FINISH_EXECUTION;
		}

		/** @var TerminateWorkflowCommandResult $result */
		$result = (new TerminateWorkflowCommand($instanceIds->ids, authorizedByCaller: true))->run();

		if ($result->terminatedCount === 0)
		{
			return self::FINISH_EXECUTION;
		}

		return self::CONTINUE_EXECUTION;
	}

	/**
	 * Schedules the job for a template and drops the jobs scheduled for the same template before.
	 *
	 * Dropping goes first: the reverse order would remove the job just scheduled. And replacing is not a
	 * matter of binding over the previous one - the platform deduplicates agents by their exact name, while
	 * the name carries the boundary, so a second publication without an explicit removal leaves two live jobs.
	 */
	public static function bindTemplate(int $templateId, DateTime $cutoff): bool
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			WorkflowTemplateTable::lockRow($templateId);
			$prefix = static::class . '::execAgent(' . $templateId . ',';
			$agentIds = [];
			$keptAgentId = 0;
			$latestCutoff = 0;
			$iterator = \CAgent::GetList([], [
				'MODULE_ID' => static::$moduleId,
				'NAME' => $prefix . '%',
			]);
			while ($agent = $iterator->Fetch())
			{
				$name = (string)($agent['NAME'] ?? '');
				if (!str_starts_with($name, $prefix))
				{
					continue;
				}

				$agentId = (int)$agent['ID'];
				$agentIds[] = $agentId;
				$agentCutoff = preg_match('/,\s*(\d+)\);$/', $name, $matches) === 1
					? (int)$matches[1]
					: 0
				;
				if ((string)($agent['ACTIVE'] ?? 'Y') === 'Y' && $agentCutoff > $latestCutoff)
				{
					$keptAgentId = $agentId;
					$latestCutoff = $agentCutoff;
				}
			}

			$shouldSchedule = $latestCutoff < $cutoff->getTimestamp();
			foreach ($agentIds as $agentId)
			{
				if ($shouldSchedule || $agentId !== $keptAgentId)
				{
					\CAgent::Delete($agentId);
				}
			}

			if ($shouldSchedule)
			{
				static::bind(self::BIND_DELAY_SECONDS, [$templateId, $cutoff->getTimestamp()]);
			}

			$connection->commitTransaction();

			return self::hasAgentsForTemplate($templateId);
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			throw $exception;
		}
	}

	public static function hasAgentsForTemplate(int $templateId): bool
	{
		return self::agentIdsForTemplate($templateId) !== [];
	}

	/**
	 * Size of a batch: a constant of the class, overridable by an option of the module.
	 *
	 * A value that is not a positive number falls back to the constant: on a batch of zero the job would
	 * stand still for good.
	 */
	public static function batchSize(): int
	{
		$configured = (int)Option::get(
			static::$moduleId,
			self::BATCH_SIZE_OPTION,
			(string)self::DEFAULT_BATCH_SIZE,
		);

		return $configured > 0 ? $configured : self::DEFAULT_BATCH_SIZE;
	}

	/**
	 * Ids of the agent records of the job for one template.
	 *
	 * A job is recognized by the prefix of the agent name carrying the template id as its first parameter: no
	 * registry of jobs and no table of our own is needed for that. The prefix ends with the comma after the
	 * id, so the job of template 12 is never taken for the job of template 123.
	 *
	 * @return list<int>
	 */
	private static function agentIdsForTemplate(int $templateId): array
	{
		$prefix = static::class . '::execAgent(' . $templateId . ',';
		$agentIds = [];
		$iterator = \CAgent::GetList([], [
			'MODULE_ID' => static::$moduleId,
			'NAME' => $prefix . '%',
		]);
		while ($agent = $iterator->Fetch())
		{
			if (str_starts_with((string)($agent['NAME'] ?? ''), $prefix))
			{
				$agentIds[] = (int)$agent['ID'];
			}
		}

		return $agentIds;
	}

}
