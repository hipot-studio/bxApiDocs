<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Provider\Item;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\SchemaProvider;
use Bitrix\Crm\V2\Public\Entity\Item\Company;
use Bitrix\Crm\V2\Public\Entity\Item\Contact;
use Bitrix\Crm\V2\Public\Entity\Item\Item;
use Bitrix\Crm\V2\Public\Entity\Item\ItemCollection;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\EntityTypeSettings;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Crm\V2\Public\Provider\Item\AbstractItemProvider;
use Bitrix\Crm\V2\Public\Provider\Item\AccessMode;
use Bitrix\Crm\V2\Public\Provider\Item\ItemProvider;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemFilter;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSelect;
use Bitrix\Crm\V2\Public\Provider\Item\Param\ItemSort;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;

class RestItemProvider
{
	public function __construct(
		private readonly EntityType $entityType,
		private readonly int $userId,
		private readonly ?AbstractItemProvider $provider = null,
	)
	{
	}

	/**
	 * @param ItemSelect|string[] $select
	 */
	public function getById(int $itemId, ItemSelect|array $select): ?Item
	{
		$provider = $this->getProvider();
		$select = $this->applyVisibilityFields(ItemSelect::from($select));
		$item = $provider->getById($itemId, $select);
		if ($item === null || !$this->isVisible($item))
		{
			return null;
		}

		return $item;
	}

	/**
	 * @param ItemSelect|string[] $select
	 */
	public function getList(
		ItemSelect|array $select,
		?ItemFilter $filter = null,
		?ItemSort $sort = null,
		?PagerInterface $pager = null,
	): ItemCollection
	{
		$filter = $this->applyListFilter($filter);

		return $this->getProvider()->getList($select, $filter, $sort, $pager);
	}

	public function getCount(?ItemFilter $filter = null): int
	{
		$filter = $this->applyListFilter($filter);

		return $this->getProvider()->getCount($filter);
	}

	public function createIdQuery(?ItemFilter $filter = null, bool $applyListRestrictions = true): ?Query
	{
		if ($applyListRestrictions)
		{
			$filter = $this->applyListFilter($filter);
		}

		return $this->getProvider()->createIdQuery($filter);
	}

	public function createMyCompanyBaseFieldsIdQuery(?ItemFilter $filter = null): ?Query
	{
		if (
			$this->entityType->getId() !== OwnerType::COMPANY
			|| !Container::getInstance()->getUserPermissions($this->userId)->myCompany()->canReadBaseFields()
		)
		{
			return null;
		}

		$filter = $this->applyMyCompanyFilter($filter);

		return $this->getProviderWithoutAccessCheck()->createIdQuery($filter);
	}

	private function applyListFilter(?ItemFilter $filter): ?ItemFilter
	{
		$restrictions = $this->getListRestrictions();
		if ($restrictions === [])
		{
			return $filter;
		}

		if ($filter === null)
		{
			return new ItemFilter($restrictions);
		}

		$rawFilter = $filter->getRaw();
		if (is_array($rawFilter))
		{
			return new ItemFilter(array_merge($rawFilter, $restrictions));
		}

		$combinedFilter = new ConditionTree();
		$combinedFilter->where($rawFilter);
		foreach ($restrictions as $field => $value)
		{
			$combinedFilter->where($this->getFieldNameWithoutOperator($field), '=', $value);
		}

		return new ItemFilter($combinedFilter);
	}

	protected function isVisible(Item $item): bool
	{
		if (method_exists($item, 'getIsRecurring') && $item->getIsRecurring() === true)
		{
			return false;
		}

		return $this->isCompanyVisible($item) && $this->isContactVisible($item);
	}

	protected function isCompanyVisible(Item $item): bool
	{
		return !(
			$item instanceof Company
			&& (
				$item->getIsMyCompany() === true
				|| ($this->isCompanyCategoryRestrictionEnabled()
					&& $this->getRouteCategoryId() !== null
					&& $item->getCategoryId() !== $this->getRouteCategoryId())
			)
		);
	}

	private function isContactVisible(Item $item): bool
	{
		return !(
			$item instanceof Contact
			&& $this->isContactCategoryRestrictionEnabled()
			&& $this->getRouteCategoryId() !== null
			&& $item->getCategoryId() !== $this->getRouteCategoryId()
		);
	}

	/**
	 * @return array<string, string>
	 */
	protected function getListRestrictions(): array
	{
		$restrictions = [];
		if (EntityTypeSettings::of($this->entityType)->isRecurringSupported())
		{
			$restrictions['=isRecurring'] = 'N';
		}

		if ($this->isCompanyCategoryRestrictionEnabled())
		{
			$restrictions['=isMyCompany'] = 'N';
			if ($this->getRouteCategoryId() !== null)
			{
				$restrictions['=categoryId'] = $this->getRouteCategoryId();
			}
		}
		elseif ($this->isContactCategoryRestrictionEnabled())
		{
			if ($this->getRouteCategoryId() !== null)
			{
				$restrictions['=categoryId'] = $this->getRouteCategoryId();
			}
		}

		return $restrictions;
	}

	private function applyVisibilityFields(ItemSelect $select): ItemSelect
	{
		if (EntityTypeSettings::of($this->entityType)->isRecurringSupported())
		{
			$select->withFields('isRecurring');
		}

		if ($this->entityType->getId() === OwnerType::COMPANY)
		{
			$select->withFields('isMyCompany');
		}

		if (
			$this->isCompanyCategoryRestrictionEnabled()
			|| $this->isContactCategoryRestrictionEnabled()
		)
		{
			$select->withFields('categoryId');
		}

		return $select;
	}

	private function getFieldNameWithoutOperator(string $field): string
	{
		return ltrim($field, '=');
	}

	private function isCompanyCategoryRestrictionEnabled(): bool
	{
		return $this->entityType->getId() === OwnerType::COMPANY
			&& !($this instanceof RestMyCompanyItemProvider);
	}

	private function isContactCategoryRestrictionEnabled(): bool
	{
		return $this->entityType->getId() === OwnerType::CONTACT;
	}

	private function getRouteCategoryId(): ?int
	{
		return SchemaProvider::getItemRouteCategoryId($this->entityType);
	}

	private function getProvider(): AbstractItemProvider
	{
		if ($this->provider !== null)
		{
			return $this->provider->withAccessCheck($this->userId, AccessMode::Filter);
		}

		return ItemProvider::forEntityType($this->entityType)
			->withAccessCheck($this->userId, AccessMode::Filter)
		;
	}

	private function getProviderWithoutAccessCheck(): AbstractItemProvider
	{
		return $this->provider ?? ItemProvider::forEntityType($this->entityType);
	}

	private function applyMyCompanyFilter(?ItemFilter $filter): ItemFilter
	{
		if ($filter === null)
		{
			return new ItemFilter(['=isMyCompany' => 'Y']);
		}

		$rawFilter = $filter->getRaw();
		if (is_array($rawFilter))
		{
			return new ItemFilter(array_merge($rawFilter, ['=isMyCompany' => 'Y']));
		}

		$combinedFilter = new ConditionTree();
		$combinedFilter->where($rawFilter);
		$combinedFilter->where('isMyCompany', '=', true);

		return new ItemFilter($combinedFilter);
	}
}
