<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\RestView\Product as ProductView;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Text\StringHelper;

final class ProductFieldsService
{
	private const TYPE = ProductTable::TYPE_PRODUCT;

	public function __construct(
		private readonly AccessSupport $accessSupport,
	)
	{
	}

	public function getByFilter(array $filter): Result
	{
		$access = $this->accessSupport->ensureProductEntityRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		if ($filter === [])
		{
			return ServiceResult::validation([new Error('Incorrect filter format')]);
		}

		$filter = $this->normalizeIncomingFilter($filter);
		$filter['PRODUCT_TYPE'] = self::TYPE;

		$view = new ProductView();
		$result = $view->getFieldsByFilter($filter);
		if (!$result->isSuccess())
		{
			return ServiceResult::validation($result->getErrors());
		}

		return ServiceResult::success([
			'fields' => $view->prepareFieldInfos($result->getData()),
		]);
	}

	/**
	 * @param array<array-key, mixed> $filter
 	 * @return array<string, mixed>
	 */
	private function normalizeIncomingFilter(array $filter): array
	{
		$result = [];
		foreach ($filter as $key => $value)
		{
			$key = (string)$key;
			if ($key === strtoupper($key))
			{
				$result[$key] = $value;

				continue;
			}

			$result[strtoupper(StringHelper::camel2snake($key))] = $value;
		}

		return $result;
	}
}
