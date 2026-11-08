<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Location\Grid\Column;

use Bitrix\Main\Grid\Column\DataProvider;

final class LocationDataProvider extends DataProvider
{
	public function __construct(private readonly array $columnDescriptions)
	{
		parent::__construct();
	}

	public function prepareColumns(): array
	{
		$columns = [];
		foreach ($this->columnDescriptions as $id => $description)
		{
			$title = (string)($description['title'] ?? $id);
			$column = $this->createColumn((string)$id)
				->setName($title)
				->setTitle($title)
				->setSort((string)$id)
				->setDefault((bool)($description['DEFAULT'] ?? false))
			;

			if ($id === 'ID')
			{
				$column->setNecessary(true);
			}

			$columns[] = $column;
		}

		return $columns;
	}
}
