<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\Feature\RegularTaskTypesFeature;
use Bitrix\Tasks\V2\Internal\Service\Replication\Recurrence\RecurrenceTemplateResolver;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\TaskSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\BaseTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence\Trait\RecurrenceFailureTrait;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\ToolService;

/**
 * Tools acting on recurring task templates. Their web entry points (the "Regular tasks" section and
 * its batch actions) are closed by the release flag, so the tools keep the same gate.
 */
abstract class BaseTemplateRecurrenceTool extends BaseTool
{
	use RecurrenceFailureTrait;

	public const NO_RECURRENCE_RULE_MESSAGE = 'The task template has no recurrence rule.';
	public const TASK_WITHOUT_TEMPLATE_MESSAGE = 'The task is not associated with a recurring task template.';

	public function __construct(
		protected readonly RecurrenceTemplateResolver $recurrenceTemplateResolver,
		ToolService $toolService,
		TaskSchemaBuilder $schemaBuilder,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($toolService, $schemaBuilder, $validationService, $tracedLogger);
	}

	public function canList(int $userId): bool
	{
		return parent::canList($userId) && Feature::isEnabled(RegularTaskTypesFeature::class);
	}

	/**
	 * @throws McpException
	 */
	public function canRun(int $userId): bool
	{
		parent::canRun($userId);

		if (!Feature::isEnabled(RegularTaskTypesFeature::class))
		{
			throw new McpException(
				'Managing recurring task templates is not available in this Bitrix24, '
				. 'so the requested action cannot be performed',
			);
		}

		return true;
	}

	/**
	 * Keeps one result per requested template in input order: templates without a recurrence rule are
	 * reported as a per-item error instead of being sent to the batch service. A template the user
	 * cannot act on is answered with the same `denied` the batch services use, and that answer wins
	 * over the missing rule - otherwise the message would tell whether a foreign template carries a
	 * recurrence rule.
	 *
	 * @param int[] $templateIds
	 * @param array<int, array{id:int, status:string, message?:string}> $serviceResults
	 * @param int[] $idsWithoutRule
	 * @param int[] $deniedIds
	 * @return array<int, array{id:int, status:string, message?:string}>
	 */
	protected function buildOrderedResults(
		array $templateIds,
		array $serviceResults,
		array $idsWithoutRule,
		array $deniedIds = [],
	): array
	{
		$resultById = [];
		foreach ($serviceResults as $serviceResult)
		{
			$resultById[(int)($serviceResult['id'] ?? 0)] = $serviceResult;
		}

		$withoutRule = array_fill_keys($idsWithoutRule, true);
		$denied = array_fill_keys($deniedIds, true);

		$results = [];
		foreach ($templateIds as $templateId)
		{
			if (isset($denied[$templateId]))
			{
				$results[] = ['id' => $templateId, 'status' => 'denied'];

				continue;
			}

			if (isset($withoutRule[$templateId]))
			{
				$results[] = [
					'id' => $templateId,
					'status' => 'error',
					'message' => self::NO_RECURRENCE_RULE_MESSAGE,
				];

				continue;
			}

			if (isset($resultById[$templateId]))
			{
				$results[] = $resultById[$templateId];

				continue;
			}

			$results[] = [
				'id' => $templateId,
				'status' => 'error',
				'message' => self::SERVICE_FAILURE_MESSAGE,
			];
		}

		return $results;
	}

	/**
	 * Keeps the legacy templateId response unchanged and adds an unambiguous task-to-template trace
	 * only when the caller used taskIds. Associations hidden by the resolver remain unavailable, while
	 * service results for a resolved template keep their original status and templateId trace.
	 *
	 * @param array<int, array{taskId: ?int, templateId: ?int}> $targets
	 * @param array<int, array{id:int, status:string, message?:string}> $serviceResults
	 * @param int[] $idsWithoutRule
	 * @param int[] $deniedIds
	 * @return array<int, array<string, int|string|null>>
	 */
	protected function buildTargetResults(
		array $targets,
		array $serviceResults,
		array $idsWithoutRule = [],
		array $deniedIds = [],
	): array
	{
		$templateIds = $this->recurrenceTemplateResolver->getTemplateIds($targets);
		$templateResults = $this->buildOrderedResults(
			$templateIds,
			$serviceResults,
			$idsWithoutRule,
			$deniedIds,
		);

		if (($targets[0]['taskId'] ?? null) === null)
		{
			return $templateResults;
		}

		$resultByTemplateId = [];
		foreach ($templateResults as $templateResult)
		{
			$resultByTemplateId[(int)$templateResult['id']] = $templateResult;
		}

		$results = [];
		foreach ($targets as $target)
		{
			$templateId = $target['templateId'];
			if ($templateId === null)
			{
				$results[] = [
					'taskId' => $target['taskId'],
					'templateId' => null,
					'status' => 'error',
					'message' => self::TASK_WITHOUT_TEMPLATE_MESSAGE,
				];

				continue;
			}

			$templateResult = $resultByTemplateId[$templateId] ?? [
				'status' => 'error',
				'message' => self::SERVICE_FAILURE_MESSAGE,
			];
			unset($templateResult['id']);

			$results[] = [
				'taskId' => $target['taskId'],
				'templateId' => $templateId,
				...$templateResult,
			];
		}

		return $results;
	}

}
