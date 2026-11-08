<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\AutoLauncher;

use Bitrix\Crm\Activity\IncomingChannel;
use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItem;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItemChecker;
use Bitrix\Crm\Copilot\CallAssessment\CriteriaLoader;
use Bitrix\Crm\Copilot\CallAssessment\ItemFactory;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Operation\Autostart\CallAssessmentRuntimePolicy;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings;
use Bitrix\Crm\Integration\AI\Operation\Autostart\ScenarioOverrideResolver;
use Bitrix\Crm\Integration\AI\Operation\Autostart\Slider\AutomationScenarioRegistry;
use Bitrix\Crm\Integration\AI\Operation\Autostart\Slider\GlobalFeatureReader;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\ScoreCallV2;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;
use Bitrix\Crm\Integration\AI\SuitableAudiosChecker;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Settings\CopilotSettings;
use Bitrix\Crm\Timeline\AI\Controller;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use CCrmOwnerType;

class CallAutoStartStrategy extends BaseChannelAutoStartStrategy
{
	private readonly CallAssessmentRuntimePolicy $callAssessmentRuntimePolicy;
	private readonly ScenarioOverrideResolver $scenarioOverrideResolver;
	private readonly CallAutostartLaunchService $launchService;
	private ?bool $isTranscriptionRuntimeReady = null;
	private ?CallAssessmentItem $scoreCallAssessmentItem = null;
	private bool $isScoreCallAssessmentItemResolved = false;

	public function __construct(
		int $activityOperation,
		array $activityFields,
		GlobalFeatureReader $featureReader = new GlobalFeatureReader(),
		?CallAutostartLaunchService $launchService = null,
	)
	{
		parent::__construct($activityOperation, $activityFields, $featureReader);

		$this->callAssessmentRuntimePolicy = new CallAssessmentRuntimePolicy();
		$this->scenarioOverrideResolver = new ScenarioOverrideResolver();
		$this->launchService = $launchService ?? new CallAutostartLaunchService();
	}

	public function run(array $changedFields = []): void
	{
		$fillFieldsSettings = $this->getUnifiedAutostartSettings();

		if ($fillFieldsSettings === null)
		{
			$this->logger->debug('{date}: Unable to autostart operation: launch options not found' . PHP_EOL);

			return;
		}

		$direction = (int)($this->activityFields['DIRECTION'] ?? 0);
		$automationAllowed = $this->featureReader->isPortalAutomationAllowed();
		if (!$automationAllowed)
		{
			return;
		}

		if (
			!$this->isAnyCallScenarioActive(
				$fillFieldsSettings,
				$direction,
				fn(): ?CallAssessmentItem => $this->resolveScoreCallAssessmentItem(),
				$automationAllowed,
			)
		)
		{
			return;
		}

		$activityId = (int)($this->activityFields['ID'] ?? null);
		$storageTypeId = 0;
		$storageElementIds = [];
		$isJobOfSameTypeNotExistsForTarget = true;

		if ($this->activityOperation === self::OPERATION_ADD)
		{
			$this->logger->info(
				'{date}: Trying to autostart operation after call activity {activityId} was added,'
				. ' assessment {assessment}' . PHP_EOL,
				[
					'activityId' => $activityId,
					'assessment' => $this->describeResolvedAssessmentItem(),
				],
			);

			$storageTypeId = (int)($this->activityFields['STORAGE_TYPE_ID'] ?? null);
			$storageElementIds = $this->getStorageElementIds($this->activityFields);
		}
		elseif ($this->activityOperation === self::OPERATION_UPDATE)
		{
			$this->logger->info(
				'{date}: Trying to autostart operation after call activity {activityId} was updated,'
				. ' assessment {assessment}, changed fields {changedFieldsKeys}' . PHP_EOL,
				[
					'activityId' => $activityId,
					'assessment' => $this->describeResolvedAssessmentItem(),
					'changedFieldsKeys' => array_keys($changedFields),
				],
			);

			$storageTypeId = (int)($changedFields['STORAGE_TYPE_ID'] ?? null);
			$storageElementIds = $this->getStorageElementIds($changedFields);
			$isJobOfSameTypeNotExistsForTarget = !JobRepository::getInstance()->isJobOfSameTypeAlreadyExistsForTarget(
				new ItemIdentifier(CCrmOwnerType::Activity, $activityId),
				TranscribeCallRecording::TYPE_ID,
			);
		}

		$isLaunchPossible = $isJobOfSameTypeNotExistsForTarget
			&& $this->isLaunchPossible($activityId, $storageTypeId, $storageElementIds)
		;
		if (!$isLaunchPossible)
		{
			$this->logger->debug(
				'{date}: Unable to autostart operation: AI operation in CRM is not possible,'
				. ' assessment {assessment}' . PHP_EOL,
				[
					'assessment' => $this->describeResolvedAssessmentItem(),
				],
			);

			return;
		}

		$launchPlan = $this->detectLaunchPlanBySettings(
			$fillFieldsSettings,
			fn(): ?CallAssessmentItem => $this->resolveScoreCallAssessmentItem(),
			$automationAllowed,
		);
		$scenario = $launchPlan['scenario'];
		if ($scenario !== Scenario::UNDEFINED_SCENARIO)
		{
			$this->logger->info(
				'{date}: Trying to autostart operation with type {operationType} with scenario "{scenario}",'
				. ' assessment {assessment}' . PHP_EOL,
				[
					'operationType' => TranscribeCallRecording::TYPE_ID,
					'scenario' => $scenario,
					'assessment' => $this->describeResolvedAssessmentItem(),
				]
			);

			$launchResult = $this->launchService->launch(
				$activityId,
				$scenario,
				$this->userId,
				$storageTypeId,
				max($storageElementIds),
				$launchPlan['shouldStartCallAssessment'],
				$launchPlan['fillFieldsEntityAllowed'],
			);
			if (!$launchResult->isSuccess())
			{
				$this->handleLaunchError(
					$launchResult,
					$this->nextTarget,
					$activityId,
					$this->userId,
					$scenario,
				);
			}
		}
	}

	private function handleLaunchError(
		Result $launchResult,
		ItemIdentifier $target,
		int $activityId,
		int $userId,
		string $scenario,
	): void
	{
		$errorMessages = $launchResult->getErrorMessages();
		$this->logger->error(
			'{date}: Unable to autostart call scenario {scenario}: {errors}' . PHP_EOL,
			[
				'scenario' => $scenario,
				'errors' => $errorMessages,
			],
		);

		$callAssessmentLaunchError = null;
		foreach ($launchResult->getErrors() as $error)
		{
			if ($error->getCode() === ErrorCode::CALL_ASSESSMENT_TRIGGER_LAUNCH_FAILED)
			{
				$callAssessmentLaunchError = $error;
			}
		}

		if ($callAssessmentLaunchError === null)
		{
			return;
		}

		$this->notifyAboutCallAssessmentLaunchError(
			$target,
			$activityId,
			[
				'OPERATION_TYPE_ID' => ScoreCallV2::TYPE_ID,
				'ENGINE_ID' => 0,
				'SCENARIO' => $scenario,
				'ERRORS' => [$callAssessmentLaunchError->getMessage()],
				'ERROR_CODES' => [ErrorCode::CALL_ASSESSMENT_TRIGGER_LAUNCH_FAILED],
			],
			$userId,
		);
	}

	protected function notifyAboutCallAssessmentLaunchError(
		ItemIdentifier $target,
		int $activityId,
		array $settings,
		int $userId,
	): void
	{
		Controller::getInstance()->onLaunchError($target, $activityId, $settings, $userId);
	}

	/**
	 * @param callable(): ?CallAssessmentItem $callAssessmentItemProvider is called on the legacy branch only.
	 */
	private function isAnyCallScenarioActive(
		?FillFieldsSettings $fillFieldsSettings,
		int $direction,
		callable $callAssessmentItemProvider,
		bool $automationAllowed,
	): bool
	{
		if ($fillFieldsSettings === null)
		{
			return false;
		}

		if (
			$this->scenarioOverrideResolver->isAnyScenarioActive(
				$fillFieldsSettings,
				AutomationScenarioRegistry::CHANNEL_CALL,
				$direction,
			)
		)
		{
			return true;
		}

		if (AIManager::isCallScoringV2Enabled())
		{
			return false;
		}

		return $this->isCallAssessmentActiveByLegacyDomain(
			$callAssessmentItemProvider(),
			$direction,
			$automationAllowed,
		);
	}

	private function isCallAssessmentActiveByLegacyDomain(
		?CallAssessmentItem $callAssessmentItem,
		int $direction,
		bool $automationAllowed,
	): bool
	{
		return $this->callAssessmentRuntimePolicy->allows(
			$callAssessmentItem,
			TranscribeCallRecording::TYPE_ID,
			$direction,
			$automationAllowed,
		);
	}

	private function detectLaunchScenarioBySettings(
		?FillFieldsSettings $fillFieldsSettings,
		?CallAssessmentItem $callAssessmentItem,
		bool $automationAllowed = true,
	): string
	{
		return $this->detectLaunchPlanBySettings(
			$fillFieldsSettings,
			static fn(): ?CallAssessmentItem => $callAssessmentItem,
			$automationAllowed,
		)['scenario'];
	}

	/**
	 * @param callable(): ?CallAssessmentItem $callAssessmentItemProvider is called only where the assessment
	 *        setting decides the plan, so a call with no active assessment scenario never looks it up.
	 * @return array{scenario: string, shouldStartCallAssessment: bool, fillFieldsEntityAllowed: bool}
	 */
	private function detectLaunchPlanBySettings(
		?FillFieldsSettings $fillFieldsSettings,
		callable $callAssessmentItemProvider,
		bool $automationAllowed = true,
	): array
	{
		$shouldFillFieldsStart = false;
		$shouldScoreCallStart = false;
		$shouldAnalyzeCommunicationStart = false;
		$direction = (int)($this->activityFields['DIRECTION'] ?? 0);
		$isFirstCallActivityWithFiles = null;
		$fillFieldsEntityAllowed = true;
		$isTranscriptionRuntimeReady = $this->isTranscriptionRuntimeReady();
		$isTranscriptionActive = $fillFieldsSettings !== null
			&& $this->scenarioOverrideResolver->isScenarioActive(
				$fillFieldsSettings,
				AutomationScenarioRegistry::SCENARIO_TRANSCRIPTION,
				AutomationScenarioRegistry::CHANNEL_CALL,
				$direction,
			)
		;
		// Standalone transcription starts ONLY by an explicit slider override (no legacy channel default).
		$isTranscriptionEnabledByOverride = $fillFieldsSettings !== null
			&& $this->scenarioOverrideResolver->isScenarioEnabledByOverride(
				$fillFieldsSettings,
				AutomationScenarioRegistry::SCENARIO_TRANSCRIPTION,
				AutomationScenarioRegistry::CHANNEL_CALL,
				$direction,
			)
		;
		$shouldTranscriptionOnlyStart = $isTranscriptionRuntimeReady && $isTranscriptionEnabledByOverride;
		if (
			$shouldTranscriptionOnlyStart
			&& $this->scenarioOverrideResolver->isFirstOnlyMode(
				$fillFieldsSettings,
				AutomationScenarioRegistry::SCENARIO_TRANSCRIPTION,
				AutomationScenarioRegistry::CHANNEL_CALL,
			)
		)
		{
			$isFirstCallActivityWithFiles ??= $this->isFirstCallActivityWithFilesForItem();
			$shouldTranscriptionOnlyStart = $isFirstCallActivityWithFiles;
		}

		if (
			$fillFieldsSettings !== null
			&& $this->isAnyCallScenarioActive(
				$fillFieldsSettings,
				$direction,
				$callAssessmentItemProvider,
				$automationAllowed,
			)
		)
		{
			$entityTypeId = $this->nextTarget?->getEntityTypeId() ?? 0;
			$fillFieldsEntityAllowed = CopilotSettings::getCurrent()->isFillFieldsEnabledFor($entityTypeId);

			$shouldStartFillFieldsChain = !$this->scenarioOverrideResolver->isFirstOnlyMode(
				$fillFieldsSettings,
				AutomationScenarioRegistry::SCENARIO_FILL_FIELDS,
				AutomationScenarioRegistry::CHANNEL_CALL,
			);
			if (!$shouldStartFillFieldsChain)
			{
				$isFirstCallActivityWithFiles ??= $this->isFirstCallActivityWithFilesForItem();
				$shouldStartFillFieldsChain = $isFirstCallActivityWithFiles;
			}

			if ($shouldStartFillFieldsChain)
			{
				$isFillFieldsAutostartRequested = self::shouldAutostartCallDependentScenario(
					$isTranscriptionRuntimeReady,
					$isTranscriptionActive,
					$this->scenarioOverrideResolver->isScenarioActive(
						$fillFieldsSettings,
						AutomationScenarioRegistry::SCENARIO_FILL_FIELDS,
						AutomationScenarioRegistry::CHANNEL_CALL,
						$direction,
					),
				);
				if (
					!$fillFieldsEntityAllowed
					&& $isFillFieldsAutostartRequested
					&& AIManager::isEnabledInGlobalSettings(GlobalSetting::Summarize)
					&& AIManager::isEnabledInGlobalSettings(GlobalSetting::FillItemFromCall)
				)
				{
					$this->logger->debug(
						'{date}: Fill fields autostart is disabled for entity type {entityTypeId}' . PHP_EOL,
						['entityTypeId' => $entityTypeId],
					);
				}

				$shouldFillFieldsStart = self::shouldAutostartFillFields(
					AIManager::isFillFieldsEnabledForEntityType($entityTypeId),
					AIManager::isEnabledInGlobalSettings(GlobalSetting::Summarize),
					$isFillFieldsAutostartRequested,
				);
				$shouldAnalyzeCommunicationStart = AIManager::isEnabledInGlobalSettings(GlobalSetting::AnalyzeCommunication)
					&& self::shouldAutostartCallDependentScenario(
						$isTranscriptionRuntimeReady,
						$isTranscriptionActive,
						$this->scenarioOverrideResolver->isScenarioActive(
							$fillFieldsSettings,
							AutomationScenarioRegistry::SCENARIO_ANALYZE_COMMUNICATION,
							AutomationScenarioRegistry::CHANNEL_CALL,
							$direction,
						),
					)
				;
			}
		}

		if (AIManager::isCallScoringV2Enabled())
		{
			// The setting is looked up after the scenario check on purpose: it is the expensive part of the
			// branch, and a call with the assessment scenario switched off must not pay for it.
			$isAssessmentScenarioActive = $fillFieldsSettings !== null
				&& self::shouldAutostartCallDependentScenario(
					$isTranscriptionRuntimeReady,
					$isTranscriptionActive,
					$this->scenarioOverrideResolver->isScenarioActive(
						$fillFieldsSettings,
						AutomationScenarioRegistry::SCENARIO_CALL_ASSESSMENT,
						AutomationScenarioRegistry::CHANNEL_CALL,
						$direction,
					),
				)
			;
			$callAssessmentItem = $isAssessmentScenarioActive ? $callAssessmentItemProvider() : null;
			if ($callAssessmentItem !== null)
			{
				$shouldStartScoreCallChain = !$this->scenarioOverrideResolver->isFirstOnlyMode(
					$fillFieldsSettings,
					AutomationScenarioRegistry::SCENARIO_CALL_ASSESSMENT,
					AutomationScenarioRegistry::CHANNEL_CALL,
				);
				if (!$shouldStartScoreCallChain)
				{
					$isFirstCallActivityWithFiles ??= $this->isFirstCallActivityWithFilesForItem();
					$shouldStartScoreCallChain = $isFirstCallActivityWithFiles;
				}

				// The setting found here answers WHETHER the assessment starts, and that is all it is used
				// for. WHICH setting scores the call is decided later by the script selector, over the whole
				// candidate list the same gates admit - see ItemFactory::getCandidateIdsByActivityId().
				$shouldScoreCallStart = $shouldStartScoreCallChain;
			}
		}
		else
		{
			// the legacy domain decides by the setting itself, so here it is needed in any case
			$callAssessmentItem = $callAssessmentItemProvider();
			if ($this->callAssessmentRuntimePolicy->allows($callAssessmentItem, TranscribeCallRecording::TYPE_ID, $direction, $automationAllowed))
			{
				$shouldStartScoreCallChain = !$this->callAssessmentRuntimePolicy->isFirstIncomingOnly($callAssessmentItem);
				if (!$shouldStartScoreCallChain)
				{
					$isFirstCallActivityWithFiles ??= $this->isFirstCallActivityWithFilesForItem();
					$shouldStartScoreCallChain = $isFirstCallActivityWithFiles;
				}

				if ($shouldStartScoreCallChain)
				{
					$shouldScoreCallStart = $this->callAssessmentRuntimePolicy->allows(
						$callAssessmentItem,
						ScoreCall::TYPE_ID,
						$direction,
						$automationAllowed,
					);
				}
			}
		}

		$shouldSummarizeStart = false;
		$summarizeActive = $fillFieldsSettings !== null
			&& self::shouldAutostartCallDependentScenario(
				$isTranscriptionRuntimeReady,
				$isTranscriptionActive,
				$this->scenarioOverrideResolver->isScenarioActive(
					$fillFieldsSettings,
					AutomationScenarioRegistry::SCENARIO_SUMMARIZE,
					AutomationScenarioRegistry::CHANNEL_CALL,
					$direction,
				),
			)
		;
		if (
			self::shouldAutostartSummarize(
				$shouldFillFieldsStart,
				AIManager::isEnabledInGlobalSettings(GlobalSetting::Summarize),
				$summarizeActive,
			)
		)
		{
			$summarizeFirstOnly = $fillFieldsSettings !== null && $this->scenarioOverrideResolver->isFirstOnlyMode(
				$fillFieldsSettings,
				AutomationScenarioRegistry::SCENARIO_SUMMARIZE,
				AutomationScenarioRegistry::CHANNEL_CALL,
			);
			if ($summarizeFirstOnly)
			{
				$isFirstCallActivityWithFiles ??= $this->isFirstCallActivityWithFilesForItem();
				$shouldSummarizeStart = $isFirstCallActivityWithFiles;
			}
			else
			{
				$shouldSummarizeStart = true;
			}
		}

		if ($shouldFillFieldsStart || $shouldAnalyzeCommunicationStart || $shouldSummarizeStart)
		{
			$shouldTranscriptionOnlyStart = false;
		}

		return [
			'scenario' => self::resolveLaunchScenario(
				$shouldFillFieldsStart,
				$shouldScoreCallStart,
				$shouldAnalyzeCommunicationStart,
				$shouldSummarizeStart,
				$shouldTranscriptionOnlyStart,
			),
			'shouldStartCallAssessment' => AIManager::isCallScoringV2Enabled() && $shouldScoreCallStart,
			'fillFieldsEntityAllowed' => $fillFieldsEntityAllowed,
		];
	}

	private static function shouldAutostartFillFields(
		bool $isFillFieldsEnabled,
		bool $isSummarizeEnabled,
		bool $shouldAutostartFillFields,
	): bool
	{
		// FillFields autostart always goes through Summarize as a prerequisite step.
		return $isFillFieldsEnabled
			&& $isSummarizeEnabled
			&& $shouldAutostartFillFields
		;
	}

	private static function shouldAutostartSummarize(
		bool $shouldFillFieldsStart,
		bool $isSummarizeEnabled,
		bool $shouldAutostartTranscription,
	): bool
	{
		// FillFields already includes summarize in its own chain.
		// ScoreCall and AnalyzeCommunication must still be able to combine with Summarize via FULL.
		return !$shouldFillFieldsStart
			&& $isSummarizeEnabled
			&& $shouldAutostartTranscription
		;
	}

	private static function shouldAutostartCallDependentScenario(
		bool $isTranscriptionRuntimeReady,
		bool $isTranscriptionActive,
		bool $isScenarioActive,
	): bool
	{
		return $isTranscriptionRuntimeReady
			&& $isTranscriptionActive
			&& $isScenarioActive
		;
	}

	private static function resolveLaunchScenario(
		bool $shouldFillFieldsStart,
		bool $shouldScoreCallStart,
		bool $shouldAnalyzeCommunicationStart,
		bool $shouldSummarizeStart = false,
		bool $shouldTranscriptionOnlyStart = false,
	): string
	{
		$enabledScenariosCount =
			(int)$shouldFillFieldsStart
			+ (int)$shouldScoreCallStart
			+ (int)$shouldAnalyzeCommunicationStart
			+ (int)$shouldSummarizeStart
		;

		if ($enabledScenariosCount > 1)
		{
			return Scenario::FULL_SCENARIO;
		}

		if ($shouldFillFieldsStart)
		{
			return Scenario::FILL_FIELDS_SCENARIO;
		}

		if ($shouldScoreCallStart)
		{
			return Scenario::resolveCallScoringScenarioName();
		}

		if ($shouldSummarizeStart)
		{
			return Scenario::SUMMARIZE_SCENARIO;
		}

		if ($shouldAnalyzeCommunicationStart)
		{
			return Scenario::ANALYZE_COMMUNICATION_SCENARIO;
		}

		if ($shouldTranscriptionOnlyStart)
		{
			return Scenario::TRANSCRIBE_RECORD_SCENARIO;
		}

		return Scenario::UNDEFINED_SCENARIO;
	}

	protected function isFirstCallActivityWithFilesForItem(): bool
	{
		$activityFields = $this->activityFields;
		$possibleTarget = $this->nextTarget;

		$this->logger->debug(
			'{date}: Trying to determine if the activity is first call activity with files for item: {activity}' . PHP_EOL,
			[
				'activity' => $activityFields,
			],
		);

		$allOtherCallActivityIdsOfTarget = ActivityTable::query()
			->setSelect(['ID'])
			->where('PROVIDER_ID', Call::getId())
			->whereNotNull('ORIGIN_ID') // check that it's a real call from voximplant
			->where('BINDINGS.OWNER_TYPE_ID', $possibleTarget->getEntityTypeId())
			->where('BINDINGS.OWNER_ID', $possibleTarget->getEntityId())
			->setLimit(100)
			->fetchCollection()
			->getIdList()
		;

		// exclude activity that we are testing right now
		$allOtherCallActivityIdsOfTarget = array_diff($allOtherCallActivityIdsOfTarget, [(int)$activityFields['ID']]);
		if (empty($allOtherCallActivityIdsOfTarget))
		{
			$this->logger->debug(
				'{date}: No other call activities found for target {target} {activity}' . PHP_EOL,
				[
					'target' => $possibleTarget,
					'activity' => $activityFields,
				],
			);

			return true;
		}

		$incomingCallsActivityIds = IncomingChannel::getInstance()->getIncomingChannelActivityIds(
			$allOtherCallActivityIdsOfTarget,
		);
		if (empty($incomingCallsActivityIds))
		{
			$this->logger->debug(
				'{date}: All call activities found for target {target} are not incoming {ids} {activity}' . PHP_EOL,
				[
					'target' => $possibleTarget,
					'ids' => $allOtherCallActivityIdsOfTarget,
					'activity' => $activityFields,
				],
			);

			return true;
		}

		$createdTime = $activityFields['CREATED'] ?? null;
		if (is_string($createdTime))
		{
			try
			{
				$createdTime = DateTime::createFromUserTime($createdTime);
			}
			catch (ObjectException)
			{
				$createdTime = null;
			}
		}

		if (!($createdTime instanceof DateTime))
		{
			$this->logger->error(
				'{date}: Didnt find valid CREATED time in activity fields: {activity}' . PHP_EOL,
				[
					'activity' => $activityFields,
				],
			);

			return false;
		}

		$previousCalls = ActivityTable::query()
			->setSelect(['ID', 'STORAGE_ELEMENT_IDS', 'STORAGE_TYPE_ID', 'ORIGIN_ID'])
			->whereIn('ID', $incomingCallsActivityIds)
			->where('CREATED', '<', $createdTime)
			->fetchCollection()
		;
		if (empty($previousCalls))
		{
			$this->logger->debug(
				'{date}: All previous calls were created after the given activity: {activity}' . PHP_EOL,
				[
					'activity' => $activityFields,
				]
			);

			return true;
		}

		$emptyArraySerializedString = serialize([]);
		foreach ($previousCalls as $previousCall)
		{
			// if a call has any files, we consider that it has recordings
			if (
				!empty($previousCall->requireStorageElementIds())
				&& $previousCall->requireStorageElementIds() !== $emptyArraySerializedString
				&& (new SuitableAudiosChecker($previousCall->requireOriginId(), $previousCall->requireStorageTypeId(), $previousCall->requireStorageElementIds()))
					->run()
					->isSuccess()
			)
			{
				$this->logger->debug(
					'{date}: Found a call activity that was created before and has files: {id}' . PHP_EOL,
					[
						'ID' => $previousCall->getId(),
					]
				);

				return false;
			}
		}

		$this->logger->debug('{date}: No other call activity with files found' . PHP_EOL);

		return true;
	}

	private function isLaunchPossible(int $activityId, int $storageTypeId, array $storageElementIds): bool
	{
		$originId = (string)($this->activityFields['ORIGIN_ID'] ?? '');

		return $activityId > 0
			&& $this->nextTarget
			&& $this->userId > 0
			&& Call::hasRecordings($this->activityFields)
			&& (new SuitableAudiosChecker($originId, $storageTypeId, serialize($storageElementIds)))
				->run()
				->isSuccess()
		;
	}

	private function getStorageElementIds(array $activityFields): array
	{
		$storageElementIds = (array)($activityFields['STORAGE_ELEMENT_IDS'] ?? []);

		return array_filter(
			array_map('intval', $storageElementIds),
			static fn(int $id) => $id > 0
		);
	}

	private function getUnifiedAutostartSettings(): ?FillFieldsSettings
	{
		$settings = $this->getFillFieldsSettings();
		if (
			$settings === null
			&& $this->nextTarget
			&& (
				AIManager::isEnabledInGlobalSettings(GlobalSetting::CallAssessment)
				|| $this->isTranscriptionRuntimeReady()
			)
		)
		{
			$settings = FillFieldsSettings::get(
				$this->nextTarget->getEntityTypeId(),
				$this->nextTarget->getCategoryId(),
			);
		}

		return $settings;
	}

	private function isTranscriptionRuntimeReady(): bool
	{
		return $this->isTranscriptionRuntimeReady ??= $this->featureReader->isScenarioRuntimeReady(
			AutomationScenarioRegistry::SCENARIO_TRANSCRIPTION,
		);
	}

	private function resolveScoreCallAssessmentItem(): ?CallAssessmentItem
	{
		if (!$this->isScoreCallAssessmentItemResolved)
		{
			$this->scoreCallAssessmentItem = $this->getScoreCallAssessmentItem();
			$this->isScoreCallAssessmentItemResolved = true;
		}

		return $this->scoreCallAssessmentItem;
	}

	/**
	 * Diagnostics must not start the lookup on their own, so the records report only what the launch
	 * decisions have already resolved by that point.
	 */
	private function describeResolvedAssessmentItem(): string
	{
		if (!$this->isScoreCallAssessmentItemResolved)
		{
			return 'not resolved';
		}

		return $this->scoreCallAssessmentItem === null
			? 'none'
			: (string)$this->scoreCallAssessmentItem->getId()
		;
	}

	protected function getScoreCallAssessmentItem(): ?CallAssessmentItem
	{
		$activityId = (int)($this->activityFields['ID'] ?? 0);
		if (
			$activityId <= 0
			|| !AIManager::isEnabledInGlobalSettings(GlobalSetting::CallAssessment)
		)
		{
			return null;
		}

		// The V2 script selector takes only settings with criteria, so the selection itself is limited the
		// same way: otherwise the newest matching setting hides a ready one behind it and the autostart of
		// the whole call is lost. A setting pinned to the call keeps its exemption there and is rejected
		// below instead - an explicit choice must not be silently replaced by another setting.
		$callAssessmentItem = ItemFactory::getByActivityId(
			$activityId,
			requireScoringCriteria: AIManager::isCallScoringV2Enabled(),
			requireCurrentAvailability: AIManager::isCallScoringV2Enabled(),
		);
		$checkerResult = CallAssessmentItemChecker::getInstance()->setItem($callAssessmentItem)->run();
		if (!$checkerResult->isSuccess())
		{
			return null;
		}

		// The setting pinned to the call comes back from the selection above regardless of its criteria, and
		// under V2 the checker accepts any enabled setting - so without this check the autostart would spend
		// a transcription on an assessment that cannot run on the pinned setting.
		if (AIManager::isCallScoringV2Enabled() && !$this->hasScoringCriteria($callAssessmentItem))
		{
			return null;
		}

		return $callAssessmentItem;
	}

	private function hasScoringCriteria(CallAssessmentItem $callAssessmentItem): bool
	{
		return !empty((new CriteriaLoader())->loadForAssessment($callAssessmentItem->getId()));
	}
}
