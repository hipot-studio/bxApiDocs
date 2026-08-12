<?php

declare(strict_types=1);

namespace Bitrix\Landing\Realtime\Transport;

use Bitrix\Landing\Realtime\Event;
use Bitrix\Landing\Realtime\EventSerializer;
use Bitrix\Landing\Realtime\EventSerializerInterface;
use Bitrix\Landing\Realtime\Logger;
use Bitrix\Landing\Realtime\TransportInterface;
use Bitrix\Main\Loader;
use Bitrix\Pull\Event as PullEvent;

final class PullTransport implements TransportInterface
{
	private EventSerializerInterface $serializer;
	private $pullModuleResolver;
	private $eventSender;
	private $eventFlusher;

	public function __construct(
		?EventSerializerInterface $serializer = null,
		?callable $pullModuleResolver = null,
		?callable $eventSender = null,
		?callable $eventFlusher = null
	)
	{
		$this->serializer = $serializer ?? new EventSerializer();
		$this->pullModuleResolver = $pullModuleResolver ?? static fn(): bool => Loader::includeModule('pull');
		$this->eventSender = $eventSender ?? static function(array $recipientIds, array $message): void {
			PullEvent::add($recipientIds, $message);
		};
		$this->eventFlusher = $eventFlusher ?? static function(): void {
			PullEvent::send();
		};
	}

	public function send(array $recipientIds, Event $event): void
	{
		Logger::write('transport.send.start', [
			'rawRecipientIds' => $recipientIds,
			'event' => Logger::eventToArray($event),
		]);

		$recipientIds = $this->normalizeRecipientIds($recipientIds);
		Logger::write('transport.send.normalized_recipients', [
			'recipientIds' => $recipientIds,
			'event' => Logger::eventToArray($event),
		]);

		if (!$recipientIds)
		{
			Logger::write('transport.send.no_recipients', [
				'event' => Logger::eventToArray($event),
			]);

			return;
		}

		if (!($this->pullModuleResolver)())
		{
			Logger::write('transport.send.pull_unavailable', [
				'recipientIds' => $recipientIds,
				'event' => Logger::eventToArray($event),
			]);

			return;
		}

		$message = [
			'module_id' => 'landing',
			'command' => 'LandingRealtime:onEvent',
			'params' => $this->serializer->serialize($event),
		];
		Logger::write('transport.send.before_add', [
			'recipientIds' => $recipientIds,
			'message' => $message,
		]);

		try
		{
			($this->eventSender)($recipientIds, $message);
			Logger::write('transport.send.after_add', [
				'recipientIds' => $recipientIds,
				'message' => $message,
			]);

			($this->eventFlusher)();
			Logger::write('transport.send.after_flush', [
				'recipientIds' => $recipientIds,
				'message' => $message,
			]);
		}
		catch (\Throwable $exception)
		{
			Logger::write('transport.send.exception', [
				'recipientIds' => $recipientIds,
				'message' => $message,
				'exception' => [
					'class' => $exception::class,
					'message' => $exception->getMessage(),
				],
			]);
		}
	}

	private function normalizeRecipientIds(array $recipientIds): array
	{
		$recipientIds = array_map('intval', $recipientIds);
		$recipientIds = array_filter($recipientIds, static fn(int $recipientId): bool => $recipientId > 0);

		return array_values(array_unique($recipientIds));
	}
}
