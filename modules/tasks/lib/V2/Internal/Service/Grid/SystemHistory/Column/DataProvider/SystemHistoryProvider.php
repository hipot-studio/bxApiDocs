<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\SystemHistory\Column\DataProvider;

use Bitrix\Main\Grid\Column\DataProvider;
use Bitrix\Main\Grid\Column\Type;
use Bitrix\Main\Localization\Loc;

class SystemHistoryProvider extends DataProvider
{
	public const TYPE_COLUMN = 'TYPE';
	public const TIME_COLUMN = 'TIME';
	public const MESSAGE_COLUMN = 'MESSAGE';
	public const ERRORS_COLUMN = 'ERRORS';

	public function prepareColumns(): array
	{
		$columns = [];

//		$columns[] =
//			$this
//				->createColumn(self::TYPE_COLUMN)
//				->setName(Loc::getMessage('TASKS_V2_SYSTEM_HISTORY_GRID_COLUMN_TYPE'))
//				->setType(Type::HTML)
//				->setDefault(true)
//		;

		$columns[] =
			$this
				->createColumn(self::TIME_COLUMN)
				->setName(Loc::getMessage('TASKS_V2_SYSTEM_HISTORY_GRID_COLUMN_TIME'))
				->setType(Type::HTML)
				->setDefault(true)
				->setResizeable(false);
		;

		$columns[] =
			$this
				->createColumn(self::MESSAGE_COLUMN)
				->setName(Loc::getMessage('TASKS_V2_SYSTEM_HISTORY_GRID_COLUMN_MESSAGE'))
				->setType(Type::HTML)
				->setDefault(true)
				->setResizeable(false);
		;

//		$columns[] =
//			$this
//				->createColumn(self::ERRORS_COLUMN)
//				->setName(Loc::getMessage('TASKS_V2_SYSTEM_HISTORY_GRID_COLUMN_ERRORS'))
//				->setType(Type::HTML)
//				->setDefault(true)
//		;

		return $columns;
	}
}
