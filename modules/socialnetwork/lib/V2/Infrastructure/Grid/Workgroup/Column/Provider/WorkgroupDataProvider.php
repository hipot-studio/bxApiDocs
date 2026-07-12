<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Workgroup\Column\Provider;

use Bitrix\Main\Grid\Column\DataProvider;
use Bitrix\Main\Grid\Column\Type;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Localization\ColumnMessage;

/**
 * Column provider for ALL workgroup types grid (COMMON/USER mode).
 * Matches legacy getGridHeaders() column set.
 */
class WorkgroupDataProvider extends DataProvider
{
	public function __construct(
		private readonly bool $isCommonMode = true,
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
			$this->createColumn('DATE_CREATE')
				->setDefault($this->isCommonMode)
				->setName(ColumnMessage::get(ColumnMessage::DATE_CREATE))
				->setSort('DATE_CREATE')
			,
			$this->createColumn('PRIVACY_TYPE')
				->setDefault(true)
				->setName(ColumnMessage::get(ColumnMessage::PRIVACY_TYPE))
			,
			$this->createColumn('DATE_ACTIVITY')
				->setDefault($this->isCommonMode)
				->setName(ColumnMessage::get(ColumnMessage::DATE_ACTIVITY))
				->setSort('DATE_ACTIVITY')
			,
			$this->createColumn('NUMBER_OF_MEMBERS')
				->setDefault(false)
				->setName(ColumnMessage::get(ColumnMessage::NUMBER_OF_MEMBERS))
				->setSort('NUMBER_OF_MEMBERS')
			,
			$this->createColumn('MEMBERS')
				->setDefault(true)
				->setName(ColumnMessage::get(ColumnMessage::MEMBERS))
			,
			$this->createColumn('TAGS')
				->setDefault(false)
				->setName(ColumnMessage::get(ColumnMessage::TAGS))
				->setType(Type::TAGS)
				->setEditable(true)
			,
		];

		if ($this->userId > 0)
		{
			$columns[] = $this->createColumn('ROLE')
				->setDefault(true)
				->setName(ColumnMessage::get(ColumnMessage::ROLE))
			;
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

		return $columns;
	}
}
