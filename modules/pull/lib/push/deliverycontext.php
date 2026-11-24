<?php

declare(strict_types=1);

namespace Bitrix\Pull\Push;

use Bitrix\Main\ArgumentException;

final readonly class DeliveryContext
{
	public const VERSION = 1;
	public const STORAGE_KEY = '__pull_delivery_context';

	public const DESTINATION_USER = 'user';
	public const DESTINATION_INCOMING_CALL = 'incoming_call';
	public const DESTINATION_CONTROL = 'control';
	public const DESTINATION_BADGE = 'badge';
	public const DESTINATION_BACKGROUND = 'background';

	public const OPERATION_NOTIFY = 'notify';
	public const OPERATION_NOTIFICATION_REMOVE = 'notification_remove';
	public const OPERATION_CALL_END = 'call_end';
	public const OPERATION_COUNTER_UPDATE = 'counter_update';
	public const OPERATION_CONTENTLESS_SYNC = 'contentless_sync';

	public function __construct(
		public string $sourceId,
		public string $producerModuleId,
		public string $eventType,
		public string $destination,
		public string $operation,
		public bool $hasUserContent,
		public bool $playsSound,
		public bool $opensUserAction,
	)
	{
		if ($sourceId === '' || $producerModuleId === '' || $eventType === '')
		{
			throw new ArgumentException('Delivery context identity cannot be empty.');
		}
	}

	public function toArray(): array
	{
		return [
			'version' => self::VERSION,
			'sourceId' => $this->sourceId,
			'producerModuleId' => $this->producerModuleId,
			'eventType' => $this->eventType,
			'destination' => $this->destination,
			'operation' => $this->operation,
			'hasUserContent' => $this->hasUserContent,
			'playsSound' => $this->playsSound,
			'opensUserAction' => $this->opensUserAction,
		];
	}
}
