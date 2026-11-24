<?php

declare(strict_types=1);

namespace Bitrix\Pull\Push;

use Bitrix\Main\ArgumentException;

final class DeliveryContextFactory
{
	private const TELEPHONY_MODULES = ['call', 'voximplant'];
	private const INCOMING_CALL_EVENTS = ['call::incoming', 'incoming', 'invite'];

	public function create(string $producerModuleId, string $eventType, array $push): DeliveryContext
	{
		$producerModuleId = mb_strtolower(trim($producerModuleId));
		$eventType = trim($eventType) !== '' ? trim($eventType) : DeliveryContext::OPERATION_NOTIFY;
		$sourceId = $this->resolveSourceId($producerModuleId, $eventType, $push);
		$hasDeferredContent = isset($push['pushParamsCallback']);
		$hasUserContent = $hasDeferredContent || trim((string)($push['message'] ?? '')) !== '';
		$playsSound = $this->playsSound($push);
		$opensUserAction = $hasDeferredContent || $hasUserContent || !empty($push['params']);

		[$destination, $operation] = $this->resolveDeliveryType(
			$producerModuleId,
			$eventType,
			$push,
			$hasUserContent,
			$playsSound,
			$opensUserAction,
		);

		return new DeliveryContext(
			$sourceId,
			$producerModuleId !== '' ? $producerModuleId : 'unknown',
			$eventType,
			$destination,
			$operation,
			$hasUserContent,
			$playsSound,
			$opensUserAction,
		);
	}

	public function restore(array $data): DeliveryContext
	{
		if ((int)($data['version'] ?? 0) !== DeliveryContext::VERSION)
		{
			throw new ArgumentException('Unsupported delivery context version.');
		}

		$context = new DeliveryContext(
			trim((string)($data['sourceId'] ?? '')),
			trim((string)($data['producerModuleId'] ?? '')),
			trim((string)($data['eventType'] ?? '')),
			(string)($data['destination'] ?? ''),
			(string)($data['operation'] ?? ''),
			(bool)($data['hasUserContent'] ?? false),
			(bool)($data['playsSound'] ?? false),
			(bool)($data['opensUserAction'] ?? false),
		);

		return $this->isValidPair($context) ? $context : $this->fallback($context);
	}

	private function resolveSourceId(string $moduleId, string $eventType, array $push): string
	{
		if ($moduleId === 'rest')
		{
			return 'rest';
		}
		if ($this->isIncomingCall($moduleId, $eventType, $push) || in_array($moduleId, self::TELEPHONY_MODULES, true))
		{
			return 'telephony';
		}

		return $moduleId !== '' ? $moduleId : 'unknown';
	}

	private function resolveDeliveryType(
		string $moduleId,
		string $eventType,
		array $push,
		bool $hasUserContent,
		bool $playsSound,
		bool $opensUserAction,
	): array
	{
		if ($moduleId === 'rest')
		{
			return [DeliveryContext::DESTINATION_USER, DeliveryContext::OPERATION_NOTIFY];
		}
		if ($this->isIncomingCall($moduleId, $eventType, $push))
		{
			return [DeliveryContext::DESTINATION_INCOMING_CALL, DeliveryContext::OPERATION_NOTIFY];
		}
		if ($hasUserContent || $playsSound || $opensUserAction)
		{
			return [DeliveryContext::DESTINATION_USER, DeliveryContext::OPERATION_NOTIFY];
		}

		$notificationsToCancel = $push['advanced_params']['notificationsToCancel'] ?? [];
		if (is_array($notificationsToCancel) && $notificationsToCancel !== [])
		{
			return [
				DeliveryContext::DESTINATION_CONTROL,
				in_array($moduleId, self::TELEPHONY_MODULES, true)
					? DeliveryContext::OPERATION_CALL_END
					: DeliveryContext::OPERATION_NOTIFICATION_REMOVE,
			];
		}
		if (array_key_exists('badge', $push))
		{
			return [DeliveryContext::DESTINATION_BADGE, DeliveryContext::OPERATION_COUNTER_UPDATE];
		}
		if (empty($push['message']) && empty($push['params']))
		{
			return [DeliveryContext::DESTINATION_BACKGROUND, DeliveryContext::OPERATION_CONTENTLESS_SYNC];
		}

		return [DeliveryContext::DESTINATION_USER, DeliveryContext::OPERATION_NOTIFY];
	}

	private function isIncomingCall(string $moduleId, string $eventType, array $push): bool
	{
		if (!in_array($moduleId, [...self::TELEPHONY_MODULES, 'im'], true))
		{
			return false;
		}
		if (in_array(mb_strtolower($eventType), self::INCOMING_CALL_EVENTS, true))
		{
			return true;
		}

		$action = (string)($push['params']['ACTION'] ?? '');

		return str_starts_with($action, 'IMINV_') || str_starts_with($action, 'VI_CALL_');
	}

	private function playsSound(array $push): bool
	{
		$sound = mb_strtolower(trim((string)($push['sound'] ?? '')));

		return $sound !== '' && $sound !== 'silence.aif';
	}

	private function isValidPair(DeliveryContext $context): bool
	{
		if ($context->destination === DeliveryContext::DESTINATION_USER)
		{
			return $context->operation === DeliveryContext::OPERATION_NOTIFY;
		}
		if ($context->destination === DeliveryContext::DESTINATION_INCOMING_CALL)
		{
			return $context->sourceId === 'telephony' && $context->operation === DeliveryContext::OPERATION_NOTIFY;
		}
		if ($context->hasUserContent || $context->playsSound || $context->opensUserAction)
		{
			return false;
		}

		return match ($context->destination)
		{
			DeliveryContext::DESTINATION_CONTROL => in_array(
				$context->operation,
				[DeliveryContext::OPERATION_NOTIFICATION_REMOVE, DeliveryContext::OPERATION_CALL_END],
				true,
			),
			DeliveryContext::DESTINATION_BADGE => $context->operation === DeliveryContext::OPERATION_COUNTER_UPDATE,
			DeliveryContext::DESTINATION_BACKGROUND => $context->operation === DeliveryContext::OPERATION_CONTENTLESS_SYNC,
			default => false,
		};
	}

	private function fallback(DeliveryContext $context): DeliveryContext
	{
		return new DeliveryContext(
			$context->sourceId,
			$context->producerModuleId,
			$context->eventType,
			DeliveryContext::DESTINATION_USER,
			DeliveryContext::OPERATION_NOTIFY,
			$context->hasUserContent,
			$context->playsSound,
			$context->opensUserAction,
		);
	}
}
