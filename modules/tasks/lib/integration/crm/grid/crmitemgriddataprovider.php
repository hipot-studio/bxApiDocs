<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Integration\CRM\Grid;

use Bitrix\Crm\Service;
use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Dto\CrmItemGridData;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItemReference;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\Mapper\CrmIdMapper;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Service\CrmItemGridService;
use CCrmEntitySelectorHelper;

/**
 * The single place where the anti-corruption layer reaches the V2 CRM services, so the task grid depends
 * on this layer instead of on V2 types.
 */
class CrmItemGridDataProvider
{
	/**
	 * @param list<string> $values raw UF_CRM_TASK values of the whole page
	 * @return array<string, CrmItemGridDataInterface> keyed by the original value; unsupported and
	 *                                                 malformed values are absent
	 */
	public function getGridDataMap(array $values, int $userId): array
	{
		return Container::getInstance()
			->get(CrmItemGridService::class)
			->getGridDataMap($values, $userId)
		;
	}

	/**
	 * Fail-closed view of a value the page-wide map does not carry -- a cell built outside
	 * Grid::prepareRows(). Null means the value is no supported CRM binding at all.
	 */
	public function makeHiddenData(mixed $value): ?CrmItemGridDataInterface
	{
		$reference = CrmItemReference::tryFrom($value);
		if ($reference === null)
		{
			return null;
		}

		return CrmItemGridData::hidden(
			$reference,
			(string)CCrmEntitySelectorHelper::getHiddenTitle($reference->typeName),
		);
	}

	public function mapToSlug(int $typeId, int $entityId): ?string
	{
		return (new CrmIdMapper())->mapToId($typeId, $entityId);
	}

	/**
	 * Title of the CRM type, empty when the type carries no factory. Not escaped: escaping belongs to
	 * the cell that renders it.
	 */
	public function resolveEntityTypeTitle(int $typeId): string
	{
		if (!Loader::includeModule('crm'))
		{
			return '';
		}

		$factory = Service\Container::getInstance()->getFactory($typeId);

		return $factory === null ? '' : $factory->getEntityDescription();
	}
}
