<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\AI\Context;
use Bitrix\AI\Payload\IPayload;
use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Copilot\AiCallScriptSelection\Controller\AiCallScriptSelectionController;
use Bitrix\Crm\Copilot\AiCallScriptSelection\Entity\AiCallScriptSelectionItem;
use Bitrix\Crm\Copilot\CallAssessment\Analytics\ScriptMatchEvent;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\Scoring\SelectCallScoringScriptPayload;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadFactory;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\Analytics\Builder\AI\SelectCallScoreScriptEvent;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Main;
use Bitrix\Main\Web\Json;
use CCrmActivity;
use CCrmOwnerType;

final class SelectCallScoreScript extends AbstractOperation
{
	public const TYPE_ID = 12;
	public const CONTEXT_ID = 'select_call_score_script';

	protected const PAYLOAD_CLASS = SelectCallScoringScriptPayload::class;
	protected const ENGINE_CODE = EventHandler::SETTINGS_CALL_ASSESSMENT_ENGINE_CODE;

	private string $transcription = '';
	private array $assessmentSettingsIds = [];

	public static function isAccessGranted(int $userId, ItemIdentifier $target): bool
	{
		return parent::isAccessGranted($userId, $target)
			&& CCrmActivity::CheckItemUpdatePermission(
				['ID' => $target->getEntityId()],
				Container::getInstance()->getUserPermissions($userId)->getCrmPermissions(),
			)
		;
	}

	public static function isSuitableTarget(ItemIdentifier $target): bool
	{
		if ($target->getEntityTypeId() === CCrmOwnerType::Activity)
		{
			$activity = Container::getInstance()->getActivityBroker()->getById($target->getEntityId());
			$providerId = $activity['PROVIDER_ID'] ?? null;
			if ($providerId === Call::getId())
			{
				return true;
			}
		}

		return false;
	}

	public function setTranscription(string $transcription): self
	{
		$this->transcription = $transcription;

		return $this;
	}

	public function setAssessmentSettingsIds(array $assessmentSettingsIds): self
	{
		$this->assessmentSettingsIds = $assessmentSettingsIds;

		return $this;
	}

	protected function getAIPayload(): Main\Result
	{
		$additionalData = [
			'transcription' => $this->transcription,
		];

		if (!empty($this->assessmentSettingsIds))
		{
			$additionalData['assessmentSettingsIds'] = $this->assessmentSettingsIds;
		}

		$result = PayloadFactory::build(self::TYPE_ID, $this->userId, $this->target)
			->setEncodedMarkers(['call', 'scripts'])
			->setAdditionalData($additionalData)
			->setMarkers([])
			->getResult()
		;

		/** @var IPayload $payload */
		$payload = $result->getData()['payload'];
		if (!$this->isPayloadMarkersValid($payload->getMarkers()))
		{
			$error = ErrorCode::getInvalidPayloadMarkersForSelectCallScoreScriptError();

			return (new Main\Result())->addError($error);
		}

		return $result;
	}

	private function isPayloadMarkersValid(array $markers): bool
	{
		if (empty($markers))
		{
			return false;
		}

		try
		{
			$call = Json::decode($markers['call'] ?? '[]');
			if (empty($call))
			{
				return false;
			}

			$scripts = Json::decode($markers['scripts'] ?? '[]');
			if (empty($scripts))
			{
				return false;
			}
		}
		catch (Main\ArgumentException)
		{
			return false;
		}

		return true;
	}

	protected static function notifyTimelineAfterSuccessfulLaunch(Result $result): void
	{
		$activityId = $result->getTarget()?->getEntityId();
		if ($activityId !== null)
		{
			self::notifyTimelinesAboutActivityUpdate($activityId, true);
		}
	}

	protected static function notifyTimelineAfterSuccessfulJobFinish(Result $result): void
	{
		$activityId = $result->getTarget()?->getEntityId();
		if ($activityId !== null)
		{
			self::notifyTimelinesAboutActivityUpdate($activityId, true);
		}
	}

	protected static function extractPayloadFromAIResult(\Bitrix\AI\Result $result, EO_Queue $job): Dto
	{
		$data = self::extractPayloadPrettifiedData($result)['scriptSelection'] ?? [];

		if (isset($data['scriptId']))
		{
			$data['scriptId'] = (int)$data['scriptId'];
		}

		return new SelectCallScoringScriptPayload($data ?? []);
	}

	/**
	 * Selected script straight from the AI result, for consumers that must not depend on the job row
	 * being persisted yet. The bizproc call assessment activity is woken by the same
	 * ai:onQueueJobExecute event that persists the row, and the handler order on it is undefined.
	 */
	public static function extractScriptIdFromAIResult(\Bitrix\AI\Result $result): ?int
	{
		$scriptId = (int)(self::extractPayloadPrettifiedData($result)['scriptSelection']['scriptId'] ?? 0);

		return $scriptId > 0 ? $scriptId : null;
	}

	protected static function onAfterSuccessfulJobFinish(Result $result, ?Context $context = null): void
	{
		/** @var SelectCallScoringScriptPayload|null $payload */
		$payload = $result->getPayload();

		if (!$payload || !$result->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: Error in select call scoring script: {target}' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $result->getTarget(),
				],
			);

			return;
		}

		$activityId = $result->getTarget()?->getEntityId();
		if (!$activityId)
		{
			return;
		}

		AiCallScriptSelectionController::getInstance()->add(
			AiCallScriptSelectionItem::createFromEntityFields([
				'ACTIVITY_ID' => $activityId,
				'ASSESSMENT_SETTING_ID' => $payload->scriptId,
				'JOB_ID' => (int)$result->getJobId(),
				'CONFIDENCE' => $payload->confidence,
				'RATIONALE' => $payload->rationale,
			]),
		);

		(new ScriptMatchEvent())
			->setCallAssessmentId((int)$payload->scriptId)
			->setConfidenceScore((string)$payload->confidence)
			->buildEvent()
			->send()
		;
	}

	protected static function notifyAboutJobError(
		Result $result,
		bool $withSyncBadges = true,
		bool $withSendAnalytics = true,
		?ItemIdentifier $target = null,
	): void
	{
		AIManager::logger()->error(
			'{date}: {class}: Error on: {target}' . PHP_EOL,
			[
				'class' => self::class,
				'target' => $result->getTarget(),
			],
		);

		$activityId = $result->getTarget()?->getEntityId();
		if ($activityId !== null)
		{
			self::notifyTimelinesAboutActivityUpdate($activityId, true);
		}
	}

	protected static function getJobFinishEventBuilder(): SelectCallScoreScriptEvent
	{
		return new SelectCallScoreScriptEvent();
	}
}
