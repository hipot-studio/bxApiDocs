<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\StorageItem;

use Bitrix\Main\Config\Option;

enum StorageEavMigrationPhase: string
{
	case DualWriteJsonRead = 'Y';
	case DualWriteEavRead = 'R';
	case EavOnly = 'N';

	private const OPTION_NAME = 'storage_eav_migration_phase';

	public static function getCurrent(): self
	{
		$value = Option::get('bizproc', self::OPTION_NAME, self::DualWriteJsonRead->value);

		return self::tryFrom($value) ?? self::DualWriteJsonRead;
	}

	public function save(): void
	{
		Option::set('bizproc', self::OPTION_NAME, $this->value);
	}
}
