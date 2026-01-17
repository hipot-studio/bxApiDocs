<?php

namespace Bitrix\Sign\Operation\Document\Template\Onboarding;

use Bitrix\Sign\Contract\Operation;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Sign\Debug\Logger;
use Bitrix\Sign\Item\Api\Company\RegisterByClientResponse;
use Bitrix\Sign\Service\B2e\CompanyService;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\Crm\MyCompanyService;
use Bitrix\Sign\Service\Api\B2e\ProviderCodeService;
use Bitrix\Sign\Type\ProviderCode;
use Psr\Log\LoggerInterface;

class GetFirstSuitableCompany implements Operation
{
	private readonly MyCompanyService $myCompanyService;
	private readonly ProviderCodeService $providerCodeService;
	private readonly CompanyService $companyService;
	private readonly LoggerInterface $logger;

	private ?string $companyUid = null;
	private ?int $companyEntityId = null;

	public function __construct(
	)
	{
		$container = Container::instance();
		$this->myCompanyService = $container->getCrmMyCompanyService();
		$this->companyService = $container->getCompanyService();
		$this->providerCodeService = $container->getApiProviderCodeService();
		$this->logger = Logger::getInstance();
	}

	public function getCompanyUid(): ?string
	{
		return $this->companyUid;
	}

	public function getCompanyEntityId(): ?int
	{
		return $this->companyEntityId;
	}

	public function launch(): Result
	{
		return $this->findCompanySuitableForTestSigning();
	}

	private function findCompanySuitableForTestSigning(): Result
	{
		$companies = $this->myCompanyService->listWithTaxIds(checkRequisitePermissions: false);

		$companyUid = null;
		$companyEntityId = null;

		foreach ($companies as $company)
		{
			$companyEntityId = $company?->id;
			if (!$companyEntityId)
			{
				$this->logger->warning('Company entity id not found', ['company' => $company]);
				continue;
			}

			$companyTaxId = $company?->taxId;
			if (!$companyTaxId)
			{
				$this->logger->warning('Company tax id not found', ['company' => $company]);
				continue;
			}

			$companyByTaxIdResult = $this->companyService->getCompanies(
				taxIds: [$companyTaxId],
				supportedProviders: ProviderCode::getAllFormattedCodes(),
			);
			if (!$companyByTaxIdResult->isSuccess())
			{
				$this->logger->warning('Failed to get company by tax id', [
					'taxId' => $companyTaxId,
					'errors' => $companyByTaxIdResult->getErrorMessages(),
				]);
				continue;
			}

			$extractedCompanyUid = $this->extractCompanyUid($companyByTaxIdResult->companies);
			if (!$extractedCompanyUid)
			{
				$this->logger->warning('Extracted company uid not found');
				continue;
			}

			$providerCode = $this->providerCodeService->loadProviderCode($extractedCompanyUid);
			if ($providerCode)
			{
				$companyUid = $extractedCompanyUid;
				break;
			}
			else
			{
				$registerCompanyResult = $this->registerCompany($companyTaxId, $company->name);
				if (!$registerCompanyResult->isSuccess())
				{
					$this->logger->error('Failed to register company', [
						'errors' => $registerCompanyResult->getErrorMessages(),
					]);
					continue;
				}

				$companyUid = $registerCompanyResult->id;
				break;
			}
		}

		if (!$companyUid || !$companyEntityId)
		{
			return (new Result())->addError(new Error('No suitable company found'));
		}

		$this->companyUid = $companyUid;
		$this->companyEntityId = $companyEntityId;
		return new Result();
	}

	private function extractCompanyUid(array $companies): ?string
	{
		foreach ($companies as $company)
		{
			$providers = (array)($company['providers'] ?? []);
			foreach ($providers as $provider)
			{
				$providerCode = $provider['code'] ?? null;
				if ($providerCode === ProviderCode::toRepresentativeString(ProviderCode::SES_RU_EXPRESS))
				{
					return $provider['uid'] ?? null;
				}
			}
		}

		return null;
	}

	private function registerCompany(string $taxId, string $companyName): RegisterByClientResponse
	{
		return $this->companyService->register(
			taxId: $taxId,
			providerCode: ProviderCode::SES_RU_EXPRESS,
			companyName: $companyName,
		);
	}
}
