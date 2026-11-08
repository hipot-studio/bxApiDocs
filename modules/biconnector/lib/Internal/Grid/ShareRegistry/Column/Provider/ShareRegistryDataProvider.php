<?php

namespace Bitrix\BIConnector\Internal\Grid\ShareRegistry\Column\Provider;

use Bitrix\Main\Grid\Column\DataProvider;
use Bitrix\Main\Grid\Column\Type;
use Bitrix\Main\Localization\Loc;

/**
 * Registry columns. None is sortable: the order is fixed by the registry provider.
 */
final class ShareRegistryDataProvider extends DataProvider
{
	public const COLUMN_IDS = [
		'DASHBOARD',
		'AUTHOR',
		'STATUS',
		'DATE_EXPIRE',
		'SLICE',
		'DATE_CREATE',
		'DATE_LAST_OPENED',
		'DISABLED_BY',
		'DATE_DISABLED',
	];

	private const WIDTHS = [
		'DASHBOARD' => 250,
		'AUTHOR' => 200,
		'STATUS' => 220,
	];

	public function prepareColumns(): array
	{
		$result = [];
		foreach (self::COLUMN_IDS as $columnId)
		{
			$column = $this->createColumn($columnId)
				->setType(Type::TEXT)
				->setName(Loc::getMessage('BICONNECTOR_SHARE_REGISTRY_COLUMN_' . $columnId) ?? $columnId)
				->setDefault(true)
			;

			if (isset(self::WIDTHS[$columnId]))
			{
				$column->setWidth(self::WIDTHS[$columnId]);
			}

			$result[] = $column;
		}

		return $result;
	}
}
