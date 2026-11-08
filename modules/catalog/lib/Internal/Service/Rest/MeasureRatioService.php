<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\Infrastructure\Rest\Dto\MeasureRatioDto;
use Bitrix\Catalog\Infrastructure\Rest\Repository\CatalogOrmRepository;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * Read-only measure ratios: Engine catalog.ratio.* exposes no write actions either.
 */
final readonly class MeasureRatioService
{
	private CatalogOrmRepository $repository;

	public function __construct(
		private AccessSupport $accessSupport,
	)
	{
		$this->repository = new CatalogOrmRepository(MeasureRatioDto::class);
	}

	public function get(int $id, ?SelectStructure $select = null): Result
	{
		$access = $this->accessSupport->ensurePriceTypeRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$entity = $this->repository->getEntityById($id, $select);
		if ($entity === null)
		{
			return ServiceResult::notFound($id);
		}

		return ServiceResult::success(['item' => $entity]);
	}

	public function list(
		?SelectStructure $select,
		?FilterStructure $filter,
		?OrderStructure $order,
		?PaginationStructure $pagination,
	): Result
	{
		$access = $this->accessSupport->ensurePriceTypeRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		return ServiceResult::success([
			'items' => $this->repository->getCollectionWithExtraFilters(
				$select,
				$filter,
				$order,
				$pagination,
			),
		]);
	}
}
