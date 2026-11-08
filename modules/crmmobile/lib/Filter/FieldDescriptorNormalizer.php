<?php

declare(strict_types=1);

namespace Bitrix\CrmMobile\Filter;

use Bitrix\Mobile;
use Bitrix\Mobile\Filter\FieldType;

/**
 * CRM-side descriptor normalizer: the shared rules live in the core, only the pickers of the
 * CRM lists (Crm\Filter\* data providers) are named here.
 */
final class FieldDescriptorNormalizer extends Mobile\Filter\FieldDescriptorNormalizer
{
	/**
	 * The pre-apiVersion-3 selector the CRM client fields still use. For mobile it is the same
	 * control as `entity_selector` - a list of ids - and the storage layer treats the two alike
	 * (Main\UI\Filter\Options::fetchFieldValuesFromFilterSettings); they differ only in how the
	 * entity is named, by flags instead of a dialog entity list.
	 */
	private const DEST_SELECTOR = 'dest_selector';

	/**
	 * Destinations of the legacy selector, in the wording `Crm\UI\Filter\EntityHandler::internalize()`
	 * reads: the flag of the field names the entity its ids belong to.
	 *
	 * Products have no owner type of CRM to name them by: the web handler writes the same literal
	 * there (`EntityHandler::internalize()`, the `enableCrmProducts` branch).
	 */
	private const DESTINATION_BY_FLAG = [
		'enableCrmContacts' => \CCrmOwnerType::ContactName,
		'enableCrmCompanies' => \CCrmOwnerType::CompanyName,
		'enableCrmLeads' => \CCrmOwnerType::LeadName,
		'enableCrmDeals' => \CCrmOwnerType::DealName,
		'enableCrmProducts' => 'PRODUCT',
	];

	/**
	 * The client fields of the CRM lists keep their value as a map of destinations in JSON, and the
	 * filter drops a plain list of ids (EntityHandler::internalize unsets the raw key). The descriptor
	 * carries the destination so the client can write the value the filter reads.
	 */
	public function normalize(array $raw): array
	{
		$raw = self::declareNumberFields($raw);

		$descriptor = parent::normalize($raw);

		// a locked field is one the mobile has no picker for, and a destination it cannot write into is
		// no answer: `LEAD_ID` of a quote names a lead the mobile cannot choose
		if ($descriptor['locked'])
		{
			return $descriptor;
		}

		$destination = $this->resolveDestination($raw);
		if ($destination !== null)
		{
			$descriptor['destination'] = $destination;
		}

		return $descriptor;
	}

	/**
	 * The type of a field the mobile draws as a number of its own accord (`NumberFields`): the
	 * provider of the CRM list leaves such a field without a type, that is a text one, and a
	 * descriptor sent as it comes would offer "contains" over a number.
	 *
	 * The type is patched on the raw descriptor and not on the ready one, so that everything the base
	 * says about a number - the comparisons it takes away, above all - is said about this field too.
	 */
	private static function declareNumberFields(array $raw): array
	{
		if (NumberFields::declaresAsNumber((string)($raw['id'] ?? '')))
		{
			$raw['type'] = FieldType::Number->value;
		}

		return $raw;
	}

	private function resolveDestination(array $raw): ?string
	{
		if ((string)($raw['type'] ?? '') !== self::DEST_SELECTOR)
		{
			return null;
		}

		$params = ($raw['params'] ?? []);
		if (!self::isFlagSet($params, 'convertJson'))
		{
			return null;
		}

		foreach (self::DESTINATION_BY_FLAG as $flag => $destination)
		{
			if (self::isFlagSet($params, $flag))
			{
				return $destination;
			}
		}

		return null;
	}

	/**
	 * A flag of the legacy selector. The filter library has no reader of its own for this pair: the
	 * web handler of the very same fields compares with `'Y'` in place
	 * (`Crm\UI\Filter\EntityHandler::internalize()`), so the comparison is written down once here
	 * instead of standing at every flag.
	 */
	private static function isFlagSet(array $params, string $flag): bool
	{
		return ($params[$flag] ?? 'N') === 'Y';
	}

	protected function mapType(string $rawType): FieldType
	{
		return $rawType === self::DEST_SELECTOR
			? FieldType::EntitySelector
			: parent::mapType($rawType);
	}

	protected function getEntityMap(): array
	{
		// `structure-node` (departments) and `meta-user` stand before `user` in the CRM dialogs and
		// are deliberately absent: the mobile picker serves one entity, and users are the one of
		// the three every such field is really about.
		return [
			'user' => 'user',
			'contact' => 'crm-contact',
			'company' => 'crm-company',
			'product' => 'product',
		];
	}

	protected function resolveEntity(array $raw): ?string
	{
		return parent::resolveEntity($raw) ?? $this->resolveByLegacySelectorFlags($raw);
	}

	/**
	 * The selector of the previous generation names its restrictions by flags of its own, the way it
	 * names its entities: `onlyMyCompanies` of a my-company requisites field is the same narrowing the
	 * modern dialog writes as an option of the entity, and the search provider reads it under the
	 * name the dialog uses.
	 */
	protected function getEntityOptions(array $raw, string $entity): array
	{
		$options = parent::getEntityOptions($raw, $entity);

		if ($entity === 'crm-company' && self::isFlagSet($raw['params'] ?? [], 'onlyMyCompanies'))
		{
			$options['enableMyCompanyOnly'] = true;
		}

		return $options;
	}

	private function resolveByLegacySelectorFlags(array $raw): ?string
	{
		$params = $raw['params'] ?? [];

		if (self::isFlagSet($params, 'enableCrmContacts'))
		{
			return 'crm-contact';
		}

		if (self::isFlagSet($params, 'enableCrmCompanies'))
		{
			return 'crm-company';
		}

		if (self::isFlagSet($params, 'enableUsers'))
		{
			return 'user';
		}

		return null;
	}
}
