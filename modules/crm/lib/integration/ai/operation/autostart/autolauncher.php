<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart;

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Operation\Autostart\AutoLauncher\ChannelAutoStartStrategyFactory;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings\CallChannelSettings;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings\ChatChannelSettings;
use Bitrix\Crm\Integration\AI\Operation\Orchestrator;

final class AutoLauncher
{
	public static function isEnabled(): bool
	{
		return AIManager::isAiCallAutomaticProcessingAllowed()
			&& AIManager::isBaasServiceHasPackage()
			&& (
				AIManager::isEnabledInGlobalSettings()
				|| AIManager::isEnabledInGlobalSettings(GlobalSetting::CallAssessment)
			)
		;
	}

	public function run(int $activityOperation, array $activityFields, array $changedFields = []): void
	{
		$logger = AIManager::logger();

		$channelType = $this->detectChannelType($activityFields);
		$strategy = ChannelAutoStartStrategyFactory::create($activityOperation, $channelType, $activityFields);
		if ($strategy === null)
		{
			$logger->warning(
				'{date}: Autostart strategy not found for channel "{channelType}" and activity: {activity}' . PHP_EOL,
				[
					'channelType' => $channelType,
					'activity' => $activityFields,
				],
			);

			return;
		}

		try
		{
			$strategy
				->setLogger($logger)
				->setOrchestrator(new Orchestrator())
				->run($changedFields)
			;
		}
		catch (\Throwable $exception)
		{
			$logger->error(
				'{date}: Autostart error for channel "{channelType}" and activity: {activity}: {error}' . PHP_EOL,
				[
					'channelType' => $channelType,
					'activity' => $activityFields,
					'error' => $exception->getMessage(),
				],
			);
		}
	}

	private function detectChannelType(array $activityFields): string
	{
		$providerId = (string)($activityFields['PROVIDER_ID'] ?? '');

		return match ($providerId)
		{
			Call::getId() => CallChannelSettings::CHANNEL_TYPE,
			OpenLine::getId() => ChatChannelSettings::CHANNEL_TYPE,
			default => '',
		};
	}
}
