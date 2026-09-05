<?php

namespace Bitrix\Location\Repository\Location\Capability;

interface IFindByCoordsList
{
	public function findByCoordsList(
		array $coordsList,
		int $zoom,
		string $languageId
	): array;
}
