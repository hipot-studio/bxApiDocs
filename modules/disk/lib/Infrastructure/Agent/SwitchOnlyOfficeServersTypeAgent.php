<?php
declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Agent;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Integration\Baas\BaasSessionBoostService;
use Bitrix\Disk\Internal\Command\DropOnlyOfficeSessionsAfterSwitchCommand;
use Bitrix\Disk\Internal\Command\SendOnlyOfficeForceReloadEventCommand;
use Bitrix\Disk\Internal\Command\SwitchOnlyOfficeServersTypeCommand;
use Bitrix\Disk\Internal\Enum\ServersTypesEnum;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Type\DateTime;
use Psr\Log\LoggerInterface;

class SwitchOnlyOfficeServersTypeAgent extends BaseDiskAgent
{
	protected const TRIES_MAX_COUNT = 5;

    protected LoggerInterface $logger;
	protected BaasSessionBoostService $baasSessionBoostService;

	public function __construct()
	{
		$this->logger = LoggerFactory::create(
			feature: 'switch onlyoffice servers type',
		);

		$this->baasSessionBoostService = new BaasSessionBoostService();
	}

	/**
	 * {@inheritDoc}
	 * @throws CommandException
	 * @throws CommandValidationException
	 */
	protected function runInternal(): RunResult
	{
        $this->logger->debug('agent run');

		$result = new RunResult(true);
		$hasAnyActiveBooster = $this->baasSessionBoostService->getQuota() > 0;
		$onlyOfficeServersType = Configuration::getOnlyOfficeServersType();
		$newServersType = null;

		if ($hasAnyActiveBooster && $onlyOfficeServersType !== ServersTypesEnum::Booster)
		{
			$newServersType = ServersTypesEnum::Booster;
		}
		elseif (!$hasAnyActiveBooster && $onlyOfficeServersType !== ServersTypesEnum::Regular)
		{
			$newServersType = ServersTypesEnum::Regular;
		}

		if ($newServersType instanceof ServersTypesEnum)
		{
			$state = $this->initializeState();

			$this->logger->info('detected new servers type, empty state initialized', [
				'type' => $newServersType,
			]);
		}
		else
		{
			$this->logger->debug('detected no new servers type');
			$this->cleanup();
			$this->logger->debug('trying get state');

			$state = Configuration::getStateForOnlyofficeSwitchServersType();
		}

		if (!is_array($state))
		{
			$this->logger->debug('state not found');
			$this->cleanup();

			return $result;
		}

		$state['try']++;

		$this->logger->debug('state found', [
			'try' => $state['try'],
		]);

		if ($state['try'] <= static::TRIES_MAX_COUNT)
		{
			Configuration::storeStateForOnlyofficeSwitchServersType($state);
		}
		else
		{
			$this->logger->debug('max tries reached, stop retrying');
			Configuration::deleteStateForOnlyofficeSwitchServersType();
			$this->cleanup();

			return $result;
		}

		if ($state['step'] === 0)
		{
			$switchServersTypeCommand = new SwitchOnlyOfficeServersTypeCommand(
				newServersType: $newServersType,
				logger: $this->logger,
			);

			$switchServersTypeResult = $switchServersTypeCommand->run();

			if (!$switchServersTypeResult->isSuccess())
			{
				$this->cleanup();

				return $result;
			}

			$state['step']++;
			Configuration::storeStateForOnlyofficeSwitchServersType($state);
		}

		if ($state['step'] === 1)
		{
			$sendOnlyOfficeForceReloadEventCommand = new SendOnlyOfficeForceReloadEventCommand(
				newServersType: $newServersType,
				logger: $this->logger,
			);

			$sendOnlyOfficeForceReloadEventResult = $sendOnlyOfficeForceReloadEventCommand->run();

			if (!$sendOnlyOfficeForceReloadEventResult->isSuccess())
			{
				$this->cleanup();

				return $result;
			}

//			$state['step']++;
//			Configuration::storeStateForOnlyofficeSwitchServersType($state);
		}

//		if ($state['step'] === 2)
//		{
//			$dropSessionsCommand = new DropOnlyOfficeSessionsAfterSwitchCommand(
//				switchedAt: $state['dateTime'],
//				logger: $this->logger,
//			);
//
//			$dropResult = $dropSessionsCommand->run();
//
//			if (!$dropResult->isSuccess())
//			{
//				$this->cleanup();
//
//				return $result;
//			}
//		}

		Configuration::deleteStateForOnlyofficeSwitchServersType();
        $this->cleanup();

		return $result;
	}

	/**
	 * Initialize empty state for this agent.
	 *
	 * @return array
	 */
	protected function initializeState(): array
	{
		return [
			'dateTime' => DateTime::createFromTimestamp(time()),
			'step' => 0,
			'try' => 0,
		];
	}

	/**
	 * Do some actions before agent ends.
	 *
	 * @return void
	 */
    protected function cleanup(): void
    {
        $this->logger->debug('agent end');
    }
}