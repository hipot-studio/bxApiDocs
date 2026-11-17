<?php

namespace Bitrix\DocumentGenerator\Retention;

/**
 * @internal
 */
abstract class PressurePolicy
{
	/**
	 * Checks whether current memory usage reached the pressure trigger.
	 *
	 * @return bool
	 */
	abstract public function isUnderPressure(): bool;

	/**
	 * Returns the portion of the retention layer to evict under pressure.
	 *
	 * @return float
	 */
	abstract public function getBatchShare(): float;

	/**
	 * Updates policy state after an eviction batch.
	 *
	 * @param int $evicted
	 * @return void
	 */
	abstract public function afterBatch(int $evicted): void;

	/**
	 * Resets policy state for a new document scope.
	 *
	 * @return void
	 */
	abstract public function reset(): void;
}
