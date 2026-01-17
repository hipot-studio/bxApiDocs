<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\History\Column\DataProvider;

use Bitrix\Main\Grid\Column\DataProvider;
use Bitrix\Main\Grid\Column\Type;
use Bitrix\Main\Localization\Loc;

class HistoryProvider extends DataProvider
{
	public const TIME_COLUMN = 'TIME';
	public const AUTHOR_COLUMN = 'AUTHOR';
	public const CHANGE_TYPE_COLUMN = 'CHANGESET_LOCATION';
	public const CHANGE_VALUE_COLUMN = 'CHANGESET_VALUE';

	public function prepareColumns(): array
	{
		$columns = [];

		$columns[] =
			$this
				->createColumn(self::TIME_COLUMN)
				->setName(Loc::getMessage('TASKS_V2_HISTORY_GRID_COLUMN_TIME'))
				->setType(Type::DATE)
				->setDefault(true)
		;

		$columns[] =
			$this
				->createColumn(self::AUTHOR_COLUMN)
				->setName(Loc::getMessage('TASKS_V2_HISTORY_GRID_COLUMN_AUTHOR'))
				->setType(Type::HTML)
				->setDefault(true)
		;

		$columns[] =
			$this
				->createColumn(self::CHANGE_TYPE_COLUMN)
				->setName(Loc::getMessage('TASKS_V2_HISTORY_GRID_COLUMN_CHANGESET_LOCATION'))
				->setType(Type::HTML)
				->setDefault(true)
		;

		$columns[] =
			$this
				->createColumn(self::CHANGE_VALUE_COLUMN)
				->setName(Loc::getMessage('TASKS_V2_HISTORY_GRID_COLUMN_CHANGESET_VALUE'))
				->setType(Type::HTML)
				->setDefault(true)
		;

		return $columns;
	}
}
