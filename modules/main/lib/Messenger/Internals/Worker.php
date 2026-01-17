<?php

declare(strict_types=1);

namespace Bitrix\Main\Messenger\Internals;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Messenger\Internals\Config\QueueConfigRegistry;
use Bitrix\Main\Messenger\Internals\Exception\ReceiverException;
use Bitrix\Main\SystemException;

final class Worker
{
	private bool $shuffle;

	public function __construct(?bool $shuffle = null)
	{
		if ($shuffle === null)
		{
			$config = Configuration::getValue('messenger');

			$this->shuffle = $config['shuffle'] ?? true;
		}
		else
		{
			$this->shuffle = $shuffle;
		}
	}

	/**
	 * @throws LoaderException
	 * @throws ConfigurationException
	 * @throws SystemException
	 */
	public function process(array $options = []): void
	{
		$registry = ServiceLocator::getInstance()->get(QueueConfigRegistry::class);

		$queues = $registry->getQueues();

		if (!empty($options['queues']))
		{
			$queues = array_intersect_key($queues, array_combine($options['queues'], $options['queues']));
		}

		if ($this->shuffle)
		{
			shuffle($queues);
		}

		foreach ($queues as $queueConfig)
		{
			try
			{
				$queueConfig->createReceiver()->run();
			}
			catch (ReceiverException $e)
			{
				Application::getInstance()->getExceptionHandler()->writeToLog($e);
			}
		}
	}
}
