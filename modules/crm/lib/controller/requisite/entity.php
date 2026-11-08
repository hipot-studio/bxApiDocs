<?php
namespace Bitrix\Crm\Controller\Requisite;

use Bitrix\Crm\EntityRequisite;
use Bitrix\Crm\Integration\BankDetailResolver;
use Bitrix\Crm\Integration\ClientResolver;
use Bitrix\Crm\V2\Internal\Service\Requisite\ExternalFieldSetFilter;
use Bitrix\Main\Engine\Controller;

class Entity extends Controller
{
	public static function searchAction($searchQuery, $options = [])
	{
		$presetId = $options['presetId'];

		$requisiteEntity = new EntityRequisite();
		$countryId = (int)$requisiteEntity->getCountryIdByPresetId((int)$presetId);

		$defaultResolverClass = 'ClientResolver';
		$resolverNamespace = 'Bitrix\\Crm\\Integration\\';
		$resolverClass = '';
		$typeId = '';
		$isTypeIdSet = false;
		if (
			isset($options['typeId'])
			&& is_string($options['typeId'])
			&& $options['typeId'] !== ''
		)
		{
			foreach (['ClientResolver', 'BankDetailResolver'] as $resolverClass)
			{
				$resolverClass = $resolverNamespace . $resolverClass;
				/** @var ClientResolver|BankDetailResolver $resolverClass */
				$allowedTypesMap = $resolverClass::getAllowedTypesMap();
				if (
					isset($allowedTypesMap[$countryId])
					&& is_array($allowedTypesMap[$countryId])
					&& in_array($options['typeId'], $allowedTypesMap[$countryId], true)
				)
				{
					$typeId = $options['typeId'];
					$isTypeIdSet = true;
					break;
				}
			}
		}

		if (!$isTypeIdSet)
		{
			$resolverClass = $resolverNamespace . $defaultResolverClass;
			$type = ($countryId ? $resolverClass::getPropertyTypeByCountry($countryId) : []);
			$typeId = empty($type) ? null : (string)$type['VALUE'];
		}

		$preparedSearchQuery = "";
		if ($typeId == ClientResolver::PROP_ITIN && preg_match('/[0-9]{10,12}/', $searchQuery, $matches))
		{
			$preparedSearchQuery = $matches[0];
		}
		elseif ($typeId == BankDetailResolver::PROP_BIC && preg_match('/[0-9]{9}/', $searchQuery, $matches))
		{
			$preparedSearchQuery = $matches[0];
		}
		elseif ($typeId == ClientResolver::PROP_SRO && preg_match('/[0-9]{8}/', $searchQuery, $matches))
		{
			$preparedSearchQuery = $matches[0];
		}

		if ($preparedSearchQuery == '')
		{
			return [
				'items' => []
			];
		}

		$result = (new $resolverClass)->resolveClient(
			$typeId,
			$preparedSearchQuery,
			$countryId
		);

		return [
			'items' => self::narrowFoundFieldsToPreset($result, (int)$presetId)
		];
	}

	/**
	 * The resolver answers with every legal detail its directory knows about the organization, while the
	 * requisite the answer is poured into shows only the details its preset declares. The narrowing is
	 * done here rather than inside the resolver: resolveClient() is called from elsewhere as well, and
	 * those callers are about their own data rather than about a requisite of this portal.
	 *
	 * @param array $items items of the answer, as the resolver produced them.
	 * @param int $currentPresetId the preset the form asked for the search under.
	 */
	private static function narrowFoundFieldsToPreset(array $items, int $currentPresetId): array
	{
		$filter = new ExternalFieldSetFilter();

		foreach ($items as &$item)
		{
			if (isset($item['fields']) && is_array($item['fields']))
			{
				$item['fields'] = $filter->filter($item['fields'], $currentPresetId);
			}
		}
		unset($item);

		return $items;
	}
}