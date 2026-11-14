<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Navigation\Catalog;

use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Web\Json;

final class CatalogRunBinding
{
	public function __construct(public readonly array $values)
	{
	}

	public function hash(): string
	{
		$values = $this->values;
		ksort($values, SORT_STRING);

		return hash('sha256', Json::encode($values));
	}

	public function actorKey(): ?string
	{
		$userId = (int)($this->values['userId'] ?? 0);
		if ($userId <= 0)
		{
			return null;
		}

		return (new Signer())->getSignature(
			(string)$userId,
			'crm.timeline.navigation.catalog-actor.v1',
		);
	}
}
