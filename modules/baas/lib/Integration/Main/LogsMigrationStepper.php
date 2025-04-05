<?php

declare(strict_types=1);

namespace Bitrix\Baas\Integration\Main;

use Bitrix\Baas;
use Bitrix\Main;
use Bitrix\Main\Update\Stepper;

class LogsMigrationStepper extends Stepper
{
	public function execute(array &$option)
	{
		if (
			Main\Loader::includeModule('baas') !== true
			|| Baas\Baas::getInstance()->isAvailable() !== true
			|| (new Baas\Config\Client())->isConsumptionsLogMigrated() === true
			|| class_exists(Baas\Repository\ConsumptionRepository::class) !== true
			|| Baas\Repository\ConsumptionRepository::getInstance()->isEnabled() !== true
		)
		{
			return Stepper::FINISH_EXECUTION;
		}

		$billingService = Baas\Service\BillingService::getInstance();

		if (Baas\Baas::getInstance()->isRegistered() !== true)
		{
			try
			{
				if ($billingService->register(true)->isSuccess() !== true)
				{
					throw new Main\SystemException('Error while auto registering the host');
				}
			}
			catch (\Exception $e)
			{
				Main\Application::getInstance()->getExceptionHandler()->writeToLog($e);
				return Stepper::CONTINUE_EXECUTION;
			}
		}

		if ($billingService->needToMigrate())
		{
			$billingService->planToMigrate();

			$option['steps'] = 2;
			$option['count'] = 1;

			return Stepper::CONTINUE_EXECUTION;
		}

		Baas\Internal\Diag\Logger::getInstance()->info('Migration has been finished from agent');

		return Stepper::FINISH_EXECUTION;
	}
}
