<?php

namespace Bitrix\Sign\Operation;

use Bitrix\Main;
use Bitrix\Sign\Contract\Operation as OperationContract;
use Bitrix\Sign\Item\Integration\Crm\MyCompanyCollection;
use Bitrix\Sign\Service\B2e\CompanyService;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\Document\InitiatedByType;
use Bitrix\Sign\Type\ProviderCode;

class GetRegisteredCompanies implements OperationContract
{
	private array $resultData = [];

	private readonly CompanyService $companyService;
	public function __construct(
		private readonly MyCompanyCollection $myCompanies,
		private readonly InitiatedByType $forDocumentInitiatedByType,
	)
	{
		$this->companyService = Container::instance()->getCompanyService();
	}

	public function launch(): Main\Result
	{
		$taxIds = $this->myCompanies->listTaxIds();;
		if (empty($taxIds))
		{
			return new Main\Result();
		}

		$result = $this->companyService->getCompanies(
			taxIds: $taxIds,
			supportedProviders: ProviderCode::getAllFormattedCodes(),
			useProvidersWhereSignerSignFirst: $this->forDocumentInitiatedByType->isEmployee(),
		);

		if (!$result->isSuccess())
		{
			return (new Main\Result())->addErrors($result->getErrors());
		}

		$companies = $result->companies;

		$map = [];
		foreach ($companies as $company)
		{
			$taxId = $company['taxId'] ?? null;
			$map[$taxId] = $company;
		}

		$this->resultData = $map;

		return new Main\Result();
	}

	public function getResultData(): array
	{
		return $this->resultData;
	}
}