<?php

declare(strict_types=1);

namespace Bitrix\Rest\Contract\Repository;

use Bitrix\Rest\Entity\Collection\IntegrationCollection;
use Bitrix\Rest\Entity\Integration;

interface IntegrationRepository
{
	public function getById(int $id): ?Integration;
	public function getCloudPaidIntegrations(): IntegrationCollection;
	public function getBoxedPaidIntegrations(): IntegrationCollection;
	public function hasUserIntegrations(): bool;
	public function hasNotInWebhookUserIntegrations(): bool;
	public function getCloudCount(): int;
	public function getBoxedCount(): int;
}
