<?php

declare(strict_types=1);

namespace Bitrix\Baas\Repository;

interface ConsumptionRepositoryInterface {
	public function isEnabled(): bool;

	public function resetLogForMigration(): void;

	public function collectLogForMigration(string $marker): array;

	public function crossOutByMigrationMarker(string $marker): void;

	public function consume(string $serviceCode, string $consumptionId, int $units): Result\BalanceResult;

	public function refund(string $serviceCode, string $consumptionId): Result\BalanceResult;
}
