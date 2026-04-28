<?php

namespace Bitrix\Sign\Service\Document;

use Bitrix\Sign\Access\AccessController;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Type\Field\FrontFieldCategory;

class FieldAccessService
{
	public function __construct(
		private readonly AccessController\AccessControllerFactory $accessControllerFactory,
	)
	{
	}

	/**
	 * @param array{fields: array, options: array} $crmFieldsData
	 *
	 * @return array<string, bool>
	 */
	public function getAddByCategoryPermissions(array $crmFieldsData, int $userId): array
	{
		$accessController = $this->accessControllerFactory->createByUserId($userId);
		if ($accessController === null)
		{
			return [];
		}

		return [
				FrontFieldCategory::PROFILE->value =>
					$accessController->check(ActionDictionary::ACTION_B2E_PROFILE_FIELDS_ADD)
				,
				FrontFieldCategory::DYNAMIC_MEMBER->value =>
					$accessController->check(ActionDictionary::ACTION_B2E_TEMPLATE_ADD)
				,
			] + $this->getOtherCategoriesAddPermissions($crmFieldsData);
	}

	/**
	 * @param array{fields: array, options: array} $crmFieldsData
	 *
	 * @return array<string, bool>
	 */
	private function getOtherCategoriesAddPermissions(array $crmFieldsData): array
	{
		$otherCategories = array_keys((array)($crmFieldsData['fields'] ?? []));
		$otherCategoriesAddPermission = (bool)($crmFieldsData['options']['permissions']['userField']['add'] ?? false);

		$arrayPermission = array_fill(0, count($otherCategories), $otherCategoriesAddPermission);

		return array_combine($otherCategories, $arrayPermission);
	}
}
