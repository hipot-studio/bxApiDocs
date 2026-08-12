<?php

namespace Bitrix\Crm\Integration\AI;

use Bitrix\AI\Context;
use Bitrix\AI\Engine;
use Bitrix\AI\Engine\IEngine;
use Bitrix\AI\Quality;
use Bitrix\AI\Tuning;
use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Activity\Provider\RepeatSale;
use Bitrix\Crm\Copilot\AiQueueBuffer\Controller\AiQueueBufferController;
use Bitrix\Crm\Copilot\AiQueueBuffer\Entity\AiQueueBufferItem;
use Bitrix\Crm\Copilot\AiQueueBuffer\Provider\FillRepeatSaleTipsProvider;
use Bitrix\Crm\Copilot\Pipeline\OperationRegistry;
use Bitrix\Crm\Copilot\Pipeline\PipelineExecutor;
use Bitrix\Crm\Copilot\Pipeline\ScenarioResolver;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\Autostart\AutoLauncher;
use Bitrix\Crm\Integration\Analytics\Builder\AI\CallActivityWithAudioRecordingEvent;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\Integration\VoxImplant;
use Bitrix\Crm\Integration\VoxImplantManager;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\ORM\Fields\FieldTypeMask;
use CCrmOwnerType;

final class EventHandler
{
	public const SETTINGS_FILL_ITEM_FROM_CALL_ENABLED_CODE = 'crm_copilot_fill_item_from_call_enabled';
	public const SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_AUDIO_CODE = 'crm_copilot_fill_item_from_call_engine_audio';
	public const SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_TEXT_CODE = 'crm_copilot_fill_item_from_call_engine_text';
	public const SETTINGS_FILL_CRM_TEXT_ENABLED_CODE = 'crm_copilot_fill_crm_text_enabled';
	public const SETTINGS_CALL_ASSESSMENT_ENABLED_CODE = 'crm_copilot_call_assessment_enabled';
	public const SETTINGS_MESSAGESENDER_EDITOR_ENABLED_CODE = 'crm_copilot_message_sender_editor_enabled';
	public const SETTINGS_CALL_ASSESSMENT_ENGINE_CODE = 'crm_copilot_call_assessment_engine_code';
	public const SETTINGS_REPEAT_SALE_ENABLED_CODE = 'crm_copilot_repeat_sale_enabled';
	public const SETTINGS_REPEAT_SALE_ENGINE_CODE = 'crm_copilot_repeat_sale_engine_code';
	public const SETTINGS_REPEAT_SALE_SCREENING_ITEM_ENABLED_CODE = 'crm_copilot_repeat_sale_screening_item_enabled';
	public const SETTINGS_REPEAT_SALE_SCREENING_ITEM_ENGINE_CODE = 'crm_copilot_repeat_sale_screening_item_code';
	public const SETTINGS_ANALYZE_COMMUNICATION_ENABLED_CODE = 'crm_copilot_analyze_communication_enabled';
	public const SETTINGS_ANALYZE_COMMUNICATION_ENGINE_CODE = 'crm_copilot_analyze_communication_engine_code';
	public const SETTINGS_SUMMARIZE_ENABLED_CODE = 'crm_copilot_summarize_enabled';
	public const SETTINGS_SUMMARIZE_ENGINE_CODE = 'crm_copilot_summarize_engine_code';

	public const ENGINE_CATEGORY = 'text';

	private const SETTINGS_GROUP_CODE = 'crm_copilot';

	// region Tuning
	public static function onTuningLoad(): EventResult
	{
		$result = new EventResult();

		$items = [];
		$groups = [];
		$itemRelations = [];

		if (Engine::getByCategory(self::ENGINE_CATEGORY, Context::getFake()))
		{
			$items[self::SETTINGS_FILL_CRM_TEXT_ENABLED_CODE] = [
				'group' => Tuning\Defaults::GROUP_TEXT,
				'header' => Loc::getMessage(
					'CRM_INTEGRATION_AI_EVENTHANDLER_SETTINGS_FILL_TODO_TEXT_HEADER',
					['#COPILOT_NAME#' => AIManager::getCopilotName()],
				),
				'title' => Loc::getMessage('CRM_INTEGRATION_AI_EVENTHANDLER_SETTINGS_FILL_TODO_TEXT_TITLE'),
				'type' => Tuning\Type::BOOLEAN,
				'default' => true,
				'sort' => 600,
			];

			$items[self::SETTINGS_MESSAGESENDER_EDITOR_ENABLED_CODE] = [
				'group' => Tuning\Defaults::GROUP_TEXT,
				'header' => Loc::getMessage(
					'CRM_INTEGRATION_AI_EVENTHANDLER_SETTINGS_MESSAGESENDER_EDITOR_HEADER',
					['#COPILOT_NAME#' => AIManager::getCopilotName()],
				),
				'title' => Loc::getMessage('CRM_INTEGRATION_AI_EVENTHANDLER_SETTINGS_MESSAGESENDER_EDITOR_TITLE'),
				'type' => Tuning\Type::BOOLEAN,
				'default' => true,
				'sort' => 700,
			];
		}

		if (AIManager::isAiCallProcessingEnabled())
		{
			$groups[self::SETTINGS_GROUP_CODE] = [
				'title' => Loc::getMessage(
					'CRM_INTEGRATION_AI_EVENTHANDLER_SETTINGS_GROUP_TITLE',
					['#COPILOT_NAME#' => AIManager::getCopilotName()],
				),
				'description' => Loc::getMessage(
					'CRM_INTEGRATION_AI_EVENTHANDLER_SETTINGS_GROUP_DESCRIPTION',
					['#COPILOT_NAME#' => AIManager::getCopilotName()],
				),
				'helpdesk' => 18799442,
			];

			$quality = new Quality([
				Quality::QUALITIES['transcribe'],
			]);
			$items[self::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_AUDIO_CODE] = array_merge(
				Tuning\Defaults::getProviderSelectFieldParams(Engine::CATEGORIES['audio'], $quality),
				[
					'group' => self::SETTINGS_GROUP_CODE,
					'title' => Loc::getMessage('CRM_INTEGRATION_AI_EVENTHANDLER_SETTINGS_ENGINE_AUDIO_TITLE'),
					'sort' => 5,
				],
			);

			$items[self::SETTINGS_SUMMARIZE_ENABLED_CODE] = [
				'group' => self::SETTINGS_GROUP_CODE,
				'title' => Loc::getMessage(
					'CRM_INTEGRATION_AI_EVENTHANDLER_SETTING_SUMMARIZE_TITLE',
					['#COPILOT_NAME#' => AIManager::getCopilotName()],
				),
				'header' => Loc::getMessage(
					'CRM_INTEGRATION_AI_EVENTHANDLER_SETTINGS_SUMMARIZE_HEADER',
					['#COPILOT_NAME#' => AIManager::getCopilotName()],
				),
				'type' => Tuning\Type::BOOLEAN,
				'default' => true,
				'sort' => 10,
			];

			$items[self::SETTINGS_FILL_ITEM_FROM_CALL_ENABLED_CODE] = [
				'group' => self::SETTINGS_GROUP_CODE,
				'title' => Loc::getMessage(
					'CRM_INTEGRATION_AI_EVENTHANDLER_SETTINGS_FILL_ITEM_FROM_CALL_TITLE',
					['#COPILOT_NAME#' => AIManager::getCopilotName()],
				),
				'header' => Loc::getMessage(
					'CRM_INTEGRATION_AI_EVENTHANDLER_SETTINGS_FILL_ITEM_FROM_CALL_HEADER',
					['#COPILOT_NAME#' => AIManager::getCopilotName()],
				),
				'type' => Tuning\Type::BOOLEAN,
				'default' => true,
				'sort' => 20,
			];

			$quality = new Quality([
				Quality::QUALITIES['fields_highlight'],
				Quality::QUALITIES['translate'],
			]);
			$items[self::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_TEXT_CODE] = array_merge(
				Tuning\Defaults::getProviderSelectFieldParams(Engine::CATEGORIES['text'], $quality),
				[
					'group' => self::SETTINGS_GROUP_CODE,
					'title' => Loc::getMessage('CRM_INTEGRATION_AI_EVENTHANDLER_SETTINGS_ENGINE_TEXT_TITLE'),
					'sort' => 24,
				],
			);

			// Trade-off: TEXT engine is shared with Summarize, but tied to FillItemFromCall here
			// because the Tuning UI (ai-page.js) doesn't support OR-visibility across multiple
			// parents — registering under both would race show/hide on the same DOM node.
			$itemRelations[self::SETTINGS_FILL_ITEM_FROM_CALL_ENABLED_CODE] = [
				self::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_TEXT_CODE,
			];

			$items[self::SETTINGS_CALL_ASSESSMENT_ENABLED_CODE] = [
				'group' => self::SETTINGS_GROUP_CODE,
				'title' => Loc::getMessage(
					'CRM_INTEGRATION_AI_EVENTHANDLER_SETTING_CALL_ASSESSMENT_TITLE',
					['#COPILOT_NAME#' => AIManager::getCopilotName()],
				),
				'header' => Loc::getMessage(
					'CRM_INTEGRATION_AI_EVENTHANDLER_SETTINGS_CALL_ASSESSMENT_HEADER',
					['#COPILOT_NAME#' => AIManager::getCopilotName()],
				),
				'type' => Tuning\Type::BOOLEAN,
				'default' => true,
				'sort' => 30,
			];

			$quality = new Quality([
				Quality::QUALITIES['scoring'] ?? Quality::QUALITIES['translate'],
			]);

			$items[self::SETTINGS_CALL_ASSESSMENT_ENGINE_CODE] = [
				...Tuning\Defaults::getProviderSelectFieldParams(Engine::CATEGORIES['text'], $quality),
				'group' => self::SETTINGS_GROUP_CODE,
				'title' => Loc::getMessage('CRM_INTEGRATION_AI_EVENTHANDLER_SETTINGS_CALL_ASSESSMENT_ENGINE_TITLE'),
				'sort' => 32,
			];

			$itemRelations[self::SETTINGS_CALL_ASSESSMENT_ENABLED_CODE] = [
				self::SETTINGS_CALL_ASSESSMENT_ENGINE_CODE,
			];

			$availabilityChecker = Container::getInstance()->getRepeatSaleAvailabilityChecker();
			if ($availabilityChecker->isAvailable())
			{
				$items[self::SETTINGS_REPEAT_SALE_ENABLED_CODE] = [
					'group' => self::SETTINGS_GROUP_CODE,
					'title' => Loc::getMessage(
						'CRM_INTEGRATION_AI_EVENTHANDLER_SETTING_REPEAT_SALE_TITLE',
						['#COPILOT_NAME#' => AIManager::getCopilotName()],
					),
					'header' => Loc::getMessage(
						'CRM_INTEGRATION_AI_EVENTHANDLER_SETTINGS_REPEAT_SALE_HEADER',
						['#COPILOT_NAME#' => AIManager::getCopilotName()],
					),
					'type' => Tuning\Type::BOOLEAN,
					'default' => true,
					'sort' => 40,
				];

				$quality = new Quality([
					Quality::QUALITIES['scoring'] ?? Quality::QUALITIES['translate'],
				]);

				$items[self::SETTINGS_REPEAT_SALE_ENGINE_CODE] = [
					...Tuning\Defaults::getProviderSelectFieldParams(Engine::CATEGORIES['text'], $quality),
					'group' => self::SETTINGS_GROUP_CODE,
					'title' => Loc::getMessage('CRM_INTEGRATION_AI_EVENTHANDLER_SETTINGS_REPEAT_SALE_ENGINE_TITLE'),
					'sort' => 42,
				];

				$itemRelations[self::SETTINGS_REPEAT_SALE_ENABLED_CODE] = [
					self::SETTINGS_REPEAT_SALE_ENGINE_CODE,
				];
			}

			$items[self::SETTINGS_ANALYZE_COMMUNICATION_ENABLED_CODE] = [
				'group' => self::SETTINGS_GROUP_CODE,
				'title' => Loc::getMessage(
					'CRM_INTEGRATION_AI_EVENTHANDLER_SETTING_ANALYZE_COMMUNICATION_TITLE',
					['#COPILOT_NAME#' => AIManager::getCopilotName()],
				),
				'header' => Loc::getMessage(
					'CRM_INTEGRATION_AI_EVENTHANDLER_SETTINGS_ANALYZE_COMMUNICATION_HEADER',
					['#COPILOT_NAME#' => AIManager::getCopilotName()],
				),
				'type' => Tuning\Type::BOOLEAN,
				'default' => true,
				'sort' => 50,
			];

			$quality = new Quality([
				Quality::QUALITIES['scoring'] ?? Quality::QUALITIES['translate'],
			]);

			$items[self::SETTINGS_ANALYZE_COMMUNICATION_ENGINE_CODE] = [
				...Tuning\Defaults::getProviderSelectFieldParams(Engine::CATEGORIES['text'], $quality),
				'group' => self::SETTINGS_GROUP_CODE,
				'title' => Loc::getMessage('CRM_INTEGRATION_AI_EVENTHANDLER_SETTINGS_ANALYZE_COMMUNICATION_ENGINE_TITLE'),
				'sort' => 52,
			];

			$itemRelations[self::SETTINGS_ANALYZE_COMMUNICATION_ENABLED_CODE] = [
				self::SETTINGS_ANALYZE_COMMUNICATION_ENGINE_CODE,
			];
		}

		$result->modifyFields([
			'groups' => $groups,
			'items' => $items,
			'itemRelations' => [
				self::SETTINGS_GROUP_CODE => $itemRelations,
			],
		]);

		return $result;
	}
	// endregion

	// region Queue
	public static function onQueueJobExecute(Event $event): void
	{
		if (!AIManager::isAiCallProcessingEnabled())
		{
			return;
		}

		AIManager::logger()->info(
			'{date}: Received event {eventName}: {event}' . PHP_EOL,
			[
				'eventName' => __FUNCTION__,
				'event' => $event,
			],
		);

		$hash = self::getValidJobHash($event);
		if ($hash === null)
		{
			return;
		}

		$job = QueueTable::query()->setSelect(['*'])->where('HASH', $hash)->fetchObject();
		if (!self::isJobProcessable($job))
		{
			return;
		}

		// Pipeline continuation: determine scenario and launch next step
		// @todo: duplicate callback protection — if the AI queue delivers the same hash twice,
		// continueAfterCompletion may attempt to launch the next step twice.
		// AbstractOperation::checkPreviousJobs provides partial deduplication, but there is a
		// TOCTOU window between read and continueAfterCompletion. For AnalyzeCommunication this
		// can produce duplicate ToDo / EntityExclusion activities. Plan: add an atomic CONTINUED
		// flag on the job row (UPDATE ... WHERE id=? AND continued=0) and bail when affected_rows=0.
		$result = self::getQueueJobExecuteResult($event, $job);
		if ($result)
		{
			$nextTypeId = $job->requireNextTypeId();
			$scenarioName = ScenarioResolver::resolve(
				(int)$job->requireTypeId(),
				$nextTypeId !== null ? (int)$nextTypeId : null,
				self::extractScenarioNameFromEngineContext($event),
			);
			if ($scenarioName !== null)
			{
				ServiceLocator::getInstance()->get(PipelineExecutor::class)->continueAfterCompletion($result, $scenarioName);
			}
		}

		AIManager::logger()->debug(
			'{date}: Event {eventName} was processed with result {result}' . PHP_EOL,
			[
				'eventName' => __FUNCTION__,
				'result' => $result,
			],
		);
	}

	public static function onQueueJobFail(Event $event): void
	{
		if (!AIManager::isAiCallProcessingEnabled())
		{
			return;
		}

		AIManager::logger()->info(
			'{date}: Received event {eventName}: {event}' . PHP_EOL,
			[
				'eventName' => __FUNCTION__,
				'event' => $event,
			],
		);

		$hash = self::getValidJobHash($event);
		if ($hash === null)
		{
			return;
		}

		$job = QueueTable::query()->setSelect(['*'])->where('HASH', $hash)->fetchObject();
		if (!self::isJobProcessable($job))
		{
			return;
		}

		$operationClass = ServiceLocator::getInstance()->get(OperationRegistry::class)->getByTypeId((int)$job->requireTypeId());
		if ($operationClass !== null)
		{
			$operationClass::onQueueJobFail($event, $job);
		}
	}
	//endregion

	// region Activity
	public static function onAfterCallActivityAdd(array $activityFields): void
	{
		if (
			VoxImplantManager::isActivityBelongsToVoximplant($activityFields)
			&& Call::hasRecordings($activityFields)
		)
		{
			self::registerCallActivityWithAudioRecordingEvent($activityFields);
		}

		if (AutoLauncher::isEnabled())
		{
			(new AutoLauncher())->run(AutoLauncher\BaseChannelAutoStartStrategy::OPERATION_ADD, $activityFields);
		}
	}

	public static function onAfterCallActivityUpdate(array $changedFields, array $oldFields, array $newFields): void
	{
		if (
			VoxImplantManager::isActivityBelongsToVoximplant($newFields)
			// if records were added
			&& !Call::hasRecordings($oldFields)
			&& Call::hasRecordings($newFields)
		)
		{
			self::registerCallActivityWithAudioRecordingEvent($newFields);
		}

		if (AutoLauncher::isEnabled())
		{
			(new AutoLauncher())->run(AutoLauncher\BaseChannelAutoStartStrategy::OPERATION_UPDATE, $newFields, $changedFields);
		}
	}

	public static function onAfterRepeatSaleActivityAdd(array $activityFields): void
	{
		$activityId = (int)($activityFields['ID'] ?? 0);
		if ($activityId <= 0)
		{
			return;
		}

		$providerId = (string)($activityFields['PROVIDER_ID'] ?? '');
		if ($providerId !== RepeatSale::getId())
		{
			return;
		}

		$isAiEnabled = AIManager::isAiCallProcessingEnabled()
			&& AIManager::isEnabledInGlobalSettings(GlobalSetting::RepeatSale)
		;
		if (!$isAiEnabled)
		{
			return; // AI is disabled in global settings
		}

		$isAutoStartPossible = BaasManager::hasPackage();
		if (!$isAutoStartPossible)
		{
			return;
		}

		$isAiAutoStartEnabled = (bool)($activityFields['PROVIDER_PARAMS']['IS_AI_AUTO_START_ENABLED'] ?? false);
		if ($isAiAutoStartEnabled)
		{
			$job = JobRepository::getInstance()->getFillRepeatSaleTipsByActivity($activityId);
			if ($job)
			{
				return; // job already exists in 'b_crm_ai_queue' table
			}

			AiQueueBufferController::getInstance()->add(
				AiQueueBufferItem::createFromEntityFields([
					'PROVIDER_ID' => FillRepeatSaleTipsProvider::getId(),
					'PROVIDER_DATA' => [
						'activityId' => $activityId,
					]
				])
			);
		}
	}

	public static function onAfterOpenLineActivityComplete(array $changedFields, array $newFields): void
	{
		if (AutoLauncher::isEnabled())
		{
			(new AutoLauncher())->run(AutoLauncher\BaseChannelAutoStartStrategy::OPERATION_COMPLETE, $newFields, $changedFields);
		}
	}
	// endregion

	//region Recycle bin
	public static function onItemMoveToBin(ItemIdentifier $target, ItemIdentifier $recycleBinItem): void
	{
		QueueTable::deletePending($target);

		QueueTable::rebind($target, $recycleBinItem);
	}

	public static function onItemDelete(ItemIdentifier $target): void
	{
		QueueTable::deleteByItem($target);
	}

	public static function onItemRestoreFromRecycleBin(ItemIdentifier $target, ItemIdentifier $recycleBinItem): void
	{
		QueueTable::rebind($recycleBinItem, $target);
	}
	// endregion

	private static function isJobProcessable(?EO_Queue $job): bool
	{
		if (!$job)
		{
			return false;
		}

		if ($job->requireExecutionStatus() !== QueueTable::EXECUTION_STATUS_PENDING)
		{
			AIManager::logger()->debug(
				'{date}: Job is not in PENDING status: {job}' . PHP_EOL,
				[
					'job' => $job->collectValues(fieldsMask: FieldTypeMask::FLAT),
				],
			);

			return false;
		}

		if (in_array($job->requireEntityTypeId(), CCrmOwnerType::getAllSuspended(), true))
		{
			AIManager::logger()->debug(
				'{date}: Job entity is suspended: {job}' . PHP_EOL,
				['job' => $job->collectValues(fieldsMask: FieldTypeMask::FLAT)],
			);

			return false;
		}

		return true;
	}

	private static function getValidJobHash(Event $event): ?string
	{
		$hash = $event->getParameter('queue');

		return is_string($hash) && !empty($hash) ? $hash : null;
	}

	private static function getQueueJobExecuteResult(Event $event, EO_Queue $job): ?Result
	{
		$operationClass = ServiceLocator::getInstance()->get(OperationRegistry::class)->getByTypeId((int)$job->requireTypeId());
		if ($operationClass === null)
		{
			return null;
		}

		return $operationClass::onQueueJobExecute($event, $job);
	}

	private static function extractScenarioNameFromEngineContext(Event $event): ?string
	{
		$engine = $event->getParameter('engine');
		if (!($engine instanceof IEngine))
		{
			return null;

		}

		$context = $engine->getContext();
		$scenario = $context->getParameters()['additionalInfo']['scenario'] ?? null;

		return is_string($scenario) && $scenario !== '' ? $scenario : null;
	}

	private static function registerCallActivityWithAudioRecordingEvent(array $activityFields): void
	{
		$nullSafeInt = static fn(array $input, string $key) => (int)($input[$key] ?? null);

		$originId = $activityFields['ORIGIN_ID'] ?? '';
		$callId = VoxImplantManager::extractCallIdFromOriginId($originId);

		$builder = (new CallActivityWithAudioRecordingEvent())
			->setActivityOwnerTypeId($nullSafeInt($activityFields, 'OWNER_TYPE_ID'))
			->setActivityId($nullSafeInt($activityFields, 'ID'))
			->setActivityDirection($nullSafeInt($activityFields, 'DIRECTION'))
			->setCallDuration(VoxImplantManager::getCallDuration($callId) ?? 0)
			->setTelephonyType(((new VoxImplant\Call($callId))->getTelephonyType()))
		;
		$builder->buildEvent()->send();
		// send the same analytics only with different TOOL and CATEGORY
		$builder
			->setTool(Dictionary::TOOL_CRM)
			->setCategory(Dictionary::CATEGORY_AI_OPERATIONS)
			->buildEvent()
			->send()
		;
	}
}
