<?php

namespace Bitrix\Calendar\Core\Queue\Agent;

use Bitrix\Calendar\Core\Queue\Queue\RevokeGuestLinkQueue;
use Bitrix\Calendar\Internal\GuestLink\GuestLinkResolver;
use Bitrix\Calendar\Internals\Log\Logger;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ModuleManager;

class RevokeGuestLinkAgent
{
	private const CANDIDATE_BATCH_SIZE = 1000;
	private const EVENTS_WITH_CHATS_BATCH_SIZE = 20;
	private const PENDING_WARNING_ATTEMPTS = 3;
	private const LOG_MARKER = 'DEBUG_CALENDAR_GUEST_LINK';

	public static function runAgent(): string
	{
		try
		{
			self::processBatch();
		}
		catch (\Throwable $e)
		{
			self::log($e);
		}

		return self::class . '::runAgent();';
	}

	private static function processBatch(): void
	{
		$queue = new RevokeGuestLinkQueue();
		$messagesByEventId = [];
		$chatIdsByEventId = [];
		$seriesContextByEventId = [];
		$acknowledgedMessageIds = [];
		$deferredMessages = [];

		foreach ($queue->getBatch(self::CANDIDATE_BATCH_SIZE) as $message)
		{
			$eventId = $message['eventId'];
			if ($eventId <= 0)
			{
				$acknowledgedMessageIds[] = $message['id'];
				continue;
			}

			$messagesByEventId[$eventId][] = $message;
			if ($message['chatId'] > 0)
			{
				$chatIdsByEventId[$eventId] = $message['chatId'];
				$seriesContextByEventId[$eventId] = [
					'seriesRootId' => $message['seriesRootId'],
					'creatorId' => $message['creatorId'],
					'ownerId' => $message['ownerId'],
				];
			}
		}

		if ($messagesByEventId === [])
		{
			$queue->acknowledge($acknowledgedMessageIds);

			return;
		}

		if (!ModuleManager::isModuleInstalled('im'))
		{
			foreach ($messagesByEventId as $messages)
			{
				array_push(
					$acknowledgedMessageIds,
					...array_column($messages, 'id'),
				);
			}

			$queue->acknowledge($acknowledgedMessageIds);

			return;
		}

		/** @var GuestLinkResolver $resolver */
		$resolver = ServiceLocator::getInstance()->get(GuestLinkResolver::class);
		$results = $resolver->revokeGuestLinks(
			array_keys($messagesByEventId),
			self::EVENTS_WITH_CHATS_BATCH_SIZE,
			$chatIdsByEventId,
			$seriesContextByEventId,
		);

		foreach ($messagesByEventId as $eventId => $messages)
		{
			if (!array_key_exists($eventId, $results))
			{
				continue;
			}

			$result = $results[$eventId];
			if (!$result->isSuccess())
			{
				self::log(
					'failed to revoke the guest link of deleted event ' . $eventId
					. ': ' . implode(', ', $result?->getErrorMessages() ?? []),
				);
				foreach ($messages as $message)
				{
					if ($message['attempts'] + 1 === self::PENDING_WARNING_ATTEMPTS)
					{
						self::log(
							'guest link revoke of deleted event ' . $eventId
							. ' is still pending after ' . self::PENDING_WARNING_ATTEMPTS . ' attempts',
						);
					}

					$deferredMessages[] = $message;
				}
				continue;
			}

			array_push(
				$acknowledgedMessageIds,
				...array_column($messages, 'id'),
			);
		}

		$queue->acknowledge($acknowledgedMessageIds);
		$queue->defer($deferredMessages);
	}

	private static function log(mixed $data): void
	{
		(new Logger(self::LOG_MARKER))->log($data);
	}
}
