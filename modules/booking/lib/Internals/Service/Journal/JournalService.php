<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Journal;

use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Service\Journal\EventProcessor\Counter\CounterEventProcessor;
use Bitrix\Booking\Internals\Service\Journal\EventProcessor\EventProcessor;
use Bitrix\Booking\Internals\Service\Journal\EventProcessor\PushPull\PushPullEventProcessor;
use Bitrix\Booking\Internals\Service\Journal\EventProcessor\Booking\BookingEventProcessor;
use Bitrix\Booking\Internals\Service\Journal\EventProcessor\Resource\ResourceEventProcessor;
use Bitrix\Booking\Internals\Service\Journal\EventProcessor\ResourceType\ResourceTypeEventProcessor;
use Bitrix\Booking\Internals\Service\Journal\EventProcessor\WaitListItem\WaitListItemEventProcessor;
use Bitrix\Main\Application;

final class JournalService implements JournalServiceInterface
{
	private const LOCK_KEY = 'booking.journallock';
	private static bool $isJobEnabled = false;

	public function append(JournalEvent $event): void
	{
		self::enableJob();
		Container::getJournalRepository()->append($event);
	}

	public static function enableJob(): void
	{
		if (self::$isJobEnabled)
		{
			return;
		}

		$application = Application::getInstance();
		$application && $application->addBackgroundJob(
			['\Bitrix\Booking\Internals\Service\Journal\JournalService', 'process'],
			[],
			Application::JOB_PRIORITY_LOW - 2
		);
		self::$isJobEnabled = true;
	}

	public static function process(): void
	{
		$connection = Application::getConnection();
		$locked = false;

		try
		{
			$locked = $connection->lock(self::LOCK_KEY);
			if (!$locked)
			{
				return;
			}

			$eventCollection = Container::getJournalRepository()->getPending();
			if ($eventCollection->isEmpty())
			{
				return;
			}

			self::processByOne($eventCollection);
		}
		finally
		{
			if ($locked)
			{
				$connection->unlock(self::LOCK_KEY);
			}
		}
	}

	private static function processByOne(JournalEventCollection $eventCollection): void
	{
		$processors = self::getProcessors();
		$journalRepository = Container::getJournalRepository();

		foreach ($eventCollection as $event)
		{
			$status = null;

			try
			{
				foreach ($processors as $processor)
				{
					$processor->processOne($event);
				}

				$status = JournalStatus::Processed;
			}
			finally
			{
				$journalRepository->setStatus($event->id, $status ?? JournalStatus::Error);
			}
		}
	}

	/**
	 * @return EventProcessor[]
	 */
	private static function getProcessors(): array
	{
		return [
			new BookingEventProcessor(),
			new ResourceEventProcessor(Container::getDelayedTaskService()),
			new ResourceTypeEventProcessor(),
			new WaitListItemEventProcessor(),
			new CounterEventProcessor(),
			new PushPullEventProcessor(),
			// other event processors ...
		];
	}
}
