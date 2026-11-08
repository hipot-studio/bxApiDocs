<?php

namespace Bitrix\Crm\Service;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\ClientRecentBySection;

final class ClientRecentCategoryResolver
{
	public const CLASSIC_CATEGORY = 'crm.client.recent.classic';
	public const AUTOMATED_SOLUTION_CATEGORY_PREFIX = 'crm.client.recent.as.';

	// own legal entities need a bucket of their own: the client read path does not filter them out,
	// and reading them writes them back into the bucket it read from
	public const MY_COMPANY_CATEGORY_SUFFIX = '.my';

	public function resolve(int $parentEntityTypeId, string $legacyCategory, bool $isMyCompany = false): string
	{
		if (!Feature::enabled(ClientRecentBySection::class))
		{
			return $legacyCategory;
		}

		if (
			in_array(
				$parentEntityTypeId,
				[
					\CCrmOwnerType::Lead,
					\CCrmOwnerType::Deal,
					\CCrmOwnerType::Contact,
					\CCrmOwnerType::Company,
					\CCrmOwnerType::SmartInvoice,
				],
				true,
			)
		)
		{
			return $this->applyMyCompanyMode(self::CLASSIC_CATEGORY, $isMyCompany);
		}

		if (!\CCrmOwnerType::isPossibleDynamicTypeId($parentEntityTypeId))
		{
			return $legacyCategory;
		}

		$type = Container::getInstance()->getTypeByEntityTypeId($parentEntityTypeId);
		if ($type === null)
		{
			return $legacyCategory;
		}

		$automatedSolutionId = (int)($type->getCustomSectionId() ?? 0);
		if ($automatedSolutionId > 0)
		{
			return $this->applyMyCompanyMode(
				self::AUTOMATED_SOLUTION_CATEGORY_PREFIX . $automatedSolutionId,
				$isMyCompany,
			);
		}

		return $this->applyMyCompanyMode(self::CLASSIC_CATEGORY, $isMyCompany);
	}

	private function applyMyCompanyMode(string $category, bool $isMyCompany): string
	{
		return $isMyCompany ? $category . self::MY_COMPANY_CATEGORY_SUFFIX : $category;
	}
}
