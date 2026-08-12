<?php

namespace Bitrix\Superset\Internal\Api;

use Bitrix\Main;
use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\RequestResult;

class UnusedElements
{
	private const UNUSED_ELEMENTS_API_LINK = '/api/v1/unused_elements/';

	private ?SupersetInstance $connector;

	public function __construct(SupersetInstance $connector)
	{
		$this->connector = $connector;
	}

	public function getUnusedElements(array $ormParams = []): RequestResult
	{
		$url = self::UNUSED_ELEMENTS_API_LINK . 'get/';
		$query = [];

		$page = $ormParams['page'] ?? 0;
		$pageSize = $ormParams['pageSize'] ?? 20;
		$order = $ormParams['order'] ?? [];
		$filter = $ormParams['filter'] ?? [];

		if ($page)
		{
			$query['page'] = $page;
		}

		if ($pageSize)
		{
			$query['page_size'] = $pageSize;
		}

		if ($order)
		{
			$orderColumn = array_keys($order)[0] ?? null;
			$orderColumn = match ($orderColumn) {
				'DATE_CREATE' => 'created_on',
				'DATE_UPDATE' => 'changed_on',
				default => 'created_on',
			};
			$orderDirection = array_values($order)[0] ?? null;
			$orderDirection = match ($orderDirection) {
				'asc' => 'asc',
				'desc' => 'desc',
				default => 'asc',
			};
			$query['order_column'] = $orderColumn;
			$query['order_direction'] = $orderDirection;
		}

		if ($filter)
		{
			foreach ($filter as $column => $value)
			{
				if ($column === '>=DATE_CREATE')
				{
					$query['filters'][] = [
						'col' => 'created_on',
						'opr' => 'gt',
						'value' => $value,
					];
				}

				if ($column === '<=DATE_CREATE')
				{
					$query['filters'][] = [
						'col' => 'created_on',
						'opr' => 'lt',
						'value' => $value,
					];
				}

				if ($column === '>=DATE_UPDATE')
				{
					$query['filters'][] = [
						'col' => 'changed_on',
						'opr' => 'gt',
						'value' => $value,
					];
				}

				if ($column === '<=DATE_UPDATE')
				{
					$query['filters'][] = [
						'col' => 'changed_on',
						'opr' => 'lt',
						'value' => $value,
					];
				}

				if ($column === 'TYPE')
				{
					$query['filters'][] = [
						'col' => 'type',
						'opr' => 'eq',
						'value' => $value,
					];
				}

				if ($column === 'TITLE')
				{
					$query['filters'][] = [
						'col' => 'name',
						'opr' => 'like',
						'value' => $value,
					];
				}
			}
		}

		$urlParams = http_build_query(['q' => Main\Web\Json::encode($query)]);
		$url .= "?$urlParams";

		return $this->connector->get($url);
	}
}
