<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Recurrence;

use Bitrix\Tasks\Internals\Log\LogFacade;
use Bitrix\Tasks\Internals\Task\Template\TemplateObject;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Tasks\Replication\Template\Enum\RegularityType;
use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Entity\Template\ReplicateParams;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Template\ReplicateParamsMapper;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateReplicateParamsRepositoryInterface;
use Bitrix\Tasks\Util\User;
use CTimeZone;
use Throwable;

/**
 * Reads recurrence rules of templates in batches and tells whether templates actually carry a rule.
 * Callers accept arbitrary template IDs, so recurrence actions must not fall through to a plain
 * template.
 */
class RecurrenceRuleService
{
	/**
	 * Keeps both the IN list and the buffered TEXT payloads (`REPLICATE_PARAMS`) bounded when the
	 * whole 200-id batch is requested.
	 */
	private const READ_CHUNK_SIZE = 100;

	public function __construct(
		private readonly TemplateReplicateParamsRepositoryInterface $templateReplicateParamsRepository,
		private readonly ReplicateParamsMapper $replicateParamsMapper,
		private readonly RecurrenceTimeZoneConverter $timeZoneConverter,
	)
	{
	}

	/**
	 * Recurrence fields of the requested templates, without any access check. Only the fields the
	 * recurrence rule consists of are selected, plus the creator time-zone fields required to
	 * normalize legacy rules. The full template read path costs one `SELECT *` of about 40 columns
	 * per template plus modifiers whose result is not part of the rule.
	 *
	 * @param int[] $templateIds
	 * @return array<int, Template> existing templates keyed by id
	 */
	public function getRules(array $templateIds, int $userId): array
	{
		return $this->readRules($templateIds, $userId, isolateItemFailures: false)->getRules();
	}

	/**
	 * Reads every valid row it can and records conversion failures by template ID. Query-level
	 * failures still escape so the caller can fail the whole batch without presenting partial data.
	 *
	 * @param int[] $templateIds
	 */
	public function getRulesResult(array $templateIds, int $userId): RecurrenceRuleReadResult
	{
		return $this->readRules($templateIds, $userId, isolateItemFailures: true);
	}

	/**
	 * @param int[] $templateIds
	 */
	private function readRules(
		array $templateIds,
		int $userId,
		bool $isolateItemFailures,
	): RecurrenceRuleReadResult
	{
		$templateIds = array_values(array_unique(array_filter(array_map('intval', $templateIds))));
		$result = new RecurrenceRuleReadResult();
		if ($templateIds === [])
		{
			return $result;
		}

		foreach (array_chunk($templateIds, self::READ_CHUNK_SIZE) as $chunk)
		{
			foreach ($this->loadTemplates($chunk) as $template)
			{
				$templateId = (int)$template->getId();
				try
				{
					$result->addRule($templateId, $this->mapTemplate($template, $userId));
				}
				catch (Throwable $exception)
				{
					if (!$isolateItemFailures)
					{
						throw $exception;
					}

					LogFacade::logThrowable($exception);
					$result->addFailedTemplate($templateId);
				}
			}
		}

		return $result;
	}

	/**
	 * @param int[] $templateIds
	 * @return iterable<TemplateObject>
	 */
	protected function loadTemplates(array $templateIds): iterable
	{
		return TemplateTable::query()
			->setSelect([
				'ID',
				'TASK_ID',
				'REPLICATE',
				'REPLICATE_PARAMS',
				'REPLICATE_TYPE',
				'TPARAM_REPLICATION_COUNT',
				'DEADLINE_AFTER',
				'MATCH_WORK_TIME',
				'CREATED_BY',
				'CREATOR.ID',
				'CREATOR.TIME_ZONE',
				'CREATOR.TIME_ZONE_OFFSET',
			])
			->whereIn('ID', $templateIds)
			->where('ZOMBIE', 'N')
			->exec()
			->fetchCollection()
		;
	}

	private function mapTemplate(TemplateObject $template, int $userId): Template
	{
		$templateId = (int)$template->getId();
		// A template that was not created from a task keeps TASK_ID = 0.
		$taskId = (int)$template->getTaskId();

		$replicateParams = $this->replicateParamsMapper->mapToValueObject($template->getReplicateParams());
		if (!$this->isValidRule($replicateParams))
		{
			$replicateParams = null;
		}

		if ($replicateParams !== null)
		{
			$sourceTimeZoneOffset = $this->resolveSourceTimeZoneOffset($replicateParams, $template);
			$replicateParams = $this->mapReplicateParams(
				$replicateParams,
				$userId,
				$sourceTimeZoneOffset,
			);
		}

		return new Template(
			id: $templateId,
			deadlineAfter: $template->getDeadlineAfter(),
			matchesWorkTime: $template->getMatchWorkTime(),
			replicate: $template->getReplicate(),
			replicateParams: $replicateParams,
			replicateCount: $template->getTparamReplicationCount(),
			regularityType: RegularityType::fromValue($template->getReplicateType()),
			taskId: $taskId > 0 ? $taskId : null,
		);
	}

	private function mapReplicateParams(
		ReplicateParams $replicateParams,
		int $userId,
		int $sourceTimeZoneOffset,
	): ReplicateParams
	{
		$referenceDate = $replicateParams->timezoneOffset === null
			? null
			: $replicateParams->startDate
		;
		$data = $replicateParams->toArray();
		$data['time'] = $this->timeZoneConverter->convertStoredTimeToUser(
			$replicateParams->time,
			$userId,
			$sourceTimeZoneOffset,
			$referenceDate,
		);
		if ($replicateParams->nextExecutionTime !== null)
		{
			$data['nextExecutionTime'] = $this->timeZoneConverter->convertServerDateTimeToUser(
				$replicateParams->nextExecutionTime,
				$userId,
			);
		}

		return ReplicateParams::mapFromArray($data);
	}

	private function resolveSourceTimeZoneOffset(
		ReplicateParams $replicateParams,
		TemplateObject $template,
	): int
	{
		if ($replicateParams->timezoneOffset !== null)
		{
			return (int)$replicateParams->timezoneOffset;
		}

		if (!CTimeZone::OptionEnabled())
		{
			return 0;
		}

		$createdBy = (int)$template->getCreatedBy();
		if ($createdBy <= 0 || $createdBy === User::getId())
		{
			return User::getTimeZoneOffset($createdBy);
		}

		$creator = $template->getCreator();
		if ($creator === null)
		{
			return CTimeZone::getTimezoneOffset('');
		}

		$creatorTimeZone = (string)$creator->getTimeZone();
		if ($creatorTimeZone !== '')
		{
			return CTimeZone::getTimezoneOffset($creatorTimeZone);
		}

		return (int)$creator->getTimeZoneOffset();
	}

	/**
	 * @param int[] $templateIds
	 * @return int[] missing templates and templates without a recurrence rule, in input order
	 */
	public function filterWithoutRule(array $templateIds): array
	{
		if ($templateIds === [])
		{
			return [];
		}

		$paramsByTemplateId = $this->templateReplicateParamsRepository->getByTemplateIds($templateIds);

		$idsWithoutRule = [];
		foreach ($templateIds as $templateId)
		{
			if (!$this->isValidRule(($paramsByTemplateId[$templateId] ?? null)?->replicateParams))
			{
				$idsWithoutRule[] = $templateId;
			}
		}

		return $idsWithoutRule;
	}

	public function hasRule(int $templateId): bool
	{
		if ($templateId <= 0)
		{
			return false;
		}

		return $this->isValidRule(
			$this->templateReplicateParamsRepository->getByTemplateId($templateId)?->replicateParams,
		);
	}

	private function isValidRule(?ReplicateParams $replicateParams): bool
	{
		return $replicateParams?->period !== null;
	}
}
