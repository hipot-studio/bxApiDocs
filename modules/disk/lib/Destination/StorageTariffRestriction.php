<?php

declare(strict_types=1);

namespace Bitrix\Disk\Destination;

use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Disk\ProxyType;
use Bitrix\Disk\Storage;

/**
 * Single source of truth for the tariff gate of common storages.
 *
 * A restricted storage still exists and stays visible: the destination list marks it, the verdict denies it.
 */
class StorageTariffRestriction
{
	public const FEATURE = 'disk_common_storage';
	public const CODE_TARIFF = 'tariff';

	public function isRestricted(Storage $storage): bool
	{
		return $storage->getProxyType() instanceof ProxyType\Common
			&& !$this->isFeatureEnabled(self::FEATURE);
	}

	public function getHelperId(): ?string
	{
		$helperId = $this->getFeatureRestrictionMap()[self::FEATURE] ?? null;

		return is_string($helperId) && $helperId !== '' ? $helperId : null;
	}

	protected function isFeatureEnabled(string $feature): bool
	{
		return (bool)Bitrix24Manager::isFeatureEnabled($feature);
	}

	protected function getFeatureRestrictionMap(): array
	{
		return Bitrix24Manager::getFeatureRestrictionMap();
	}
}
