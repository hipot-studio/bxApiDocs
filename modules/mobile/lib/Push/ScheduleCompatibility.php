<?php

namespace Bitrix\Mobile\Push;

use Bitrix\Pull\Push\DeliveryContext;
use Bitrix\Pull\Push\DeliveryContextFactory;
use Bitrix\Pull\PushTable;

final class ScheduleCompatibility
{
	public const PUSH_SCHEDULE_VERSION = 1;

	public function __construct(
		private readonly string $pushManagerClass = \CPushManager::class,
		private readonly string $pushTableClass = PushTable::class,
		private readonly string $deliveryContextFactoryClass = DeliveryContextFactory::class,
		private readonly string $deliveryContextClass = DeliveryContext::class,
	)
	{
	}

	public function getDeliveryContextParameters(string $moduleId, string $event, array $payload): array
	{
		if (
			!class_exists($this->deliveryContextFactoryClass)
			|| !method_exists($this->deliveryContextFactoryClass, 'create')
			|| !defined($this->deliveryContextClass . '::STORAGE_KEY')
		)
		{
			return [];
		}

		$context = (new $this->deliveryContextFactoryClass())->create($moduleId, $event, $payload);

		return [$this->deliveryContextClass::STORAGE_KEY => $context->toArray()];
	}

	public function sendMessage(array $messages): bool
	{
		$manager = new $this->pushManagerClass();

		return $manager->sendMessage($messages)
			|| (
				method_exists($manager, 'wasMessageSuppressedByDeliveryPolicy')
				&& $manager->wasMessageSuppressedByDeliveryPolicy()
			)
		;
	}

	public function normalizeTimeZoneContext(mixed $timeZoneId, mixed $offsetMinutes): array
	{
		if (
			!method_exists($this->pushTableClass, 'normalizeTimeZoneContext')
			|| !method_exists($this->pushTableClass, 'isTimeZoneContextSupported')
			|| !$this->pushTableClass::isTimeZoneContextSupported()
		)
		{
			return [];
		}

		return $this->pushTableClass::normalizeTimeZoneContext($timeZoneId, $offsetMinutes);
	}
}
