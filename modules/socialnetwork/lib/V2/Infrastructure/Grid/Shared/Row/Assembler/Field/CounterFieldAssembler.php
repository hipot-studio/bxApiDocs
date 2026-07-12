<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Counter\SingleCounterFormatter;
use Bitrix\Socialnetwork\V2\Public\Provider\Grid\CounterProviderInterface;

class CounterFieldAssembler extends FieldAssembler
{
	private array $formattedCounters = [];

	public function __construct(
		array $columnIds,
		private readonly CounterProviderInterface $counterProvider,
		private readonly SingleCounterFormatter $counterFormatter,
		private readonly int $userId,
	)
	{
		parent::__construct($columnIds);
	}

	public function prepareRows(array $rowList): array
	{
		$this->formattedCounters = [];

		if ($this->userId < 1 || empty($this->getColumnIds()))
		{
			return $rowList;
		}

		$groupIds = array_column($rowList, 'id');
		if (!$groupIds)
		{
			return $rowList;
		}

		$this->formattedCounters = $this->counterFormatter->format(
			$this->counterProvider->get($groupIds, $this->userId),
		);

		$rowList = parent::prepareRows($rowList);
		$this->formattedCounters = [];

		return $rowList;
	}

	protected function prepareRow(array $row): array
	{
		$preparedData = $this->prepareColumn($row['id']);

		$row['counters'] ??= [];
		foreach ($this->getColumnIds() as $columnId)
		{
			$row['counters'][$columnId] = $preparedData;
		}

		return $row;
	}

	protected function prepareColumn(mixed $value): array
	{
		$entityId = (int)$value;

		if ($entityId < 1)
		{
			return [];
		}

		return $this->formattedCounters[$entityId] ?? [];
	}
}
