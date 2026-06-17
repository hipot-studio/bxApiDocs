<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DayPlanBot;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Integration\ImBot\BizprocBot;
use Bitrix\Bizproc\Internal\Factory\Workflow\TriggerStageWorkflowFactory;
use Bitrix\Bizproc\Internal\Repository\DayPlanBot\DayPlanBotRepository;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\LaunchedTemplateRepository;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

class DayPlanBotSyncService
{
	public const TYPE_DAY_PLAN_BOT = 'day_plan_bot';

	private const DAY_PLAN_SYSTEM_CODES = [
		'bitrix_ai_day_planner',
	];

	private const IM_BOT_NEW_MESSAGE_TRIGGER = 'ImBotNewMessageTrigger';
	private const IM_BOT_PARAM_BOT_ID = 'BotId';
	private const IM_BOT_PARAM_BOT_CODE = 'BotCode';
	private const TRACKED_USERS_CONSTANT = 'TrackedUsers';

	private DayPlanBotRepository $repository;

	public function __construct()
	{
		$this->repository = ServiceLocator::getInstance()->get(DayPlanBotRepository::class);
	}

	public static function onTemplateUpdate(int $templateId, array $changedFields): void
	{
		$relevantFields = ['ACTIVE', 'CONSTANTS', 'TEMPLATE'];
		if (empty(array_intersect($relevantFields, array_keys($changedFields))))
		{
			return;
		}

		if (self::isDayPlanTemplate($templateId))
		{
			ServiceLocator::getInstance()
				->get(self::class)
				?->syncTemplate($templateId)
			;
		}
	}

	public function syncTemplate(int $templateId): void
	{
		if (!self::isDayPlanTemplate($templateId))
		{
			$this->repository->deleteUserData($templateId);

			return;
		}

		$trackedValues = $this->loadTrackedValues($templateId);
		if ($trackedValues === null)
		{
			$this->repository->deleteUserData($templateId);

			return;
		}

		$entityIds = $this->parseTrackedValues($trackedValues);
		$rows = [];
		foreach ($entityIds as $entityId)
		{
			$rows[] = [
				'TEMPLATE_ID' => $templateId,
				'ENTITY_ID' => $entityId,
				'TYPE' => self::TYPE_DAY_PLAN_BOT,
				'NAME' => 'bot_id',
				'VALUE' => '',
			];
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$this->repository->deleteUserData($templateId);
			$this->repository->addUserData($rows);
			$connection->commitTransaction();
		}
		catch (\Exception $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}
	}

	public function syncAllTemplates(): void
	{
		foreach ($this->getAllDayPlanTemplateIds() as $templateId)
		{
			$this->syncTemplate($templateId);
		}
	}

	private function loadTrackedValues(int $templateId): ?array
	{
		$constants = $this->repository->findActiveTemplateConstants($templateId);
		if ($constants === null)
		{
			return null;
		}

		return $this->extractTrackedValues($constants);
	}

	private function extractTrackedValues(array $constants): ?array
	{
		$trackedUsers = $constants[self::TRACKED_USERS_CONSTANT] ?? null;
		if ($trackedUsers === null || ($trackedUsers['Type'] ?? '') !== FieldType::USER)
		{
			return null;
		}

		$defaults = (array)($trackedUsers['Default'] ?? []);

		return !empty($defaults) ? array_values($defaults) : null;
	}

	private function parseTrackedValues(array $trackedValues): array
	{
		$entities = [];

		foreach ($trackedValues as $value)
		{
			$parsed = $this->parseSingleValue((string)$value);
			foreach ($parsed as $entity)
			{
				$entities[$entity] = true;
			}
		}

		return array_keys($entities);
	}

	private function parseSingleValue(string $value): array
	{
		if (str_starts_with($value, 'user_'))
		{
			$id = (int)substr($value, 5);

			return $id > 0 ? ['user_' . $id] : [];
		}

		if (preg_match('/^group_hr(r?)(\d+)$/i', $value, $match))
		{
			$recursive = !empty($match[1]);

			return ['[HR' . ($recursive ? 'R' : '') . $match[2] . ']'];
		}

		$entities = [];

		if (preg_match_all('/\[(\d+)]/', $value, $matches))
		{
			foreach ($matches[1] as $id)
			{
				$id = (int)$id;
				if ($id > 0)
				{
					$entities[] = 'user_' . $id;
				}
			}
		}

		if (preg_match_all('/\[HR(R?)(\d+)]/i', $value, $matches, PREG_SET_ORDER))
		{
			foreach ($matches as $match)
			{
				$recursive = !empty($match[1]);
				$entities[] = '[HR' . ($recursive ? 'R' : '') . $match[2] . ']';
			}
		}

		return $entities;
	}

	private function getAllDayPlanTemplateIds(): array
	{
		return ServiceLocator::getInstance()
			->get(LaunchedTemplateRepository::class)
			->getIdsBySystemCodes(self::DAY_PLAN_SYSTEM_CODES)
		;
	}

	public function resolveBotId(int $templateId): ?int
	{
		$triggerRow = $this->findImBotTrigger($templateId);
		if ($triggerRow === null)
		{
			return null;
		}

		$properties = $triggerRow['APPLY_RULES']['Properties'] ?? [];
		if (empty($properties))
		{
			return null;
		}

		return $this->resolveBotIdByDirectValue($properties)
			?? $this->resolveBotIdByCode($properties)
			?? $this->resolveBotIdViaActivity($properties, $triggerRow, $templateId)
		;
	}

	private function findImBotTrigger(int $templateId): ?array
	{
		return $this->repository->findTriggerByType($templateId, self::IM_BOT_NEW_MESSAGE_TRIGGER);
	}

	private function resolveBotIdByDirectValue(array $properties): ?int
	{
		$rawBotId = $properties[self::IM_BOT_PARAM_BOT_ID] ?? null;
		if (is_numeric($rawBotId) && (int)$rawBotId > 0)
		{
			return (int)$rawBotId;
		}

		return null;
	}

	private function resolveBotIdByCode(array $properties): ?int
	{
		$rawBotCode = $properties[self::IM_BOT_PARAM_BOT_CODE] ?? null;
		if (
			!is_string($rawBotCode)
			|| $rawBotCode === ''
			|| \CBPActivity::isExpression($rawBotCode)
			|| !Loader::includeModule('imbot')
		)
		{
			return null;
		}

		$botId = (int)BizprocBot::getBotIdByCode($rawBotCode);

		return $botId > 0 ? $botId : null;
	}

	private function resolveBotIdViaActivity(array $properties, array $triggerRow, int $templateId): ?int
	{
		if (!Loader::includeModule('imbot'))
		{
			return null;
		}

		if (!\CBPRuntime::getRuntime()->includeActivityFile(self::IM_BOT_NEW_MESSAGE_TRIGGER))
		{
			return null;
		}

		$activity = \CBPActivity::createInstance(self::IM_BOT_NEW_MESSAGE_TRIGGER, '');
		if (!$activity)
		{
			return null;
		}

		$activity->initializeFromArray($properties);

		$documentId = [$triggerRow['MODULE_ID'], $triggerRow['ENTITY'], $triggerRow['DOCUMENT_TYPE']];
		$stubWorkflow = (new TriggerStageWorkflowFactory())->create($templateId, $documentId);
		$activity->setWorkflow($stubWorkflow);

		$botId = filter_var($activity->{self::IM_BOT_PARAM_BOT_ID}, FILTER_VALIDATE_INT, [
			'options' => ['min_range' => 1],
		]);

		if (!$botId)
		{
			$botCode = (string)$activity->{self::IM_BOT_PARAM_BOT_CODE};
			$botId = (int)BizprocBot::getBotIdByCode($botCode);
		}

		return $botId > 0 ? $botId : null;
	}

	private static function isDayPlanTemplate(int $templateId): bool
	{
		return ServiceLocator::getInstance()
			->get(LaunchedTemplateRepository::class)
			->isLaunchedFrom($templateId, self::DAY_PLAN_SYSTEM_CODES)
		;
	}
}
