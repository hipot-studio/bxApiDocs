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
	 * Delete permissions of the field categories the module owns. The CRM categories are absent on
	 * purpose: the module has no delete permission of its own for them, their lifecycle is ruled by CRM.
	 *
	 * @return array<string, bool>
	 */
	public function getDeleteByCategoryPermissions(int $userId): array
	{
		$accessController = $this->accessControllerFactory->createByUserId($userId);
		if ($accessController === null)
		{
			return [];
		}

		return [
			FrontFieldCategory::PROFILE->value =>
				$accessController->check(ActionDictionary::ACTION_B2E_PROFILE_FIELDS_DELETE)
			,
			FrontFieldCategory::DYNAMIC_MEMBER->value =>
				$accessController->check(ActionDictionary::ACTION_B2E_MEMBER_DYNAMIC_FIELDS_DELETE)
			,
		];
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
