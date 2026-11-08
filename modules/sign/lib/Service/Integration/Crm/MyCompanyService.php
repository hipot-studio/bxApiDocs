<?php

namespace Bitrix\Sign\Service\Integration\Crm;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Requisite\DefaultRequisite;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Sign\Connector\Crm\MyCompany;
use Bitrix\Sign\Item\Integration\Crm\MyCompanyCollection;

final class MyCompanyService
{
	/**
	 * @param ?int $limit how many companies to answer with at most; null asks for all of them. The tax
	 *        id of every company answered is read by a request of its own, so a caller that only
	 *        offers a list to choose from is better off with a limit.
	 */
	public function listWithTaxIds(
		array $inIds = [],
		bool $checkRequisitePermissions = true,
		?int $limit = null,
	): MyCompanyCollection
	{
		$companies = MyCompany::listItems(itemsAmount: $limit, inIds: $inIds);
		$this->appendRequisites($companies, $checkRequisitePermissions);

		return $companies;
	}

	private function appendRequisites(MyCompanyCollection $myCompanies, bool $checkRequisitePermissions = true): void
	{
		if (!$myCompanies->count())
		{
			return;
		}

		if ($this->isTaxIdIsCompanyId())
		{
			foreach ($myCompanies as $company)
			{
				$company->taxId = (string)$company->id;
			}

			return;
		}

		foreach ($myCompanies as $company)
		{
			$defaultRequisite = new DefaultRequisite(
				new ItemIdentifier(\CCrmOwnerType::Company, $company->id)
			);
			$defaultRequisite->setCheckPermissions($checkRequisitePermissions);
			$requisite = $defaultRequisite->get();
			$company->taxId = $requisite['RQ_INN'] ?? null;
		}
	}

	public function isTaxIdIsCompanyId(): bool
	{
		return Application::getInstance()->getLicense()->getRegion() !== 'ru';
	}

	public function getFirstCompanyWithTaxId(bool $checkRequisitePermissions = true): ?\Bitrix\Sign\Item\Integration\Crm\MyCompany
	{
		$companies = $this->listWithTaxIds(checkRequisitePermissions: $checkRequisitePermissions);
		foreach ($companies as $company)
		{
			if (!empty($company->taxId))
			{
				return $company;
			}
		}

		return null;
	}

	public function getCompanyName(int $companyId): ?string
	{
		return MyCompany::getById($companyId)?->name;
	}
}