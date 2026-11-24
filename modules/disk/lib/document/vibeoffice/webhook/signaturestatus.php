<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Webhook;

final class SignatureStatus
{
	private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D';
	private const FIELDS = [
		'platform_instance_id', 'host_id', 'capability', 'rollout_authorized', 'transition_id',
		'contract_id', 'send_min', 'epoch', 'phase', 'producer_fence_generation', 'old_active_attempts',
	];

	public static function validate(mixed $value): ?array
	{
		if (!is_array($value)
			|| !is_string($value['platform_instance_id'] ?? null)
			|| preg_match(self::UUID, $value['platform_instance_id']) !== 1
			|| !is_string($value['host_id'] ?? null)
			|| preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D', $value['host_id']) !== 1
			|| ($value['capability'] ?? null) !== SignatureV2::CONTRACT_ID
			|| ($value['contract_id'] ?? null) !== SignatureV2::CONTRACT_ID
			|| !is_bool($value['rollout_authorized'] ?? null)
			|| !is_string($value['transition_id'] ?? null)
			|| ($value['transition_id'] !== '' && preg_match(self::UUID, $value['transition_id']) !== 1)
			|| !in_array($value['send_min'] ?? null, [1, 2], true)
			|| !in_array($value['phase'] ?? null, ['legacy', 'draining', 'prepared', 'complete'], true))
		{
			return null;
		}
		foreach (['epoch', 'producer_fence_generation', 'old_active_attempts'] as $field)
		{
			if (!is_int($value[$field] ?? null) || $value[$field] < 0)
			{
				return null;
			}
		}
		if (($value['send_min'] === 1 && $value['phase'] !== 'legacy')
			|| ($value['send_min'] === 2 && ($value['phase'] === 'legacy'
				|| $value['transition_id'] === '' || $value['epoch'] < 1 || $value['producer_fence_generation'] < 1))
			|| (in_array($value['phase'], ['prepared', 'complete'], true) && $value['old_active_attempts'] !== 0)
			|| ($value['rollout_authorized'] && $value['transition_id'] === ''))
		{
			return null;
		}

		return array_intersect_key($value, array_flip(self::FIELDS));
	}
}
