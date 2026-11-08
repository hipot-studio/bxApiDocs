<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Placement;

use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\ORM\Query\Result;
use Bitrix\Rest\AppTable;
use Bitrix\Rest\EO_Placement_Collection;
use Bitrix\Rest\PlacementTable;

final class HandlerQuery
{
	public function getLegacyHandlers(string $placement, int $userId): Result
	{
		return PlacementTable::getList([
			'filter' => [
				'=PLACEMENT' => $placement,
				'=REST_APP.ACTIVE' => AppTable::ACTIVE,
				'=USER_ID' => $this->getUserFilter($userId),
			],
			'select' => [
				'ID',
				'USER_ID',
				'ICON_ID',
				'TITLE',
				'GROUP_NAME',
				'OPTIONS',
				'COMMENT',
				'APP_ID',
				'ADDITIONAL',
				'PLACEMENT_HANDLER',
				'INSTALLED' => 'REST_APP.INSTALLED',
				'APP_NAME' => 'REST_APP.APP_NAME',
				'APP_ACCESS' => 'REST_APP.ACCESS',
			],
			'order' => ['ID' => 'ASC'],
		]);
	}

	public function getOwnerAwareCandidates(string $placement, int $userId): EO_Placement_Collection
	{
		return PlacementTable::query()
			->setSelect([
				'ID',
				'USER_ID',
				'ICON_ID',
				'TITLE',
				'GROUP_NAME',
				'OPTIONS',
				'COMMENT',
				'APP_ID',
				'ADDITIONAL',
				'PLACEMENT_HANDLER',
				'MODULE_ID',
				'ENTITY_TYPE',
				'ENTITY_ID',
				'ACTIVE' => 'REST_APP.ACTIVE',
				'INSTALLED' => 'REST_APP.INSTALLED',
				'APP_NAME' => 'REST_APP.APP_NAME',
				'APP_ACCESS' => 'REST_APP.ACCESS',
			])
			->where('PLACEMENT', $placement)
			->whereIn('USER_ID', (array)$this->getUserFilter($userId))
			->where($this->getActiveOrEmbeddingFilter())
			->setOrder(['ID' => 'ASC'])
			->fetchCollection()
		;
	}

	public function getOwnerAwareAccessCandidates(string $placement, int $userId): Result
	{
		return PlacementTable::query()
			->setSelect([
				'ID',
				'APP_ID',
				'MODULE_ID',
				'ENTITY_TYPE',
				'ENTITY_ID',
				'ACTIVE' => 'REST_APP.ACTIVE',
				'INSTALLED' => 'REST_APP.INSTALLED',
				'APP_ACCESS' => 'REST_APP.ACCESS',
			])
			->where('PLACEMENT', $placement)
			->whereIn('USER_ID', (array)$this->getUserFilter($userId))
			->where($this->getActiveOrEmbeddingFilter())
			->setOrder(['ID' => 'ASC'])
			->exec()
		;
	}

	private function getActiveOrEmbeddingFilter(): \Bitrix\Main\ORM\Query\Filter\ConditionTree
	{
		return Query::filter()
			->logic('or')
			->whereNull('APP_ID')
			->where('APP_ID', 0)
			->where('REST_APP.ACTIVE', AppTable::ACTIVE)
		;
	}

	private function getUserFilter(int $userId): int|array
	{
		if ($userId > 0)
		{
			return [
				PlacementTable::DEFAULT_USER_ID_VALUE,
				$userId,
			];
		}

		return PlacementTable::DEFAULT_USER_ID_VALUE;
	}
}
