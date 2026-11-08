<?php

namespace Bitrix\Crm\Integration\UI\EntitySelector;

use Bitrix\Crm\Category\ItemCategoryUserField;
use Bitrix\Crm\Integration\DocumentGeneratorManager;
use Bitrix\Crm\Service\Container;
use Bitrix\DocumentGenerator\DataProviderManager;
use Bitrix\DocumentGenerator\Document;
use Bitrix\DocumentGenerator\Template;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use CCrmOwnerType;

class PlaceholderProvider extends BaseProvider
{
	public const ENTITY_ID = 'placeholder';

	protected const ITEMS_VIEW_TYPE_LIST = 'list';
	protected const ITEMS_VIEW_TYPE_TREE = 'tree';
	protected const ITEM_ID_WHITE_LIST = [
		'BANK_DETAIL',
		'COMPANY',
		'CONTACT',
		'PRODUCTS_PRODUCT',
		'MY_COMPANY',
		'ASSIGNED',
		'REQUISITE',
	];
	protected const ITEM_ID_BLACK_LIST = [
		'OPENED',
		'COMMENTS',
		'REVENUE',
		'WEBFORM_ID',
		'ORIGINATOR_ID',
		'ORIGIN_ID',
		'ORIGIN_VERSION',
		'OBSERVER_IDS',
		'OBSERVERS',
		'OPPORTUNITY_ACCOUNT',
		'ORDER_BINDING',
		'SOURCE_ID',
		'SOURCE_BY',
		'SOURCE_DESCRIPTION',
		'UTM_SOURCE',
		'UTM_MEDIUM',
		'UTM_CAMPAIGN',
		'UTM_CONTENT',
		'UTM_TERM',
		'PRODUCT_ID',
		'QUOTE_ID',
		'BIRTHDAY_SORT',
		'IS_MANUAL_OPPORTUNITY',
		'IS_REPEATED_APPROACH',
		'IS_NEW',
		'MOVED_BY_ID',
		'HISTORY',
		'FULL_HISTORY',
		'EXPORT',
		'BINDINGS',
		'CURRENCY_ID',
		'XML_ID',
		'CLIENT_PHONE',
		'CLIENT_EMAIL',
		'REQUISITE.PRESET_ID',
		'REQUISITE.RQ_COMPANY_ID',
		'CRM_DEAL_RECURRING',
		'.ID', // TODO: temporary disable show ID field
		'LAST_ACTIVITY_BY',
		'LAST_ACTIVITY_TIME',
		'STAGE_ID',
		'CATEGORY_ID',
	];
	protected const INCLUDE_UF_ITEMS_TO_WHITE_LIST = true;

	protected string $viewType;
	protected int $entityTypeId;
	protected ?int $categoryId;
	private array $entityCategoryDependentCache = [];

	public function __construct(array $options = [])
	{
		parent::__construct();

		$this->entityTypeId = (int)($options['entityTypeId'] ?? 0);
		$this->viewType = $options['viewType'] ?? static::ITEMS_VIEW_TYPE_TREE;
		$this->categoryId = isset($options['categoryId']) ? (int)$options['categoryId'] : null;
	}

	public function isAvailable(): bool
	{
		if (!DocumentGeneratorManager::getInstance()->isEnabled())
		{
			return false;
		}

		return
			Container::getInstance()
			->getUserPermissions()
			->entityType()
			->canReadItems($this->entityTypeId)
		;
	}

	public function fillDialog(Dialog $dialog): void
	{
		$dialog->addRecentItems($this->makeItems());
	}

	public function getItems(array $ids): array
	{
		return $this->makeItems();
	}

	public function getSelectedItems(array $ids): array
	{
		return $this->makeItems();
	}

	protected function makeItems(): array
	{
		if (!DocumentGeneratorManager::getInstance()->isEnabled())
		{
			return [];
		}

		$providerClassName = DocumentGeneratorManager::getInstance()
			->getCrmOwnerTypeProvider($this->entityTypeId, false)
		;
		if ($providerClassName === null)
		{
			return [];
		}

		$placeholders = DataProviderManager::getInstance()->getDefaultTemplateFields(
			$providerClassName,
			[],
			[],
			false
		);

		$placeholders = $this->filterItems($placeholders);
		$placeholders = $this->filterUserFieldsByCategory($placeholders);
		if ($this->viewType === static::ITEMS_VIEW_TYPE_LIST)
		{
			return $this->makeItemsAsFlatList($placeholders);
		}

		if ($this->viewType === static::ITEMS_VIEW_TYPE_TREE)
		{
			return $this->makeItemsAsTree($placeholders);
		}

		return [];
	}

	protected function filterItems(array $input): array
	{
		$duplicatingItems = [];

		// modify 'PRODUCTS.PRODUCT' key
		array_walk($input, static function (array &$row) use (&$duplicatingItems) {
			static $productProps = [];
			if (str_contains($row['VALUE'], 'PRODUCTS.PRODUCT'))
			{
				$row['VALUE'] = str_replace('PRODUCTS.PRODUCT', 'PRODUCTS_PRODUCT', $row['VALUE']);

				if (str_contains($row['VALUE'], 'PROPERTY_'))
				{
					if (in_array($row['TITLE'], $productProps, true))
					{
						$duplicatingItems[] = $row['VALUE'];
					}
					else
					{
						$productProps[] = $row['TITLE'];
					}

				}
			}
		});

		return array_filter(
			$input,
			static function(array $row) use ($duplicatingItems)
			{
				$value = $row['VALUE'] ?? '';
				$isImageType = isset($row['TYPE']) && $row['TYPE'] === 'IMAGE';
				$isStampType = isset($row['TYPE']) && $row['TYPE'] === 'STAMP';
				$isInBlackList = !empty(
					array_filter(
						static::ITEM_ID_BLACK_LIST,
						static fn(string $word) => str_ends_with($value, $word)
					)
				);
				if (
					$isImageType
					|| $isStampType
					|| $isInBlackList
					|| in_array($value, $duplicatingItems, true)
				)
				{
					return false;
				}

				$valueArr = array_slice(explode('.', $value), 2, -1);
				$val = implode('.', $valueArr);

				return
					empty($valueArr)												// entity placeholders
					|| in_array($val, static::ITEM_ID_WHITE_LIST)	// white list placeholders
					|| (static::isUserField($value) && static::INCLUDE_UF_ITEMS_TO_WHITE_LIST)
				;
			}
		);
	}

	protected static function isUserField(string $value): bool
	{
		// The user field name sits at segment index 2 (e.g. "this.SOURCE.UF_CRM_123"),
		// the same position extractUserFieldName() reads. The previous check dropped the
		// trailing segment and required a TITLE/ITEM.VALUE suffix, which silently excluded
		// scalar user fields (string/number/...) whose value carries no such suffix — they
		// leaked past category scoping in both the selector and the insert-time cut.
		$segments = explode('.', $value);

		return isset($segments[2]) && str_starts_with($segments[2], 'UF_CRM');
	}

	protected static function extractUserFieldName(string $value): string
	{
		return explode('.', $value)[2] ?? '';
	}

	protected function isEntityCategoryDependent(): bool
	{
		// Keyed by entity type because MultiplePlaceholderProvider reuses
		// $this->entityTypeId across several entity types in a single pass.
		$this->entityCategoryDependentCache[$this->entityTypeId] ??=
			(new ItemCategoryUserField($this->entityTypeId))->isCategoryDependent();

		return $this->entityCategoryDependentCache[$this->entityTypeId];
	}

	protected function isCategoryDependentField(string $value): bool
	{
		return static::isUserField($value) && $this->isEntityCategoryDependent();
	}

	/**
	 * Keep only the user fields visible in the current category.
	 *
	 * Opt-in: applies only when a category id is supplied and the entity type is
	 * category-governed (Contact/Company). Base fields and user fields of
	 * category-agnostic entity types pass through untouched. Only the user-field
	 * subset is run through the category filter so an empty mapping does not wipe
	 * the base fields.
	 */
	protected function filterUserFieldsByCategory(array $input): array
	{
		if ($this->categoryId === null)
		{
			return $input;
		}

		$categoryUserFields = new ItemCategoryUserField($this->entityTypeId);
		if (!$categoryUserFields->isCategoryDependent())
		{
			return $input;
		}

		$userFieldKeys = [];
		foreach ($input as $key => $row)
		{
			$value = $row['VALUE'] ?? $key;
			if (static::isUserField($value))
			{
				$userFieldKeys[$key] = static::extractUserFieldName($value);
			}
		}

		if (empty($userFieldKeys))
		{
			return $input;
		}

		$allowedUserFields = $categoryUserFields->filter($this->categoryId, array_flip($userFieldKeys));

		return array_filter(
			$input,
			static function (string $key) use ($userFieldKeys, $allowedUserFields): bool {
				if (!isset($userFieldKeys[$key]))
				{
					return true;
				}

				return isset($allowedUserFields[$userFieldKeys[$key]]);
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	protected function makeItemsAsTree(array $input): array
	{
		if (empty($input))
		{
			return [];
		}

		$rootBranches = $this->makeTreeRootItems($input);
		$groupedItems = $this->groupTreeItems($input);

		$result = [];
		foreach ($rootBranches as $id => $title)
		{
			$result[] = new Item([
				'id' => $id,
				'entityId' => static::ENTITY_ID,
				'title' => $title,
				'tabs' => 'recents',
				'searchable' => false,
				'children' => $this->makeItemChildren($groupedItems[$id] ?? $groupedItems, $id, $title),
			]);
		}

		return $result;
	}

	private function makeItemsAsFlatList(array $input): array
	{
		if (empty($input))
		{
			return [];
		}

		$result = [];
		foreach ($input as $placeholder => $row)
		{
			$result[] = new Item([
				'id' => $placeholder,
				'entityId' => static::ENTITY_ID,
				'title' => $row['TITLE'] ?? $placeholder,
				'customData' => [
					'value' =>  $row['VALUE'] ?? $placeholder,
					'entityTypeName' => static::ENTITY_ID,
					'isUf' => static::isUserField($row['VALUE'] ?? $placeholder),
					'categoryDependent' => $this->isCategoryDependentField($row['VALUE'] ?? $placeholder),
				],
			]);
		}

		return $result;
	}

	private function makeTreeRootItems(array $input): array
	{
		$result = [];

		foreach ($input as $row)
		{
			$valueArr = array_slice(explode('.', $row['VALUE'] ?? ''), 2, -1);
			$cnt = count($valueArr);
			if ($cnt === 0)
			{
				$result[CCrmOwnerType::ResolveName($this->entityTypeId)] = CCrmOwnerType::GetDescription($this->entityTypeId);
			}
			elseif (
				$cnt === 1
				|| (str_starts_with($valueArr[0], 'UF_CRM') && $valueArr[1] === 'ITEM')
			)
			{
				$result[$valueArr[0]] = $row['GROUP'][0];
			}
		}

		return $result;
	}

	private function groupTreeItems(array $input): array
	{
		foreach ($input as $placeholder => &$row)
		{
			$row['placeholder'] = $placeholder;
		}
		unset($row);

		$result = array_reduce($input, static function($carry, $item)
		{
			$uidParts = explode('.', $item['VALUE']);
			$lastUidPart = array_pop($uidParts);

			$currentArray = &$carry;
			foreach ($uidParts as $uidPart)
			{
				if (!isset($currentArray[$uidPart]))
				{
					$currentArray[$uidPart] = [];
				}
				$currentArray = &$currentArray[$uidPart];
			}

			$currentArray[$lastUidPart] = $item;

			return $carry;
		}, []);

		return $result[Document::THIS_PLACEHOLDER][Template::MAIN_PROVIDER_PLACEHOLDER] ?? [];
	}

	private function makeItemChildren(array $input, string $parentId, string $parentTitle): array
	{
		if (empty($input))
		{
			return [];
		}

		if (array_key_exists('ITEM', $input) && str_starts_with($parentId, 'UF_CRM'))
		{
			$input = $input['ITEM'];
		}

		$childrenItems = [];

		$leaves = array_filter(
			$input,
			static function(array $row): bool {
				return array_key_exists('TITLE', $row)
					&& array_key_exists('VALUE', $row)
					&& array_key_exists('GROUP', $row)
				;
			}
		);
		if (!empty($leaves))
		{
			foreach ($leaves as $placeholder => $leave)
			{
				if (str_ends_with($leave['VALUE'], $leave['TITLE']))
				{
					continue; // skip not localized items
				}

				$childrenItems[] = [
					'id' => $leave['placeholder'],
					'entityId' => $parentId,
					'supertitle' => $parentTitle,
					'title' => $leave['TITLE'] ,
					'customData' => [
						'value' => $leave['VALUE'] ?? $placeholder,
						'entityTypeName' => $parentId,
						'isUf' => static::isUserField($leave['VALUE'] ?? $placeholder),
						'categoryDependent' => $this->isCategoryDependentField($leave['VALUE'] ?? $placeholder),
						'fieldName' => str_replace(
							Document::THIS_PLACEHOLDER . '.' . Template::MAIN_PROVIDER_PLACEHOLDER . $parentId,
							'',
							$leave['VALUE']
						),
						'text' => $parentTitle . ': ' . $leave['TITLE'],
					],
				];
			}
		}

		return $childrenItems;
	}
}
