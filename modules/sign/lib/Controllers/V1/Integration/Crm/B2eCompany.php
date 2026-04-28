<?php

namespace Bitrix\Sign\Controllers\V1\Integration\Crm;

use Bitrix\Main\Context;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Uri;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Attribute\Access\LogicOr;
use Bitrix\Sign\Attribute\ActionAccess;
use Bitrix\Sign\Engine\Controller;
use Bitrix\Sign\Integration\Bitrix24\B2eTariff;
use Bitrix\Sign\Item\CompanyCollection;
use Bitrix\Sign\Item\CompanyProvider;
use Bitrix\Sign\Item\Integration\Crm\MyCompanyCollection;
use Bitrix\Sign\Operation\GetRegisteredCompanies;
use Bitrix\Sign\Item\Company;
use Bitrix\Sign\Type\Document\InitiatedByType;

class B2eCompany extends Controller
{
	#[LogicOr(
		new ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
		new ActionAccess(ActionDictionary::ACTION_B2E_TEMPLATE_EDIT),
	)]
	public function listAction(
		?string $forDocumentInitiatedByType = null,
	): array
	{
		$forDocumentInitiatedByType ??= InitiatedByType::COMPANY->value;
		$initiatedByType = InitiatedByType::tryFrom($forDocumentInitiatedByType);
		if ($initiatedByType === null)
		{
			$this->addError(new Error('Incorrect document initiated by type'));

			return [];
		}

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

		$myCompanyService = $this->container->getCrmMyCompanyService();
		$myCompanies = $myCompanyService->listWithTaxIds(checkRequisitePermissions: false);

		$companies = $this->getVisibleFilledRegisteredCompanies($myCompanies, $initiatedByType);

		$activeCompanyUids = [];
		/** @var Company $company */
		foreach ($companies as $company)
		{
			foreach ($company->providers as $provider)
			{
				$activeCompanyUids[] = $provider->uid;
			}
		}

		$lastProviders = $this->container
			->getDocumentRepository()
			->getLastCompanyProvidersByUser(
				(int)CurrentUser::get()->getId(),
				$activeCompanyUids,
			)
		;

		// sort providers
		$companies = $companies->sortProviders(
			function(CompanyProvider $a, CompanyProvider $b) use ($lastProviders) {
				$dateA = $lastProviders->getLastUsedByUid($a->uid)?->dateLastUsed ?? null;
				$dateB = $lastProviders->getLastUsedByUid($b->uid)?->dateLastUsed ?? null;

				$tsForCompareA = max($dateA?->getTimestamp() ?? 0, $a->timestamp);
				$tsForCompareB = max($dateB?->getTimestamp() ?? 0, $b->timestamp);

				return $tsForCompareB <=> $tsForCompareA;
			},
		);

		// sort companies
		$companies = $companies->getSorted(
			function(Company $a, Company $b) use ($lastProviders) {
				$dateA = $lastProviders->getLastUsedByCompanyId($a->id)?->dateLastUsed ?? null;
				$dateB = $lastProviders->getLastUsedByCompanyId($b->id)?->dateLastUsed ?? null;

				$tsForCompareA = $dateA?->getTimestamp() ?? 0;
				$tsForCompareB = $dateB?->getTimestamp() ?? 0;

				return $tsForCompareB <=> $tsForCompareA;
			}
		);

		return [
			'showTaxId' => !$myCompanyService->isTaxIdIsCompanyId(),
			'companies' => $companies->toArray(),
		];
	}

	#[LogicOr(
		new ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
		new ActionAccess(ActionDictionary::ACTION_B2E_TEMPLATE_EDIT),
	)]
	public function deleteAction(string $id): array
	{
		$response = $this->container->getCompanyService()->delete($id);

		$this->addErrors($response->getErrors());

		return [];
	}

	/**
	 * Get registered companies with visible providers.
	 * Hidden providers are excluded.
	 *
	 * @param MyCompanyCollection $myCompanies
	 * @param InitiatedByType $forDocumentInitiatedByType
	 * @return CompanyCollection
	 */
	private function getVisibleFilledRegisteredCompanies(
		MyCompanyCollection $myCompanies,
		InitiatedByType $forDocumentInitiatedByType = InitiatedByType::COMPANY,
	): CompanyCollection
	{
		$providerVisibilityService = $this->container->getProviderVisibilityService();

		$registeredCompaniesOperation = new GetRegisteredCompanies(
			myCompanies: $myCompanies,
			forDocumentInitiatedByType: $forDocumentInitiatedByType,
		);
		$registeredCompaniesOperationResult = $registeredCompaniesOperation->launch();
		if (!$registeredCompaniesOperationResult->isSuccess())
		{
			$this->addErrors($registeredCompaniesOperationResult->getErrors());

			return new CompanyCollection();
		}
		$registeredCompanies = $registeredCompaniesOperation->getResultData();

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
				$contextLang = Context::getCurrent()->getLanguage();
				if ($contextLang)
				{
					$company->registerUrl = (new Uri($company->registerUrl))
						->addParams(['lang' => Context::getCurrent()->getLanguage()])
						->getUri()
					;
				}
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
					if ($providerVisibilityService->isProviderHidden($provider['code']))
					{
						continue;
					}

					$company->providers[] = new CompanyProvider(
						$provider['code'],
						$provider['uid'],
						(int)($provider['date'] ?? null),
						(bool)($provider['virtual'] ?? false),
						(bool)($provider['autoRegister'] ?? false),
						(string)($provider['name'] ?? ''),
						(string)($provider['description'] ?? ''),
						(string)($provider['iconUrl'] ?? ''),
						is_numeric($provider['expires'] ?? null) ? (int)$provider['expires'] : null,
						(string)($provider['externalProviderId'] ?? ''),
					);
				}
			}

			$companies->add($company);
		}

		return $companies;
	}

	#[LogicOr(
		new ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_EDIT),
		new ActionAccess(ActionDictionary::ACTION_B2E_TEMPLATE_EDIT),
	)]
	public function registerAction(
		string $taxId,
		string $providerCode,
		int $companyId,
		string $externalProviderId = '',
	): array
	{
		$companyName = $this->container->getCrmMyCompanyService()->getCompanyName($companyId);

		if ($companyName === null)
		{
			$this->addError(new Error('Company not found'));

			return [];
		}

		$response = $this->container->getCompanyService()->register(
			taxId: $taxId,
			providerCode: $providerCode,
			companyName: $companyName,
			externalProviderId: $externalProviderId,
		);

		$this->addErrors($response->getErrors());

		return [
			'id' => $response->id,
		];
	}
}
