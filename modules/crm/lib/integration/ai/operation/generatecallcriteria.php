<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\AI\Context;
use Bitrix\Crm\Copilot\CallScriptMaintenance\Dispatcher;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\Scoring\GenerateCallCriteriaPayload;
use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Operation\Handler\GenerateCallCriteriaHandler;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadFactory;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\Analytics\Builder\AI\AIBaseEvent;
use Bitrix\Crm\Integration\Analytics\Builder\AI\GenerateCallCriteriaEvent;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Main;
use CCrmOwnerType;

final class GenerateCallCriteria extends AbstractOperation
{
	public const TYPE_ID = 10;
	public const CONTEXT_ID = 'generate_call_criteria';

	protected const PAYLOAD_CLASS = GenerateCallCriteriaPayload::class;
	protected const ENGINE_CODE = EventHandler::SETTINGS_CALL_ASSESSMENT_ENGINE_CODE;

	private array $dialogues = [];
	private bool $isCreateMode = false;

	public static function isAccessGranted(int $userId, ItemIdentifier $target): bool
	{
		return
			parent::isAccessGranted($userId, $target)
			&& Container::getInstance()->getUserPermissions($userId)->copilotCallAssessment()->canEdit()
		;
	}

	public static function isSuitableTarget(ItemIdentifier $target): bool
	{
		return $target->getEntityTypeId() === CCrmOwnerType::CopilotCallAssessment;
	}

	public function setDialogues(array $dialogues): self
	{
		$this->dialogues = $dialogues;

		return $this;
	}

	public function setCreateMode(bool $value): self
	{
		$this->isCreateMode = $value;

		return $this;
	}

	protected static function checkPreviousJobs(ItemIdentifier $target, int $parentId): Main\Result
	{
		return new Main\Result();
	}

	protected function getAIPayload(): Main\Result
	{
		$additionalData = [
			'dialogues' => $this->dialogues,
			'isCreateMode' => $this->isCreateMode,
		];

		return PayloadFactory::build(self::TYPE_ID, $this->userId, $this->target)
			->setAdditionalData($additionalData)
			->setMarkers([])
			->getResult()
		;
	}

	// region notify
	protected static function notifyTimelineAfterSuccessfulLaunch(Result $result): void
	{
	}

	protected static function notifyTimelineAfterSuccessfulJobFinish(Result $result): void
	{
	}

	protected static function notifyAboutJobError(
		Result $result,
		bool $withSyncBadges = true,
		bool $withSendAnalytics = true,
		?ItemIdentifier $target = null,
	): void
	{
		AIManager::logger()->error(
			'{date}: {class}: Job error on target: {target}' . PHP_EOL,
			[
				'class' => self::class,
				'target' => $result->getTarget(),
			],
		);

		Dispatcher::getInstance()->pingAgent();
	}
	// endregion

	protected static function extractPayloadFromAIResult(\Bitrix\AI\Result $result, EO_Queue $job): Dto
	{
		$json = self::extractPayloadPrettifiedData($result);
		if (empty($json))
		{
			return new GenerateCallCriteriaPayload([]);
		}

		return new GenerateCallCriteriaPayload([
			'name' => self::stripUnresolvedMarkers($json['name'] ?? null),
			'description' => self::stripUnresolvedMarkers($json['description'] ?? null),
			'newCriteria' => $json['new_criteria'] ?? [],
		]);
	}

	protected static function getJobFinishEventBuilder(): AIBaseEvent
	{
		return new GenerateCallCriteriaEvent();
	}

	/**
	 * The synthetic target belongs to the create mode alone, and creation is launched only from the
	 * suspicious calls grouping, so such a job always carries a parent job. Enrichment passes no parent
	 * and runs on the id of a real script, so it keeps reporting its job finish.
	 */
	protected static function hasSyntheticAnalyticsTarget(EO_Queue $job): bool
	{
		return (int)$job->getParentId() > 0
			&& (int)$job->getEntityId() === Dispatcher::SYNTHETIC_TARGET_ID
		;
	}

	protected static function onAfterSuccessfulJobFinish(Result $result, ?Context $context = null): void
	{
		/** @var GenerateCallCriteriaPayload $payload */
		$payload = $result->getPayload();
		if (!$payload || !$result->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: Error in generate call criteria job: {target}' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $result->getTarget(),
				],
			);

			return;
		}

		$assessmentId = $result->getTarget()?->getEntityId() ?? 0;
		$jobId = $result->getJobId() ?? 0;
		if ($jobId <= 0)
		{
			return;
		}

		$handler = new GenerateCallCriteriaHandler($assessmentId, $payload);
		if ($handler->applyMaintenancePostActionsByJob($jobId))
		{
			return;
		}

		if ($assessmentId > 0)
		{
			$handler->saveCriteria();

			return;
		}

		AIManager::logger()->warning(
			'{date}: {class}: job {job} finished without maintenance context and no assessment target; skipping',
			['class' => self::class, 'job' => $jobId],
		);
	}
}
