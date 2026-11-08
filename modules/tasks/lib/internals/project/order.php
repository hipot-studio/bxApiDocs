<?php

namespace Bitrix\Tasks\Internals\Project;

use Bitrix\Main\Grid;

class Order
{
	private const AVAILABLE_SORT_FIELDS = [
		'ID',
		'NAME',
		'ACTIVITY_DATE',
		'OPENED',
		'PROJECT_DATE_START',
		'PROJECT_DATE_FINISH',
	];

	private $gridId;

	public function __construct(string $gridId)
	{
		$this->gridId = $gridId;
	}

	private function getDefaultGridSorting(): array
	{
		return [
			'sort' => ['ACTIVITY_DATE' => 'desc'],
			'vars' => [
				'by' => 'by',
				'order' => 'order',
			],
		];
	}

	public function getGridSorting(): array
	{
		$gridOptions = new Grid\Options($this->gridId);
		$sorting = $this->prepareGridSorting(
			$gridOptions->getSorting($this->getDefaultGridSorting())['sort']
		);

		return $sorting ?: $this->getDefaultGridSorting()['sort'];
	}

	public function getOrder(): array
	{
		$order = ['IS_PINNED' => 'desc'];
		$order = array_merge($order, $this->getGridSorting());
		$order['ID'] ??= 'asc';

		return $order;
	}

	private function prepareGridSorting(array $sorting): array
	{
		$result = [];
		foreach ($sorting as $field => $direction)
		{
			$direction = mb_strtolower((string)$direction);
			if (
				in_array($field, self::AVAILABLE_SORT_FIELDS, true)
				&& in_array($direction, ['asc', 'desc'], true)
			)
			{
				$result[$field] = $direction;
			}
		}

		return $result;
	}
}
