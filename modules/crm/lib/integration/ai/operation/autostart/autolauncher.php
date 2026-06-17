<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart;

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Copilot\Pipeline\TargetResolver;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\BaasManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Operation\Autostart\AutoLauncher\ChannelAutoStartStrategyFactory;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings\CallChannelSettings;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings\ChatChannelSettings;

/**
 * @todo migrate to PipelineExecutor
 *
 * AutoLauncher currently launches operations via AIManager::launch*() methods,
 * which rely on Scenario::getNextTypeIdByScenario() for NEXT_TYPE_ID.
 * This creates an implicit coupling: FULL_SCENARIO must return null from getNextTypeIdByScenario()
 * because FULL and FILL_FIELDS share the same first transition (Transcribe→Summarize),
 * and ScenarioResolver uses a fallback for null NEXT_TYPE_ID.
 *
 * Migrating strategies to use PipelineExecutor would:
 * - Eliminate the null-NEXT_TYPE_ID special case
 * - Set explicit NEXT_TYPE_ID per step via setNextTypeIdOverride()
 * - Unify all operation launches through a single code path
 *
 * @see \Bitrix\Crm\Copilot\Pipeline\PipelineExecutor
 * @see \Bitrix\Crm\Copilot\Pipeline\ScenarioResolver::resolve() — null fallback
 * @see Scenario::getNextTypeIdByScenario() — FULL_SCENARIO special case
 */
final class AutoLauncher
{
	public static function isEnabled(): bool
	{
		return AIManager::isAiCallAutomaticProcessingAllowed()
			&& BaasManager::hasPackage()
			&& (
				AIManager::isEnabledInGlobalSettings()
				|| AIManager::isEnabledInGlobalSettings(GlobalSetting::CallAssessment)
				|| AIManager::isEnabledInGlobalSettings(GlobalSetting::AnalyzeCommunication)
				|| AIManager::isEnabledInGlobalSettings(GlobalSetting::Summarize)
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
				->setTargetResolver(new TargetResolver())
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
