<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Column\Provider;

use Bitrix\Main\Grid\Column\DataProvider;
use Bitrix\Main\Grid\Column\Type;
use Bitrix\Main\Localization\Loc;


class AiAgentsDataProvider extends DataProvider
{
	public function prepareColumns(): array
	{
		$result = [];

		$result[] =
			$this->createColumn('ID')
				->setType(Type::NUMBER)
				->setName(Loc::getMessage('BIZPROC_AI_AGENTS_COLUMN_ID'))
				->setFirstOrder('asc')
				->setDefault(false)
				->setSort('ID')
		;

		$result[] =
			$this->createColumn('NAME')
				->setName(Loc::getMessage('BIZPROC_AI_AGENTS_COLUMN_NAME'))
				->setDefault(true)
		;

		$result[] =
			$this->createColumn('USED_BY')
				->setName(Loc::getMessage('BIZPROC_AI_AGENTS_COLUMN_USED_BY'))
				->setDefault(true)
				->setWidth(220)
				->setResizeable(false)
		;

		$result[] =
			$this->createColumn('LAUNCHED_BY')
				->setName(Loc::getMessage('BIZPROC_AI_AGENTS_COLUMN_LAUNCHED_BY'))
				->setWidth(220)
				->setPreventDefault(false)
				->setDefault(true)
		;

		$result[] =
			$this->createColumn('LAUNCH_CONTROL')
				->setName(Loc::getMessage('BIZPROC_AI_AGENTS_COLUMN_LAUNCHED'))
				->setWidth(180)
				->setDefault(true)
		;

		return $result;
	}
}