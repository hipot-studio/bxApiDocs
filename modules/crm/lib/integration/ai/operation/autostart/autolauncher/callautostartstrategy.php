<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\AutoLauncher;

use Bitrix\Crm\Activity\IncomingChannel;
use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItemChecker;
use Bitrix\Crm\Copilot\CallAssessment\ItemFactory;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Operation\AnalyzeCommunication;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings;
use Bitrix\Crm\Integration\AI\Operation\Autostart\ScoreCallSettings;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;
use Bitrix\Crm\Integration\AI\SuitableAudiosChecker;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\DateTime;
use CCrmOwnerType;

final class CallAutoStartStrategy extends BaseChannelAutoStartStrategy
{
	public function run(array $changedFields = []): void
	{
		$fillFieldsSettings = $this->getFillFieldsSettings();
		$scoreCallSettings = AIManager::isEnabledInGlobalSettings(GlobalSetting::CallAssessment)
			? $this->getScoreCallSettingsByActivity()
			: null;

		if ($fillFieldsSettings === null && $scoreCallSettings === null)
		{
			$this->logger->debug('{date}: Unable to autostart operation: launch options not found' . PHP_EOL);

			return;
		}

		$direction = (int)($this->activityFields['DIRECTION'] ?? 0);
		if (
			!$fillFieldsSettings?->shouldAutostart(TranscribeCallRecording::TYPE_ID, $direction)
			&& !$scoreCallSettings?->shouldAutostart(TranscribeCallRecording::TYPE_ID, $direction)
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
				'{date}: Trying to autostart operation after call activity was added.'
				. ' Autostart fill fields settings {fillFieldsSettings}, score call settings {scoreCallSettings}, activity {activity}' . PHP_EOL,
				[
					'fillFieldsSettings' => $fillFieldsSettings ?? null,
					'scoreCallSettings' => $scoreCallSettings ?? null,
					'activity' => $this->activityFields
				],
			);

			$storageTypeId = (int)($this->activityFields['STORAGE_TYPE_ID'] ?? null);
			$storageElementIds = $this->getStorageElementIds($this->activityFields);
		}
		elseif ($this->activityOperation === self::OPERATION_UPDATE)
		{
			$this->logger->info(
				'{date}: Trying to autostart operation after call activity was updated.'
				. ' Autostart fill fields settings {fillFieldsSettings}, score call settings {scoreCallSettings}, changed fields {changedFields}, new activity state {activity}' . PHP_EOL,
				[
					'fillFieldsSettings' => $fillFieldsSettings ?? null,
					'scoreCallSettings' => $scoreCallSettings ?? null,
					'activity' => $this->activityFields,
					'changedFields' => $changedFields,
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
			$this->logger->debug('{date}: Unable to autostart operation: AI operation in CRM is not possible' . PHP_EOL);

			return;
		}

		$scenario = $this->detectLaunchScenarioBySettings($fillFieldsSettings, $scoreCallSettings);
		if ($scenario !== Scenario::UNDEFINED_SCENARIO)
		{
			$this->logger->info(
				'{date}: Trying to autostart operation with type {operationType} with scenario "{scenario}"' . PHP_EOL,
				[
					'operationType' => TranscribeCallRecording::TYPE_ID,
					'scenario' => $scenario,
				]
			);

			AIManager::launchCallRecordingTranscription(
				$activityId,
				$scenario,
				$this->userId,
				$storageTypeId,
				max($storageElementIds),
				false,
			);
		}
	}

	private function detectLaunchScenarioBySettings(
		?FillFieldsSettings $fillFieldsSettings,
		?ScoreCallSettings $scoreCallSettings
	): string
	{
		$shouldFillFieldsStart = false;
		$shouldScoreCallStart = false;
		$shouldAnalyzeCommunicationStart = false;
		$direction = (int)($this->activityFields['DIRECTION'] ?? 0);
		$isFirstCallActivityWithFiles = null;

		if ($fillFieldsSettings?->shouldAutostart(TranscribeCallRecording::TYPE_ID, $direction))
		{
			$shouldStartFillFieldsChain = !$fillFieldsSettings->isAutostartTranscriptionOnlyOnFirstCallWithRecording();
			if (!$shouldStartFillFieldsChain)
			{
				$isFirstCallActivityWithFiles ??= $this->isFirstCallActivityWithFilesForItem();
				$shouldStartFillFieldsChain = $isFirstCallActivityWithFiles;
			}

			if ($shouldStartFillFieldsChain)
			{
				$shouldFillFieldsStart = self::shouldAutostartFillFields(
					AIManager::isEnabledInGlobalSettings(),
					AIManager::isEnabledInGlobalSettings(GlobalSetting::Summarize),
					$fillFieldsSettings->shouldAutostart(
						FillItemFieldsFromCallTranscription::TYPE_ID,
						$direction,
						false,
					),
				);
				$shouldAnalyzeCommunicationStart = AIManager::isEnabledInGlobalSettings(GlobalSetting::AnalyzeCommunication)
					&& $fillFieldsSettings->shouldAutostart(
						AnalyzeCommunication::TYPE_ID,
						$direction,
						false,
					)
				;
			}
		}

		if ($scoreCallSettings?->shouldAutostart(TranscribeCallRecording::TYPE_ID, $direction))
		{
			$shouldStartScoreCallChain = !$scoreCallSettings->isAutostartTranscriptionOnlyOnFirstCallWithRecording();
			if (!$shouldStartScoreCallChain)
			{
				$isFirstCallActivityWithFiles ??= $this->isFirstCallActivityWithFilesForItem();
				$shouldStartScoreCallChain = $isFirstCallActivityWithFiles;
			}

			if ($shouldStartScoreCallChain)
			{
				$shouldScoreCallStart = $scoreCallSettings->shouldAutostart(ScoreCall::TYPE_ID, $direction);
			}
		}

		$shouldSummarizeStart = false;
		if (
			self::shouldAutostartSummarize(
				$shouldFillFieldsStart,
				AIManager::isEnabledInGlobalSettings(GlobalSetting::Summarize),
				$fillFieldsSettings?->shouldAutostart(TranscribeCallRecording::TYPE_ID, $direction) ?? false,
			)
		)
		{
			if ($fillFieldsSettings?->isAutostartTranscriptionOnlyOnFirstCallWithRecording())
			{
				$shouldSummarizeStart = $this->isFirstCallActivityWithFilesForItem();
			}
			else
			{
				$shouldSummarizeStart = true;
			}
		}

		return self::resolveLaunchScenario(
			$shouldFillFieldsStart,
			$shouldScoreCallStart,
			$shouldAnalyzeCommunicationStart,
			$shouldSummarizeStart,
		);
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

	private static function resolveLaunchScenario(
		bool $shouldFillFieldsStart,
		bool $shouldScoreCallStart,
		bool $shouldAnalyzeCommunicationStart,
		bool $shouldSummarizeStart = false,
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
			return Scenario::CALL_SCORING_SCENARIO;
		}

		if ($shouldSummarizeStart)
		{
			return Scenario::SUMMARIZE_SCENARIO;
		}

		if ($shouldAnalyzeCommunicationStart)
		{
			return Scenario::ANALYZE_COMMUNICATION_SCENARIO;
		}

		return Scenario::UNDEFINED_SCENARIO;
	}

	private function isFirstCallActivityWithFilesForItem(): bool
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

	private function getScoreCallSettingsByActivity(): ?ScoreCallSettings
	{
		$activityId = (int)($this->activityFields['ID'] ?? 0);
		if ($activityId <= 0)
		{
			return null;
		}

		$callAssessmentItem = ItemFactory::getByActivityId($activityId);
		$checkerResult = CallAssessmentItemChecker::getInstance()->setItem($callAssessmentItem)->run();
		if (!$checkerResult->isSuccess())
		{
			return null;
		}

		return new ScoreCallSettings($callAssessmentItem?->getAutoCheckTypeId());
	}
}
