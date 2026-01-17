<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Integration;

interface IntegrationServiceInterface
{
	public function getName(): string;
	public function getStatus(): IntegrationStatusEnum|null;
	public function isAvailable(): bool;
	public function getSettings(): array;
}
