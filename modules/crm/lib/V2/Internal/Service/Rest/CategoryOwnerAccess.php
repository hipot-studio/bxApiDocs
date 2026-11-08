<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Rest;

use Bitrix\Crm\V2\Public\Entity\Item\Interface\HasCategoriesInterface;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Crm\V2\Public\Provider\Item\AccessMode;
use Bitrix\Crm\V2\Public\Provider\Item\ItemProvider;

final class CategoryOwnerAccess
{
	private readonly \Closure $categoryLoader;

	public function __construct(?\Closure $categoryLoader = null)
	{
		$this->categoryLoader = $categoryLoader ?? $this->loadCategoryId(...);
	}

	public function assertAllowed(ItemId $owner, int $expectedCategoryId, int $userId): void
	{
		$actualCategoryId = ($this->categoryLoader)($owner, $userId);
		if ($actualCategoryId !== $expectedCategoryId)
		{
			throw new CategoryOwnerAccessException();
		}
	}

	private function loadCategoryId(ItemId $owner, int $userId): ?int
	{
		$item = ItemProvider::forEntityType($owner->getEntityType())
			->withAccessCheck($userId, AccessMode::Filter)
			->getById($owner->getId(), ['categoryId'])
		;

		return $item instanceof HasCategoriesInterface ? $item->getCategoryId() : null;
	}
}
