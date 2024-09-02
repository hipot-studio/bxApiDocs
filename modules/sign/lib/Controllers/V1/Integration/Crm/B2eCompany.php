<?php

namespace Bitrix\Sign\Controllers\V1\Integration\Crm;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Requisite\DefaultRequisite;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Attribute;
use Bitrix\Sign\Connector;
use Bitrix\Sign\Integration\Bitrix24\B2eTariff;
use Bitrix\Sign\Item\CompanyCollection;
use Bitrix\Sign\Item\CompanyProvider;
use Bitrix\Sign\Item\Integration\Crm\MyCompanyCollection;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Item\Company;

class B2eCompany extends \Bitrix\Sign\Engine\Controller
{
	#[Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT)]
	public function listAction(): array
	{
		if (B2eTariff::instance()->isB2eRestrictedInCurrentTariff())
		{
			$this->addB2eTariffRestrictedError();

			return [];
		}

		if (!Loader::includeModule('crm'))
		{
			$this->addError(new Error('Module crm not installed'));
			return [];
		}

		$companies = $this->getCrmMyCompanies();
		$this->appendRequisites($companies);

		return [
			'showTaxId' => !$this->isTaxIdIsCompanyId(),
			'companies' => $this->getFilledRegisteredCompanies($companies)
				->sortProviders()
				->toArray(),
		];
	}

	#[Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT)]
	public function deleteAction(string $id): array
	{
		$result = Container::instance()->getApiService()
			->post('v1/b2e.company.delete', ['id' => $id])
		;

		$this->addErrorsFromResult($result);

		return [];
	}

	private function appendRequisites(MyCompanyCollection $myCompanies): void
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
			$requisite = $defaultRequisite->get();
			$company->taxId = $requisite['RQ_INN'] ?? null;
		}
	}

	private function isTaxIdIsCompanyId(): bool
	{
		return Application::getInstance()->getLicense()->getRegion() !== 'ru';
	}


	/**
	 * Get crm my companies
	 *
	 * @return MyCompanyCollection
	 */
	private function getCrmMyCompanies(): MyCompanyCollection
	{
		return Connector\Crm\MyCompany::listItems();
	}

	private function getFilledRegisteredCompanies(MyCompanyCollection $myCompanies): CompanyCollection
	{
		$registeredCompanies = $this->getRegistered($myCompanies);

		$companies = new CompanyCollection();

		foreach ($myCompanies as $myCompany)
		{
			$company = new Company(
				id: $myCompany->id,
				title: $myCompany->name,
				rqInn: $myCompany->taxId,
			);
			if (!$company->rqInn || !isset($registeredCompanies[$company->rqInn]))
			{
				$companies->add($company);
				continue;
			}

			$registeredByTaxId = $registeredCompanies[$company->rqInn] ?? [];
			if (!empty($registeredByTaxId['register_url']) && is_string($registeredByTaxId['register_url']))
			{
				$company->registerUrl = $registeredByTaxId['register_url'];
			}
			if (empty($registeredByTaxId['providers']) || !is_array($registeredByTaxId['providers']))
			{
				$companies->add($company);
				continue;
			}

			foreach ($registeredByTaxId['providers'] as $provider)
			{
				if (!empty($provider['uid']) && is_string($provider['uid'])
					&& !empty($provider['code']) && is_string($provider['code'])
				)
				{
					$company->providers[] = new CompanyProvider(
						$provider['code'],
						$provider['uid'],
						(int)($provider['date'] ?? null),
						(bool)($provider['virtual'] ?? false),
						(bool)($provider['autoRegister'] ?? false),
						is_numeric($provider['expires'] ?? null) ? (int)$provider['expires'] : null,
					);
				}
			}

			$companies->add($company);
		}

		return $companies;
	}

	private function getRegistered(MyCompanyCollection $myCompanies): array
	{
		$taxIds = $myCompanies->listTaxIds();
		if (empty($taxIds))
		{
			return [];
		}

		$result = Container::instance()->getApiService()
			->post('v1/b2e.company.get', ['taxIds' => $taxIds]);
		if ($result->isSuccess())
		{
			$data = $result->getData();
			$companies = (array)($data['companies'] ?? []);

			$map = [];
			foreach ($companies as $company)
			{
				$taxId = $company['taxId'] ?? null;
				$map[$taxId] = $company;
			}

			return $map;
		}

		$this->addErrors($result->getErrors());

		return [];
	}

	#[Attribute\ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT)]
	public function registerAction(string $taxId, string $providerCode): array
	{
		$providerData = [];
		if ($this->isTaxIdIsCompanyId())
		{
			$providerData['companyName'] = Connector\Crm\MyCompany::getById($taxId)?->name;
		}

		$result = Container::instance()->getApiService()
			->post('v1/b2e.company.registerByClient', [
				'taxId' => $taxId,
				'providerCode' => $providerCode,
				'providerData' => $providerData,
			])
		;

		$this->addErrorsFromResult($result);

		return $result->getData();
	}
}
