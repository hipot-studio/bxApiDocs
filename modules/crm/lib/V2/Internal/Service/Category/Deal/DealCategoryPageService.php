<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category\Deal;

use Bitrix\Crm\Category\DealCategory;
use Bitrix\Crm\V2\Internal\Entity\Category\Deal\DealCategoryPage;
use Bitrix\Crm\V2\Internal\Repository\Category\Deal\DealCategoryRepository;

final class DealCategoryPageService
{
	private const DEFAULT_SORT_FIELD = 'SORT';
	private const ALLOWED_SORT_FIELDS = [
		'ID',
		'NAME',
		'SORT',
		'CREATED_DATE',
	];

	private DealCategoryRepository $repository;
	private ?int $userCount = null;
	private ?array $defaultRankOrder = null;
	private ?int $defaultRank = null;

	public function __construct(?DealCategoryRepository $repository = null)
	{
		$this->repository = $repository ?? new DealCategoryRepository();
	}

	public function getTotalCount(): int
	{
		return $this->getUserCount() + 1;
	}

	public function getPageOffsetForCategory(
		int $categoryId,
		array $requestedOrder,
		int $pageSize,
	): ?int
	{
		if ($categoryId < 0)
		{
			return null;
		}

		$pageSize = max(1, $pageSize);
		$order = $this->normalizeOrder($requestedOrder);
		$defaultRank = $this->getDefaultRank($order);
		if ($categoryId === 0)
		{
			return intdiv($defaultRank, $pageSize) * $pageSize;
		}

		$targetCategory = $this->repository->fetchById($categoryId);
		if ($targetCategory === null)
		{
			return null;
		}

		$userRank = $this->repository->countBefore($targetCategory, $order);

		$categoryRank = $userRank + ($defaultRank <= $userRank ? 1 : 0);

		return intdiv($categoryRank, $pageSize) * $pageSize;
	}

	public function getPage(array $requestedOrder, int $requestedOffset, int $pageSize): DealCategoryPage
	{
		$pageSize = max(1, $pageSize);
		$totalCount = $this->getTotalCount();
		$offset = $this->normalizeOffset($requestedOffset, $totalCount, $pageSize);
		$order = $this->normalizeOrder($requestedOrder);
		$defaultCategory = $this->createDefaultCategory();
		$defaultRank = $this->getDefaultRank($order);

		if ($offset + $pageSize <= $defaultRank)
		{
			$userOffset = $offset;
			$userLimit = $pageSize;
		}
		elseif ($offset > $defaultRank)
		{
			$userOffset = $offset - 1;
			$userLimit = $pageSize;
		}
		else
		{
			$userOffset = $offset;
			$userLimit = $pageSize - 1;
		}

		$rows = $this->repository->fetchPage($order, $userOffset, $userLimit);
		if ($offset <= $defaultRank && $defaultRank < $offset + $pageSize)
		{
			array_splice($rows, $defaultRank - $offset, 0, [$defaultCategory]);
		}

		return new DealCategoryPage($rows, $totalCount, $offset);
	}

	private function getUserCount(): int
	{
		$this->userCount ??= $this->repository->countAll();

		return $this->userCount;
	}

	private function getDefaultRank(array $order): int
	{
		if ($this->defaultRank === null || $this->defaultRankOrder !== $order)
		{
			$this->defaultRankOrder = $order;
			$this->defaultRank = $this->repository->countBefore($this->createDefaultCategory(), $order);
		}

		return $this->defaultRank;
	}

	private function normalizeOrder(array $requestedOrder): array
	{
		$sortField = array_key_first($requestedOrder);
		$requestedDirection = $sortField !== null ? $requestedOrder[$sortField] : null;
		if (!is_string($sortField) || !in_array($sortField, self::ALLOWED_SORT_FIELDS, true))
		{
			$sortField = self::DEFAULT_SORT_FIELD;
		}

		$direction = is_string($requestedDirection) && strtoupper($requestedDirection) === 'DESC'
			? 'DESC'
			: 'ASC';
		$effectiveSortField = $sortField === 'CREATED_DATE' ? 'ID' : $sortField;

		$order = [
			$effectiveSortField => $direction,
		];
		if ($effectiveSortField !== 'ID')
		{
			$order['ID'] = $direction;
		}

		return $order;
	}

	private function normalizeOffset(int $requestedOffset, int $totalCount, int $pageSize): int
	{
		$lastPageOffset = intdiv(max(0, $totalCount - 1), $pageSize) * $pageSize;

		return min(max(0, $requestedOffset), $lastPageOffset);
	}

	private function createDefaultCategory(): array
	{
		return [
			'ID' => 0,
			'NAME' => DealCategory::getDefaultCategoryName(),
			'SORT' => DealCategory::getDefaultCategorySort(),
			'CREATED_DATE' => null,
			'IS_DEFAULT' => true,
		];
	}
}
