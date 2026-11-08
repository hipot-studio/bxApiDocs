<?php

namespace Bitrix\BIConnector\Integration\Crm;

use Bitrix\Main\Localization\Loc;

/**
 * Category name of an entity that keeps its categories in b_crm_item_category.
 *
 * Unlike deal funnels, the default category has no row in b_crm_item_category and system ones
 * keep an empty NAME there, so both names are inlined: the default one is a phrase the dataset
 * passes in, system ones are resolved from phrases of the crm module.
 * A dangling CATEGORY_ID gives null instead of the default category name.
 *
 * The expression is built for a dataset that aliases its own table as C and the dictionary as DC.
 */
trait CategoryNameTrait
{
	/**
	 * Codes of the system categories of the entity, as they are declared in the crm module.
	 *
	 * @return string[]
	 */
	abstract protected static function getSystemCategoryCodes(): array;

	private static function getCategoryNameExpression(
		\Bitrix\Main\DB\SqlHelper $helper,
		string $languageId,
		string $defaultCategoryName,
	): string
	{
		$defaultName = $helper->forSql($defaultCategoryName);
		$expression = 'CASE WHEN C.CATEGORY_ID = 0 THEN \'' . $defaultName . '\'';
		foreach (self::getSystemCategoryNames($languageId) as $code => $name)
		{
			$expression .= ' WHEN DC.CODE = \'' . $helper->forSql($code) . '\' THEN \'' . $helper->forSql($name) . '\'';
		}

		return $expression . ' ELSE nullif(DC.NAME, \'\') END';
	}

	/**
	 * Repeats how \Bitrix\Crm\Category\Entity\ItemCategory renders a system category: the phrase by
	 * CODE, plural first, single where a language has no plural for that code.
	 *
	 * @return array Category name indexed by category code.
	 */
	private static function getSystemCategoryNames(string $languageId): array
	{
		$messages = self::loadCrmMessages('/bitrix/modules/crm/lib/category/entity/itemcategory.php', $languageId);

		$names = [];
		foreach (static::getSystemCategoryCodes() as $code)
		{
			$name = $messages['CRM_CATEGORY_ENTITY_ITEM_CATEGORY_NAME_' . $code . '_PLURAL'] ?? '';
			if ($name === '')
			{
				$name = $messages['CRM_CATEGORY_ENTITY_ITEM_CATEGORY_NAME_' . $code . '_SINGLE'] ?? '';
			}

			if ($name !== '')
			{
				$names[$code] = $name;
			}
		}

		return $names;
	}

	private static function loadCrmMessages(string $path, string $languageId): array
	{
		$languageFile = $_SERVER['DOCUMENT_ROOT'] . $path;
		$messages = Loc::loadLanguageFile($languageFile, $languageId);
		if ($languageId !== 'en')
		{
			$messages += Loc::loadLanguageFile($languageFile, 'en');
		}

		return $messages;
	}
}
