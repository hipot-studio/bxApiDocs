<?php declare(strict_types=1);

namespace Bitrix\AI\Engine\Service;

use Bitrix\AI\Logger\LoggerService;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

class RuleService
{
	protected const KEY_VERSION_RULES = 'version_engine_rules';

	public function __construct(
		protected BitrixEngineService $bitrixEngineService,
		protected DefaultSettingsService $defaultSettingsService
	)
	{
	}

	public function initUpdate(array $rulesData): void
	{
		if (empty($rulesData['version']) || Option::get('ai', static::KEY_VERSION_RULES, 1) === $rulesData['version'])
		{
			return;
		}

		try
		{
			Application::getInstance()->addBackgroundJob(
				[$this, "update"],
				[$rulesData],
			);
		}
		catch (\Throwable)
		{
		}
	}

	public function update(array $rulesData): void
	{
		if (!Application::getConnection()->lock('ai_update_info_for_engines'))
		{
			return;
		}

		$region = Application::getInstance()->getLicense()->getRegion();

		try
		{
			$this->bitrixEngineService->updateEngines(
				$this->bitrixEngineService->getActiveProviders($rulesData, $region)
			);
		}
		catch (ArgumentException|ObjectPropertyException|SystemException $exception)
		{
			ServiceLocator::getInstance()->get(LoggerService::class)->logMessage(
				'error_update_in_rule_service',
				$exception->getMessage()
			);

			return;
		}

		$this->defaultSettingsService->updateDefaultSettings(
			$this->defaultSettingsService->getActions($rulesData, $region)
		);

		Option::set('ai', static::KEY_VERSION_RULES, $rulesData['version']);
	}
}
