<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Service;

use Bitrix\Disk\Driver;
use Bitrix\Disk\ProxyType;
use Bitrix\Disk\Storage;

final class SupportedStoragePolicy
{
	private const ENTITY_TYPES = [
		ProxyType\User::class,
		ProxyType\Group::class,
		ProxyType\Common::class,
	];

	public function supports(Storage $storage): bool
	{
		return $storage->getModuleId() === Driver::INTERNAL_MODULE_ID
			&& $storage->isUseInternalRights()
			&& in_array($storage->getEntityType(), self::ENTITY_TYPES, true)
		;
	}

	public function getSupportedEntityTypes(): array
	{
		return self::ENTITY_TYPES;
	}
}
