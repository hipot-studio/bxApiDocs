<?php

namespace Bitrix\Crm\Component\EntityList\ClientTab;

use Bitrix\Crm\Binding\OwnerBindingFilter;
use Bitrix\Crm\Component\EntityList\GridId;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\EditorAdapter;
use CCrmOwnerType;

/**
 * Client tab mode of the contact (company) list: the list shows the items bound to the owner.
 *
 * The context is built from the unsigned component parameters only, on every request.
 * A context exists only for a supported pair of types, an existing owner and a user who can read the owner.
 * A company or a contact owner in a category other than the default one has no client tab and no context.
 * The right to the child type is not required: without it the tab lists all its rows as hidden.
 */
final class ClientTabContext
{
	public const PARAM_NAME = 'CLIENT_TAB';

	private const GRID_ID_SUFFIX_PREFIX = 'CLIENT_TAB_';
	private const FILTER_KEY = 'BINDING_OWNER';

	private ?bool $canUpdateOwner = null;
	private ?bool $canReadChildType = null;
	private ?bool $canAddChildType = null;
	private bool $isPrimaryChildIdLoaded = false;
	private ?int $primaryChildId = null;

	private function __construct(
		private readonly int $childEntityTypeId,
		private readonly ItemIdentifier $owner,
	)
	{
	}

	/**
	 * Tells whether the parameters ask for the client tab mode.
	 * Such parameters are admitted only through {@see self::tryCreate()}.
	 */
	public static function hasParameter(array $unsignedParams): bool
	{
		return array_key_exists(self::PARAM_NAME, $unsignedParams);
	}

	/**
	 * @param array $unsignedParams Component parameters with the signature already checked and removed.
	 * Only $unsignedParams['CLIENT_TAB'] = ['OWNER_ENTITY_TYPE_ID' => int, 'OWNER_ENTITY_ID' => int] is read.
	 */
	public static function tryCreate(array $unsignedParams, int $childEntityTypeId): ?self
	{
		$value = $unsignedParams[self::PARAM_NAME] ?? null;
		if (!is_array($value))
		{
			return null;
		}

		$ownerEntityTypeId = $value['OWNER_ENTITY_TYPE_ID'] ?? null;
		$ownerEntityId = $value['OWNER_ENTITY_ID'] ?? null;
		if (!is_numeric($ownerEntityTypeId) || !is_numeric($ownerEntityId))
		{
			return null;
		}

		$ownerEntityTypeId = (int)$ownerEntityTypeId;
		$ownerEntityId = (int)$ownerEntityId;
		if (
			$ownerEntityId <= 0
			|| !CCrmOwnerType::IsDefined($ownerEntityTypeId)
			|| !OwnerBindingFilter::isSupported($childEntityTypeId, $ownerEntityTypeId)
		)
		{
			return null;
		}

		$owner = self::loadOwner($ownerEntityTypeId, $ownerEntityId);
		if (
			$owner === null
			|| self::isClientOwnerInCategory($owner)
			|| !Container::getInstance()->getUserPermissions()->item()->canReadItemIdentifier($owner)
		)
		{
			return null;
		}

		return new self($childEntityTypeId, $owner);
	}

	public function getOwner(): ItemIdentifier
	{
		return $this->owner;
	}

	public function getChildEntityTypeId(): int
	{
		return $this->childEntityTypeId;
	}

	public function getGridIdSuffix(): string
	{
		return self::GRID_ID_SUFFIX_PREFIX . CCrmOwnerType::ResolveName($this->owner->getEntityTypeId());
	}

	/**
	 * Tells whether the grid is a client tab of the child type: its id is the list id with {@see self::getGridIdSuffix()}.
	 * The id says nothing about the owner, it is the same for all the cards of one owner type.
	 */
	public static function isClientTabGridId(int $childEntityTypeId, string $gridId): bool
	{
		if (
			$childEntityTypeId !== CCrmOwnerType::Contact
			&& $childEntityTypeId !== CCrmOwnerType::Company
		)
		{
			return false;
		}

		$prefix = (new GridId($childEntityTypeId))->getValue(self::GRID_ID_SUFFIX_PREFIX);

		return strlen($gridId) > strlen($prefix) && str_starts_with($gridId, $prefix);
	}

	/**
	 * Restricts the list filter to the owner. The key is set last and replaces a key of the same name
	 * with any operator prefix, so a user filter cannot change the owner.
	 */
	public function applyTo(array $filter): array
	{
		foreach (array_keys($filter) as $key)
		{
			if (is_string($key) && preg_match('/^[^A-Za-z0-9_]*' . self::FILTER_KEY . '$/', $key))
			{
				unset($filter[$key]);
			}
		}

		$filter[self::FILTER_KEY] = [
			'ENTITY_TYPE_ID' => $this->owner->getEntityTypeId(),
			'ENTITY_ID' => $this->owner->getEntityId(),
		];

		return $filter;
	}

	/**
	 * The ID step of the list may skip permissions only while the final filter is restricted to the owner
	 * of this context. The rows are checked afterwards by {@see self::splitReadable()}.
	 */
	public function isPermissionFreeListing(array $finalFilter): bool
	{
		if (!array_key_exists(self::FILTER_KEY, $finalFilter))
		{
			return false;
		}

		$filterOwner = OwnerBindingFilter::tryCreateOwnerFromFilterValue($finalFilter[self::FILTER_KEY]);

		return
			$filterOwner !== null
			&& $filterOwner->getEntityTypeId() === $this->owner->getEntityTypeId()
			&& $filterOwner->getEntityId() === $this->owner->getEntityId()
		;
	}

	public function canUpdateOwner(): bool
	{
		if ($this->canUpdateOwner === null)
		{
			$this->canUpdateOwner = Container::getInstance()
				->getUserPermissions()
				->item()
				->canUpdateItemIdentifier($this->owner)
			;
		}

		return $this->canUpdateOwner;
	}

	/**
	 * The right to read the list of the child type. The tab is admitted without it, all its rows are hidden then.
	 */
	public function canReadChildType(): bool
	{
		if ($this->canReadChildType === null)
		{
			$this->canReadChildType = Container::getInstance()
				->getUserPermissions()
				->entityType()
				->canReadItemsInCategory($this->childEntityTypeId, 0)
			;
		}

		return $this->canReadChildType;
	}

	/**
	 * "Bind": the selector shows only readable items, so the child type must be readable.
	 */
	public function canBindChildren(): bool
	{
		return $this->canUpdateOwner() && $this->canReadChildType();
	}

	/**
	 * "Create": a new item of the child type is bound to the owner.
	 */
	public function canCreateChild(): bool
	{
		if (!$this->canUpdateOwner())
		{
			return false;
		}

		if ($this->canAddChildType === null)
		{
			$this->canAddChildType = Container::getInstance()
				->getUserPermissions()
				->entityType()
				->canAddItemsInCategory($this->childEntityTypeId, 0)
			;
		}

		return $this->canAddChildType;
	}

	/**
	 * Splits the IDs of a page into readable and hidden ones.
	 * Permission attributes of the whole page are loaded at once.
	 *
	 * @param int[] $pageIds
	 * @return array{readable: int[], hidden: int[]}
	 */
	public function splitReadable(array $pageIds): array
	{
		$ids = [];
		foreach ($pageIds as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$ids[$id] = $id;
			}
		}
		$ids = array_values($ids);

		$result = [
			'readable' => [],
			'hidden' => [],
		];
		if (empty($ids))
		{
			return $result;
		}

		$permissions = Container::getInstance()->getUserPermissions()->item();
		$permissions->preloadPermissionAttributes($this->childEntityTypeId, $ids);

		foreach ($ids as $id)
		{
			if ($permissions->canRead($this->childEntityTypeId, $id))
			{
				$result['readable'][] = $id;
			}
			else
			{
				$result['hidden'][] = $id;
			}
		}

		return $result;
	}

	public function supportsPrimary(): bool
	{
		// IS_PRIMARY of a contact-company binding marks the primary company of the contact,
		// a company has no primary contact
		return $this->owner->getEntityTypeId() !== CCrmOwnerType::Company;
	}

	public function getPrimaryChildId(): ?int
	{
		if (!$this->supportsPrimary())
		{
			return null;
		}

		if (!$this->isPrimaryChildIdLoaded)
		{
			$this->primaryChildId = OwnerBindingFilter::getPrimaryChildId($this->childEntityTypeId, $this->owner);
			$this->isPrimaryChildIdLoaded = true;
		}

		return $this->primaryChildId;
	}

	/**
	 * Name of the field in the owner card editor that holds the bindings shown in the tab.
	 */
	public function getClientFieldName(): string
	{
		return match ($this->owner->getEntityTypeId())
		{
			CCrmOwnerType::Company => 'CONTACT',
			CCrmOwnerType::Contact => 'COMPANY',
			default => EditorAdapter::FIELD_CLIENT,
		};
	}

	public function toPanelOptions(): ClientTabPanelOptions
	{
		return new ClientTabPanelOptions($this->owner, $this->canUpdateOwner(), $this->canReadChildType());
	}

	/**
	 * The card of a company or a contact in a category shows no client tab, so a context for it is refused
	 * the same way as without the owner read: a stale signed tab or a direct call gets nothing.
	 * The category of other owners and of the child items does not matter.
	 */
	private static function isClientOwnerInCategory(ItemIdentifier $owner): bool
	{
		return
			in_array($owner->getEntityTypeId(), [CCrmOwnerType::Company, CCrmOwnerType::Contact], true)
			&& (int)$owner->getCategoryId() > 0
		;
	}

	/**
	 * Reads the owner with its category. My company is not a client and has no client tabs.
	 */
	private static function loadOwner(int $entityTypeId, int $entityId): ?ItemIdentifier
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if (!$factory)
		{
			return null;
		}

		$select = ['ID'];
		$categoryFieldName = null;
		if ($factory->isCategoriesSupported())
		{
			$categoryFieldName = $factory->getEntityFieldNameByMap(Item::FIELD_NAME_CATEGORY_ID);
			$select[] = $categoryFieldName;
		}
		if ($entityTypeId === CCrmOwnerType::Company)
		{
			$select[] = Item\Company::FIELD_NAME_IS_MY_COMPANY;
		}

		$row = $factory->getDataClass()::getList([
			'select' => $select,
			'filter' => ['=ID' => $entityId],
			'limit' => 1,
		])->fetch();
		if (!$row)
		{
			return null;
		}

		if (
			$entityTypeId === CCrmOwnerType::Company
			&& in_array($row[Item\Company::FIELD_NAME_IS_MY_COMPANY] ?? null, [true, 'Y'], true)
		)
		{
			return null;
		}

		$categoryId = $categoryFieldName !== null && isset($row[$categoryFieldName])
			? (int)$row[$categoryFieldName]
			: null
		;

		return new ItemIdentifier($entityTypeId, $entityId, $categoryId);
	}
}
