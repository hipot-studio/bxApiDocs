<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\AI\Context;
use Bitrix\Crm\Activity\Entity\ToDo;
use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Activity\Provider\EntityExclusion;
use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Activity\Provider\ToDo\ToDo as ToDoProvider;
use Bitrix\Crm\Activity\ToDo\ColorSettings\ColorSettingsProvider;
use Bitrix\Crm\Badge;
use Bitrix\Crm\Copilot\Pipeline\StepContext;
use Bitrix\Crm\Copilot\Pipeline\TargetResolver;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Exclusion\Access as ExclusionAccess;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Config;
use Bitrix\Crm\Integration\AI\Dto\AnalyzeCommunicationActionPayload;
use Bitrix\Crm\Integration\AI\Dto\AnalyzeCommunicationPayload;
use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadFactory;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\Analytics\Builder\AI\AIBaseEvent;
use Bitrix\Crm\Integration\Analytics\Builder\AI\AnalyzeCommunicationEvent;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\AI\Controller;
use Bitrix\Main\Type\DateTime;
use CCrmActivity;
use CCrmOwnerType;

final class AnalyzeCommunication extends AbstractOperation
{
	public const TYPE_ID = 9;
	public const CONTEXT_ID = 'analyze_communication';

	public const SUPPORTED_TARGET_ENTITY_TYPE_IDS = [
		CCrmOwnerType::Activity,
	];
	public const SUPPORTED_ACTIVITY_PROVIDER_IDS = [
		Call::ACTIVITY_PROVIDER_ID,
		OpenLine::ACTIVITY_PROVIDER_ID,
	];

	public const DATE_FORMAT = 'Y-m-d\TH:i:s';

	protected const PAYLOAD_CLASS = AnalyzeCommunicationPayload::class;
	protected const ENGINE_CODE = EventHandler::SETTINGS_ANALYZE_COMMUNICATION_ENGINE_CODE;

	private const MAX_TODO_ACTIONS = 5;
	private const MAX_TITLE_LENGTH = 255;
	private const MAX_DESCRIPTION_LENGTH = 10000;
	private const MAX_REASON_LENGTH = 10000;

	public function __construct(
		ItemIdentifier $target,
		private readonly string $transcription,
		?int $userId = null,
		?int $parentJobId = null,
	)
	{
		parent::__construct($target, $userId, $parentJobId);
	}

	public static function isAccessGranted(int $userId, ItemIdentifier $target): bool
	{
		if (
			!parent::isAccessGranted($userId, $target)
			|| !CCrmActivity::CheckItemUpdatePermission(
				['ID' => $target->getEntityId()],
				Container::getInstance()->getUserPermissions($userId)->getCrmPermissions(),
			)
		)
		{
			return false;
		}

		$entityTarget = (new TargetResolver())->findTarget($target->getEntityId());

		return $entityTarget === null || self::hasTargetUpdatePermission($userId, $entityTarget);
	}

	public static function isSuitableTarget(ItemIdentifier $target): bool
	{
		if ($target->getEntityTypeId() === CCrmOwnerType::Activity)
		{
			$activity = Container::getInstance()->getActivityBroker()->getById($target->getEntityId());
			if (
				$activity
				&& isset($activity['PROVIDER_ID'])
				&& in_array($activity['PROVIDER_ID'], self::SUPPORTED_ACTIVITY_PROVIDER_IDS, true)
			)
			{
				return true;
			}
		}

		return false;
	}

	public static function shouldRelaunch(Result $existingResult, StepContext $context): bool
	{
		if (
			$context->getActivityId() <= 0
			|| !$context->isManualLaunch()
			|| $context->getActivityProvider() !== OpenLine::getId()
		)
		{
			return false;
		}

		// Extras branch is a testing seam: unit tests inject pre-computed `messagesForCopilot`
		// and `lastMessagesVolumeForCopilot` via StepContext to avoid activity/broker fixtures.
		// Production callers do not set these — the fallback below reads the same data from the activity.
		$messages = $context->getExtra('messagesForCopilot');
		$lastMessagesVolume = $context->getExtra('lastMessagesVolumeForCopilot');
		if (is_string($messages) && is_numeric($lastMessagesVolume))
		{
			return mb_strlen($messages, 'UTF-8') >= (int)$lastMessagesVolume + OpenLine::CHAT_MESSAGE_COPILOT_PROCESSING_LIMIT;
		}

		return OpenLine::isCopilotProcessingAvailable(
			$context->getActivityId(),
			is_string($messages) ? $messages : ''
		);
	}

	protected static function checkPreviousJobs(ItemIdentifier $target, int $parentId): \Bitrix\Main\Result
	{
		$activity = Container::getInstance()->getActivityBroker()->getById($target->getEntityId());
		if (($activity['PROVIDER_ID'] ?? null) !== OpenLine::getId())
		{
			return parent::checkPreviousJobs($target, $parentId);
		}

		return parent::checkPreviousJobsAllowingSuccessfulRelaunch($target, $parentId);
	}

	protected function getAIPayload(): \Bitrix\Main\Result
	{
		return PayloadFactory::build(self::TYPE_ID, $this->userId, $this->target)
			->setMarkers([
				'dialogue' => $this->transcription,
			])->getResult()
		;
	}

	protected function getContextLanguageId(): string
	{
		$itemIdentifier = $this->targetResolver->findTarget($this->target->getEntityId());
		if ($itemIdentifier)
		{
			return Config::getLanguageId(
				$this->userId,
				$itemIdentifier->getEntityTypeId(),
				$itemIdentifier->getCategoryId()
			);
		}

		return parent::getContextLanguageId();
	}

	protected static function notifyTimelineAfterSuccessfulLaunch(Result $result): void
	{
		$activityId = $result->getTarget()?->getEntityId();
		$nextTarget = (new TargetResolver())->findTarget($activityId);
		if ($nextTarget)
		{
			self::notifyTimelinesAboutActivityUpdate($activityId, true);
		}
	}

	protected static function notifyTimelineAfterSuccessfulJobFinish(Result $result): void {}

	protected static function notifyAboutJobError(
		Result $result,
		bool $withSyncBadges = true,
		bool $withSendAnalytics = true
	): void
	{
		$activityId = $result->getTarget()?->getEntityId();
		$nextTarget = (new TargetResolver())->findTarget($activityId);
		if ($nextTarget)
		{
			if ($withSyncBadges)
			{
				Controller::getInstance()->onLaunchError(
					$nextTarget,
					$activityId,
					[
						'OPERATION_TYPE_ID' => self::TYPE_ID,
						'ENGINE_ID' => self::$engineId,
						'ERRORS' => array_unique($result->getErrorMessages()),
					],
					$result->getUserId(),
				);

				// Reuses the shared AI error badge — all AI operations show the same "processing error" indicator
				self::syncBadges($activityId, Badge\Type\AiCallFieldsFillingResult::ERROR_PROCESS_VALUE);
			}

			self::notifyTimelinesAboutActivityUpdate($activityId);

			if ($withSendAnalytics)
			{
				self::sendCallParsingAnalyticsEvent(
					$result,
					$activityId
				);
			}
		}
	}

	protected static function extractPayloadFromAIResult(\Bitrix\AI\Result $result, EO_Queue $job): Dto
	{
		$json = self::extractPayloadPrettifiedData($result);
		if (empty($json))
		{
			return new AnalyzeCommunicationPayload([]);
		}

		$rawActions = $json['actions'] ?? null;
		$actions = is_array($rawActions)
			? array_values(array_filter($rawActions, 'is_array'))
			: []
		;
		$normalized = [];
		foreach ($actions as $row)
		{
			$title = mb_substr((string)($row['title'] ?? ''), 0, self::MAX_TITLE_LENGTH);
			$description = mb_substr((string)($row['description'] ?? ''), 0, self::MAX_DESCRIPTION_LENGTH);

			// Drop actions that would otherwise fail AnalyzeCommunicationActionPayload validation
			// and invalidate the entire payload — keep surviving actions usable.
			if (trim($title) === '' && trim($description) === '')
			{
				continue;
			}

			$normalized[] = [
				'title' => $title,
				'description' => $description,
				'responsiblePerson' => $row['responsible_person'] ?? '',
				'deadline' => $row['deadline'] ?? null,
			];

			if (count($normalized) >= self::MAX_TODO_ACTIONS)
			{
				break;
			}
		}

		$rawReason = $json['reason_if_is_client_false'] ?? null;
		$reason = is_string($rawReason) && $rawReason !== ''
			? mb_substr($rawReason, 0, self::MAX_REASON_LENGTH)
			: null
		;

		return new AnalyzeCommunicationPayload([
			'isClient' => $json['is_client'] ?? false,
			'reasonIfIsClientFalse' => $reason,
			'actions' => $normalized,
		]);
	}

	protected static function onAfterSuccessfulJobFinish(Result $result, ?Context $context = null): void
	{
		/** @var AnalyzeCommunicationPayload $payload */
		$payload = $result->getPayload();
		if (!$payload || !$result->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: Error while trying to save activity of job error: {target}' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $result->getTarget(),
				],
			);

			return;
		}

		$activityId = $result->getTarget()?->getEntityId();
		$entityTarget = (new TargetResolver())->findTarget($activityId);
		if (!$entityTarget)
		{
			AIManager::logger()->error(
				'{date}: {class}: No target found for activity {activityId} to save AnalyzeCommunication result' . PHP_EOL,
				[
					'class' => self::class,
					'activityId' => $activityId,
				],
			);

			return;
		}

		$activity = Container::getInstance()->getActivityBroker()->getById($activityId);
		$responsibleId = is_array($activity) ? (int)($activity['RESPONSIBLE_ID'] ?? 0) : 0;
		$userId = $responsibleId > 0 ? $responsibleId : (int)$result->getUserId();

		if ($userId <= 0)
		{
			AIManager::logger()->error(
				'{date}: {class}: Cannot resolve responsible user for activity {activityId} — both RESPONSIBLE_ID and result userId are empty' . PHP_EOL,
				[
					'class' => self::class,
					'activityId' => $activityId,
				],
			);
			self::notifyAboutJobError($result);

			return;
		}

		if ($payload->isClient)
		{
			self::createTodoActivities($activityId, $userId, $payload->actions, $entityTarget);
		}
		else
		{
			self::createEntityExclusionActivity($activityId, $userId, $payload->reasonIfIsClientFalse, $entityTarget);
		}
	}

	protected static function getJobFinishEventBuilder(): AIBaseEvent
	{
		return new AnalyzeCommunicationEvent();
	}

	private static function createTodoActivities(int $activityId, int $userId, array $actions, ItemIdentifier $entityTarget): void
	{
		if (empty($actions))
		{
			AIManager::logger()->warning(
				'{date}: {class}: Unable to create ToDo activity for {owner} - data is empty ' . PHP_EOL,
				[
					'class' => self::class,
					'owner' => $entityTarget,
				],
			);

			return;
		}

		if (!self::canCreateTodoActivity($userId, $entityTarget))
		{
			self::logPermissionDenied('ToDo activity', $userId, $entityTarget);

			return;
		}

		/** @var AnalyzeCommunicationActionPayload $action */
		foreach ($actions as $action)
		{
			$deadline = (new DateTime())->add('+3 days');

			if (!empty($action->deadline))
			{
				$parsedDeadline = self::parseDeadline((string)$action->deadline);
				if ($parsedDeadline !== null)
				{
					$deadline = $parsedDeadline;
				}
				else
				{
					AIManager::logger()->warning(
						'{date}: {class}: Failed to parse deadline "{deadline}", using default +3 days' . PHP_EOL,
						[
							'class' => self::class,
							'deadline' => $action->deadline,
						],
					);
				}
			}

			$todo = (new ToDo($entityTarget, new ToDoProvider()))
				->setParentActivityId($activityId)
				->setSubject($action->getSubject(self::MAX_TITLE_LENGTH))
				->setDescription($action->description ?? '')
				->setDeadline($deadline)
				->setResponsibleId($userId)
				->setAuthorId($userId)
				->setColorId(ColorSettingsProvider::COPILOT_COLOR_ID)
				// Permissions are validated explicitly for $userId; async callbacks may run
				// under a different current user, so BaseActivity::save() cannot rely on context.
				->setCheckPermissions(false)
				->setSettings(['IS_AI_CREATED' => true])
			;

			$saveTodoActResult = $todo->save(['DISABLE_COMPLETE_PARENT_ACTIVITY' => true], true);
			if (!$saveTodoActResult->isSuccess())
			{
				AIManager::logger()->error(
					'{date}: {class}: Unable to save ToDo activity for owner {owner}: {error}' . PHP_EOL,
					[
						'class' => self::class,
						'owner' => $entityTarget,
						'error' => $saveTodoActResult->getError(),
					],
				);
			}
		}

		OpenLine::saveLastMessagesVolumeForCopilot($activityId);
		self::notifyTimelinesAboutActivityUpdate($activityId, true);
	}

	private static function createEntityExclusionActivity(int $activityId, int $userId, ?string $reason, ItemIdentifier $entityTarget): void
	{
		if (empty($reason))
		{
			AIManager::logger()->warning(
				'{date}: {class}: Unable to create EntityExclusion activity for {owner} - data is empty ' . PHP_EOL,
				[
					'class' => self::class,
					'owner' => $entityTarget,
				],
			);

			return;
		}

		if (!self::canCreateEntityExclusionActivity($userId, $entityTarget))
		{
			self::logPermissionDenied('EntityExclusion activity', $userId, $entityTarget, true);

			return;
		}

		$fields = [
			'DESCRIPTION' => $reason,
			'AUTHOR_ID' => $userId,
			'RESPONSIBLE_ID' => $userId,
			'ASSOCIATED_ENTITY_ID' => $activityId,
			'BINDINGS' => [
				[
					'OWNER_TYPE_ID' => $entityTarget->getEntityTypeId(),
					'OWNER_ID' => $entityTarget->getEntityId(),
				],
			],
		];

		$saveActResult = (new EntityExclusion())->createActivity(
			EntityExclusion::PROVIDER_TYPE_ID_DEFAULT,
			$fields
		);
		if (!$saveActResult->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: Unable to save EntityExclusion activity for owner {owner}: {error}' . PHP_EOL,
				[
					'class' => self::class,
					'owner' => $entityTarget,
					'error' => $saveActResult->getError(),
				],
			);
		}

		OpenLine::saveLastMessagesVolumeForCopilot($activityId);
		self::notifyTimelinesAboutActivityUpdate($activityId, true);
	}

	private static function canCreateTodoActivity(int $userId, ItemIdentifier $entityTarget): bool
	{
		return self::hasTargetUpdatePermission($userId, $entityTarget);
	}

	private static function canCreateEntityExclusionActivity(int $userId, ItemIdentifier $entityTarget): bool
	{
		return self::hasTargetUpdatePermission($userId, $entityTarget)
			&& (new ExclusionAccess($userId))->canWrite()
		;
	}

	private static function hasTargetUpdatePermission(int $userId, ItemIdentifier $entityTarget): bool
	{
		return $userId > 0
			&& Container::getInstance()->getUserPermissions($userId)->item()->canUpdate(
				$entityTarget->getEntityTypeId(),
				$entityTarget->getEntityId(),
			)
		;
	}

	/**
	 * Parses LLM-supplied deadline. Prompt asks for Y-m-d\TH:i:s in server TZ, but LLMs frequently
	 * emit ISO-8601 variants (offset, Zulu, space separator, date-only). Try common patterns.
	 */
	private static function parseDeadline(string $raw): ?DateTime
	{
		$raw = trim($raw);
		if ($raw === '')
		{
			return null;
		}

		// Normalize Zulu suffix to explicit UTC offset — DateTime format 'P' parses '+00:00'.
		if (str_ends_with($raw, 'Z'))
		{
			$raw = substr($raw, 0, -1) . '+00:00';
		}

		$formats = [
			'Y-m-d\TH:i:sP', // ISO with TZ offset: 2026-04-17T14:30:00+03:00
			self::DATE_FORMAT, // Server TZ: 2026-04-17T14:30:00
			'Y-m-d H:i:s', // Common LLM variant with space separator
			'Y-m-d', // Date only
		];

		foreach ($formats as $format)
		{
			$parsed = DateTime::tryParse($raw, $format);
			if ($parsed !== null)
			{
				// Date-only produces 00:00:00 — snap to end of day so today's deadlines don't arrive already overdue.
				if ($format === 'Y-m-d')
				{
					$parsed->setTime(23, 59, 59);
				}

				return $parsed;
			}
		}

		return null;
	}

	private static function logPermissionDenied(
		string $activityType,
		int $userId,
		ItemIdentifier $entityTarget,
		bool $requiresExclusionWrite = false,
	): void
	{
		AIManager::logger()->warning(
			'{date}: {class}: Unable to create {activityType} for owner {owner}: missing permissions for user {userId}.'
			. ' Target update access {canUpdateTarget}, exclusion write access {canWriteExclusion}' . PHP_EOL,
			[
				'class' => self::class,
				'activityType' => $activityType,
				'owner' => $entityTarget,
				'userId' => $userId,
				'canUpdateTarget' => self::hasTargetUpdatePermission($userId, $entityTarget),
				'canWriteExclusion' => !$requiresExclusionWrite || (new ExclusionAccess($userId))->canWrite(),
			],
		);
	}
}
