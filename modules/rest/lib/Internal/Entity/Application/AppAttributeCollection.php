<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application;

use Bitrix\Main;

class AppAttributeCollection extends Main\Entity\EntityCollection
{
	protected static function getEntityClass(): string
	{
		return AppAttribute::class;
	}

	public function getByCode(string $code): ?AppAttribute
	{
		return $this->find(fn(AppAttribute $attr) => $attr->getCode() === $code);
	}

	/**
	 * @return array<string, AppAttribute>
	 */
	public function indexByCode(): array
	{
		$result = [];

		foreach ($this->getIterator() as $attribute)
		{
			$result[$attribute->getCode()] = $attribute;
		}

		return $result;
	}
}
