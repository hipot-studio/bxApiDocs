<?php

namespace Bitrix\Crm\Integration\Analytics\Builder\Entity;

use Bitrix\Crm\Integration\Analytics\Builder\AbstractBuilder;
use Bitrix\Crm\Service\Container;

/**
 * P4 of an entity event is the workplace its type is bound to.
 *
 * Only a smart process can be bound to one, and only through the custom section of its type. The id of the
 * item, the current url and the section of the interface are not sources of it.
 */
final class WorkplaceP4
{
	private const KEY = 'id';
	private const LEGACY_KEY = 'myCompany';
	private const LEGACY_VALUES = ['0', '1'];

	public static function apply(AbstractBuilder $builder, int $entityTypeId): void
	{
		$workplaceId = self::resolveWorkplaceId($entityTypeId);
		if ($workplaceId !== null)
		{
			$builder->setP4(self::KEY, (string)$workplaceId);

			return;
		}

		if (self::isLegacyMyCompany($builder->getP4()))
		{
			$builder->clearP4();
		}
	}

	private static function resolveWorkplaceId(int $entityTypeId): ?int
	{
		if (!\CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
		{
			return null;
		}

		$type = Container::getInstance()->getTypeByEntityTypeId($entityTypeId);
		$workplaceId = (int)$type?->getCustomSectionId();

		// the workplace is taken as it is stored: checking that it still exists would cost a read per event
		return $workplaceId > 0 ? $workplaceId : null;
	}

	private static function isLegacyMyCompany(array $p4): bool
	{
		if (count($p4) !== 2)
		{
			return false;
		}

		[$key, $value] = $p4;

		return $key === self::LEGACY_KEY && in_array($value, self::LEGACY_VALUES, true);
	}
}
