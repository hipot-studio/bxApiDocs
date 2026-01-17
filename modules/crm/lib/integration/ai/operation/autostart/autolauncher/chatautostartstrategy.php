<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\AutoLauncher;

use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings\ChatChannelSettings;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;

final class ChatAutoStartStrategy extends BaseChannelAutoStartStrategy
{
	public function run(array $changedFields = []): void
	{
		$fillFieldsSettings = $this->getFillFieldsSettings();
		if ($fillFieldsSettings === null)
		{
			$this->logger->debug('{date}: Unable to autostart operation: launch options not found' . PHP_EOL);

			return;
		}

		$fillFieldsSettingsChat = $fillFieldsSettings->getChannelSettings(ChatChannelSettings::CHANNEL_TYPE);
		if ($fillFieldsSettingsChat === null)
		{
			$this->logger->debug('{date}: Unable to get chat autostart operation: launch options not found' . PHP_EOL);

			return;
		}

		$shouldFillFieldsStart = false;
		if ($fillFieldsSettingsChat->shouldAutostart(SummarizeCallTranscription::TYPE_ID))
		{
			$shouldFillFieldsStart = !$fillFieldsSettingsChat->isAutostartOnlyFirstChat() || $this->isFirstOpenLineActivityForItem();
		}

		if (!$shouldFillFieldsStart)
		{
			return;
		}

		$this->logger->info(
			'{date}: Trying to autostart operation after completing the open line dialog.'
			. ' Autostart fill fields settings {fillFieldsSettings}, changed fields {changedFields}, new activity state {activity}' . PHP_EOL,
			[
				'fillFieldsSettings' => $fillFieldsSettingsChat,
				'activity' => $this->activityFields,
				'changedFields' => $changedFields,
			],
		);

		$activityId = (int)($this->activityFields['ID'] ?? null);
		if (!$this->isLaunchPossible($activityId))
		{
			$this->logger->debug('{date}: Unable to autostart operation: AI operation in CRM is not possible' . PHP_EOL);

			return;
		}

		AIManager::launchSummarizeData($activityId, $this->userId, false);
	}

	private function isLaunchPossible(int $activityId): bool
	{
		return $activityId > 0
			&& $this->nextTarget
			&& $this->userId > 0
			&& OpenLine::isCopilotProcessingAvailable($activityId)
		;
	}

	private function isFirstOpenLineActivityForItem(): bool
	{
		$activityFields = $this->activityFields;
		$possibleTarget = $this->nextTarget;

		$this->logger->debug(
			'{date}: Trying to determine if the activity is first open line activity for item: {activity}' . PHP_EOL,
			[
				'activity' => $activityFields,
			],
		);

		$allOtherOpenLineActivityIdsOfTarget = ActivityTable::query()
			->setSelect(['ID'])
			->where('PROVIDER_ID', OpenLine::getId())
			->where('BINDINGS.OWNER_TYPE_ID', $possibleTarget->getEntityTypeId())
			->where('BINDINGS.OWNER_ID', $possibleTarget->getEntityId())
			->setLimit(100)
			->fetchCollection()
			->getIdList()
		;

		// exclude activity that we are testing right now
		$allOtherOpenLineActivityIdsOfTarget = array_diff($allOtherOpenLineActivityIdsOfTarget, [(int)$activityFields['ID']]);
		if (empty($allOtherOpenLineActivityIdsOfTarget))
		{
			$this->logger->debug(
				'{date}: No other open line activities found for target {target} {activity}' . PHP_EOL,
				[
					'target' => $possibleTarget,
					'activity' => $activityFields,
				],
			);

			return true;
		}

		return false;
	}
}
