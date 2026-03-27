<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\LimitEncounter\DocumentEditSession\Notification;

use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\Diag\ExceptionHandler;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Throwable;

class ThresholdReachedNotifier
{
	private ExceptionHandler $exceptionHandler;

	public function __construct(
		private readonly ThresholdReachedCommandFactory $commandFactory,
	)
	{
		$this->exceptionHandler = Application::getInstance()->getExceptionHandler();
	}

	/**
	 * @param int $thresholdPosition
	 * @param int $thresholdValue
	 * @return void
	 * @throws ArgumentException
	 * @throws CircularDependencyException
	 * @throws ServiceNotFoundException
	 * @throws ObjectNotFoundException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function notify(int $thresholdPosition, int $thresholdValue): void
	{
		$commands = $this->commandFactory->create($thresholdPosition, $thresholdValue);

		foreach ($commands as $command)
		{
			try
			{
				$command->run();
			}
			catch (Throwable $e)
			{
				$this->exceptionHandler->handleException($e);
			}
		}

		$this->sendAnalytic($thresholdValue);
	}

	public function sendAnalytic(int $thresholdValue): void
	{
		Application::getInstance()->addBackgroundJob(function () use ($thresholdValue) {
			$p1 = 'reachLimit_' . $thresholdValue;
			(new AnalyticsEvent(
				event: 'send_notification',
				tool: 'docs',
				category: 'infohelper',
			))
				->setP1($p1)
				->send()
			;
		});
	}
}
