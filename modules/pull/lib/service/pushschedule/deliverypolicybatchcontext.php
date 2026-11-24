<?php

declare(strict_types=1);

namespace Bitrix\Pull\Service\PushSchedule;

/**
 * Prepared delivery policy state shared by transport-sized message groups.
 *
 * @internal
 */
final class DeliveryPolicyBatchContext
{
	/**
	 * @param array<int, string> $modes
	 */
	public function __construct(
		public readonly int $serverInstant,
		public readonly PolicyMetrics $metrics,
		public readonly DeliveryPolicyEvaluator $evaluator,
		public readonly array $modes,
		public readonly ?BatchStateProvider $stateProvider,
	)
	{
	}

	public function hasPolicyUsers(): bool
	{
		return $this->modes !== [];
	}
}
