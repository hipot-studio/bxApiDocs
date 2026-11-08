<?php

namespace Bitrix\BIConnector\Integration\Crm;

use Bitrix\Crm\AutomatedSolution\Entity\AutomatedSolutionTable;
use Bitrix\Crm\Model\Dynamic\TypeTable;
use Bitrix\Main\Loader;

/**
 * Codes of the CRM tables that exist only as long as the entity behind them does.
 *
 * They cannot be declared in SystemTableGroupMap: a smart process is created by the administrator
 * on a live portal, and its code carries the identifier of that entity. The codes are built here
 * exactly as the catalog handlers build them - Integration\Crm\DynamicItems for the items,
 * Crm\Integration\BiConnector\DynamicItemsProductMapping for their product rows and
 * Crm\Integration\BiConnector\AutomatedSolutionMapping for the solutions - so a code resolved here
 * always matches a code the catalog publishes.
 */
final class DynamicTableCodes
{
	/**
	 * @return string[] Empty when the crm module is not installed on the portal.
	 */
	public static function getCodes(): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		return [
			...self::getDynamicItemCodes(),
			...self::getAutomatedSolutionCodes(),
		];
	}

	/**
	 * @return string[]
	 */
	private static function getDynamicItemCodes(): array
	{
		$entityTypeIds = TypeTable::query()
			->setSelect(['ENTITY_TYPE_ID'])
			->fetchAll()
		;

		$codes = [];
		foreach ($entityTypeIds as $row)
		{
			$entityTypeId = (int)$row['ENTITY_TYPE_ID'];

			$codes[] = 'crm_dynamic_items_' . $entityTypeId;
			$codes[] = 'crm_dynamic_items_prod_' . $entityTypeId;
		}

		return $codes;
	}

	/**
	 * @return string[]
	 */
	private static function getAutomatedSolutionCodes(): array
	{
		$solutions = AutomatedSolutionTable::query()
			->setSelect(['ID'])
			->fetchAll()
		;

		return array_map(
			static fn(array $row): string => 'crm_automated_solution_' . (int)$row['ID'],
			$solutions,
		);
	}
}
