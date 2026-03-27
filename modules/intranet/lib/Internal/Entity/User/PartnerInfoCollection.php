<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\User;

use Bitrix\Intranet\Internal\Entity\IdentifiableEntityCollection;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Type\Contract\Arrayable;

class PartnerInfoCollection extends IdentifiableEntityCollection implements Arrayable
{
	protected static function getEntityClass(): string
	{
		return PartnerInfo::class;
	}

	/**
	 * @throws ArgumentException
	 */
	public static function createByOption(array $option): PartnerInfoCollection
	{
		$integratorInfoCollection = new PartnerInfoCollection();

		foreach ($option as $integratorInfoOption)
		{
			$integratorInfo = PartnerInfo::createByOption($integratorInfoOption);
			$integratorInfoCollection->add($integratorInfo);
		}

		return $integratorInfoCollection;
	}

	public function toArray(): array
	{
		return $this->map(fn(PartnerInfo $info) => $info->toArray());
	}
}
