<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Project\Column\Provider;

use Bitrix\Main\Grid\Column\DataProvider;
use Bitrix\Main\Grid\Column\Type;
use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Localization\ColumnMessage;

class ProjectDataProvider extends DataProvider
{
	public function __construct(
		private readonly bool $isProjectMode = true,
		private readonly int $userId = 0,
	)
	{
		parent::__construct();
	}

	public function prepareColumns(): array
	{
		$columns = [
			$this->createColumn('ID')
				->setDefault(true)
				->setName('ID')
				->setSort('ID')
			,
			$this->createColumn('NAME')
				->setDefault(true)
				->setName(ColumnMessage::get(ColumnMessage::NAME))
				->setSort('NAME')
			,
			$this->createColumn('ACTIVITY_DATE')
				->setDefault(true)
				->setName(ColumnMessage::get(ColumnMessage::ACTIVITY_DATE))
				->setSort('ACTIVITY_DATE')
			,
		];

		if ($this->isProjectMode)
		{
			$columns[] = $this->createColumn('EFFICIENCY')
				->setDefault(true)
				->setName(Loc::getMessage('SONET_V2_GRID_PROJECT_COLUMN_EFFICIENCY'))
			;
		}

		$columns[] = $this->createColumn('MEMBERS')
			->setDefault(true)
			->setName(ColumnMessage::get(ColumnMessage::MEMBERS))
		;

		if ($this->userId > 0)
		{
			$columns[] = $this->createColumn('ROLE')
				->setDefault(true)
				->setName(ColumnMessage::get(ColumnMessage::ROLE))
			;
		}

		$columns[] = $this->createColumn('TAGS')
			->setDefault(false)
			->setName(ColumnMessage::get(ColumnMessage::TAGS))
			->setType(Type::TAGS)
			->setEditable(true)
		;

		$columns[] = $this->createColumn('PRIVACY_TYPE')
			->setDefault(true)
			->setName(ColumnMessage::get(ColumnMessage::PRIVACY_TYPE))
		;

		if ($this->userId > 0)
		{
			$columns[] = $this->createColumn('DATE_RELATION')
				->setDefault(false)
				->setName(ColumnMessage::get(ColumnMessage::DATE_RELATION))
				->setSort('DATE_RELATION')
			;
			$columns[] = $this->createColumn('DATE_VIEW')
				->setDefault(false)
				->setName(ColumnMessage::get(ColumnMessage::DATE_VIEW))
				->setSort('DATE_VIEW')
			;
		}

		if ($this->isProjectMode)
		{
			$columns[] = $this->createColumn('PROJECT_DATE_START')
				->setDefault(false)
				->setName(Loc::getMessage('SONET_V2_GRID_PROJECT_COLUMN_PROJECT_DATE_START'))
				->setSort('PROJECT_DATE_START')
			;
			$columns[] = $this->createColumn('PROJECT_DATE_FINISH')
				->setDefault(false)
				->setName(Loc::getMessage('SONET_V2_GRID_PROJECT_COLUMN_PROJECT_DATE_FINISH'))
				->setSort('PROJECT_DATE_FINISH')
			;
		}

		$columns[] = $this->createColumn('DATE_CREATE')
			->setDefault(false)
			->setName(ColumnMessage::get(ColumnMessage::DATE_CREATE))
			->setSort('DATE_CREATE')
		;

		$columns[] = $this->createColumn('DATE_ACTIVITY')
			->setDefault(false)
			->setName(ColumnMessage::get(ColumnMessage::DATE_ACTIVITY))
			->setSort('DATE_ACTIVITY')
		;

		$columns[] = $this->createColumn('NUMBER_OF_MEMBERS')
			->setDefault(false)
			->setName(ColumnMessage::get(ColumnMessage::NUMBER_OF_MEMBERS))
			->setSort('NUMBER_OF_MEMBERS')
		;

		return $columns;
	}
}
