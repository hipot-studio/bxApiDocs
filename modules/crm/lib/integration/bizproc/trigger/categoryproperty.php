<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\Trigger;

use Bitrix\Bizproc\FieldType;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

/**
 * Single source of truth for the category property shared by CRM node-workflow
 * entity triggers: edit/create (via CrmEntityTriggerTrait) and field-changed
 * (CBPCrmEntityFieldChangedTrigger). Keeps the category-parity contract in one
 * place: field map, applicability, options and entity-type/category resolution.
 */
final class CategoryProperty
{
	public const FIELD_NAME = 'categoryId';

	public static function buildPropertyMap(string $document): array
	{
		if (!self::shouldRenderField($document))
		{
			return [];
		}

		return [
			self::FIELD_NAME => [
				// Invariant: no lang file sits next to this helper, so every consumer activity must keep
				// BP_CRM_ENTITY_CREATE_TRIGGER_CATEGORY_ID in its own lang/ru (includeActivityFile preloads it before render).
				'Name' => Loc::getMessage('BP_CRM_ENTITY_CREATE_TRIGGER_CATEGORY_ID'),
				'FieldName' => self::FIELD_NAME,
				'Type' => FieldType::SELECT,
				'Multiple' => self::supportsMultipleCategories(),
				'Required' => false,
				'AllowSelection' => false,
				'Options' => self::getOptions($document),
			],
		];
	}

	public static function supportsMultipleCategories(): bool
	{
		return method_exists(\Bitrix\Bizproc\Public\Activity\Configurator::class, 'getSections');
	}

	/** @return int[] */
	public static function normalizeIds(mixed $categoryIds): array
	{
		$categoryIds = is_array($categoryIds) ? $categoryIds : [$categoryIds];
		$ids = [];
		foreach ($categoryIds as $categoryId)
		{
			if ($categoryId === '' || $categoryId === null)
			{
				continue;
			}

			$ids[] = (int)$categoryId;
		}

		return array_values(array_unique($ids));
	}

	public static function getOptions(string $document): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		$entityTypeId = self::resolveEntityTypeId($document);
		if ($entityTypeId <= 0)
		{
			return [];
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);
		if (!$factory || !$factory->isCategoriesEnabled() || !$factory->isStagesEnabled())
		{
			return [];
		}

		$options = [];
		foreach ($factory->getCategories() as $category)
		{
			$options[$category->getId()] = $category->getName();
		}

		return $options;
	}

	public static function normalizeId(mixed $categoryId): ?int
	{
		if (is_array($categoryId))
		{
			$categoryId = reset($categoryId);
		}

		if ($categoryId === '' || $categoryId === null)
		{
			return null;
		}

		return (int)$categoryId;
	}

	public static function resolveEntityTypeId(mixed $document): int
	{
		if (is_array($document) && count($document) === 3)
		{
			[$entityTypeId] = \CCrmBizProcHelper::resolveEntityId($document);

			return (int)$entityTypeId;
		}

		if (!is_string($document) || $document === '')
		{
			return 0;
		}

		$documentType = self::resolveDocumentTypeFromDocument($document);
		if (!$documentType)
		{
			return 0;
		}

		return (int)\CCrmOwnerType::ResolveID((string)$documentType[2]);
	}

	public static function resolveDocumentTypeFromDocument(?string $document): ?array
	{
		if (!$document || !Loader::includeModule('crm'))
		{
			return null;
		}

		if (str_contains($document, '@'))
		{
			return explode('@', $document);
		}

		return \CCrmBizProcHelper::resolveDocumentType(\CCrmOwnerType::resolveID($document));
	}

	public static function resolveDocumentTypeName(string $document): string
	{
		$complexDocumentType = self::resolveDocumentTypeFromDocument($document);

		return $complexDocumentType ? (string)$complexDocumentType[2] : $document;
	}

	public static function getPresetEntityNames(): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		return [
			\CCrmOwnerType::DealName,
			\CCrmOwnerType::CompanyName,
			\CCrmOwnerType::OrderName,
			\CCrmOwnerType::ContactName,
			\CCrmOwnerType::LeadName,
			\CCrmOwnerType::QuoteName,
			\CCrmOwnerType::SmartInvoiceName,
		];
	}

	private static function shouldRenderField(string $document): bool
	{
		if ($document === '')
		{
			return true;
		}

		if (!in_array(self::resolveDocumentTypeName($document), self::getPresetEntityNames(), true))
		{
			return true;
		}

		$entityTypeId = self::resolveEntityTypeId($document);
		if ($entityTypeId <= 0)
		{
			return false;
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);

		return $factory && $factory->isCategoriesEnabled() && $factory->isStagesEnabled();
	}
}
