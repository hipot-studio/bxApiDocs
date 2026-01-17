<?php

namespace Bitrix\Sign\Service\B2e;

use Bitrix\Sign\Item\Api\Company\DeleteRequest;
use Bitrix\Sign\Item\Api\Company\DeleteResponse;
use Bitrix\Sign\Item\Api\Company\GetResponse;
use Bitrix\Sign\Item\Api\Company\RegisterByClientRequest;
use Bitrix\Sign\Item\Api\Company\RegisterByClientResponse;
use Bitrix\Sign\Service\Api;
use Bitrix\Sign\Type\ProviderCode;

class CompanyService
{
	public function __construct(
		private readonly Api\B2e\CompanyService $apiCompanyService,
	)
	{
	}

	public function register(
		string $taxId,
		string $providerCode,
		string $companyName = '',
		string $externalProviderId = '',
	): RegisterByClientResponse
	{
		$providerCode = ProviderCode::isNotRepresentativeString($providerCode)
			? ProviderCode::toRepresentativeString($providerCode)
			: $providerCode
		;
		$request = new RegisterByClientRequest(
			taxId: $taxId,
			providerCode: $providerCode,
			companyName: $companyName,
			externalProviderId: $externalProviderId,
		);

		return $this->apiCompanyService->registerByClient($request);
	}

	/**
	 * @param string[] $taxIds
	 * @param bool $useProvidersWhereSignerSignFirst
	 * @param string[] $supportedProviders
	 * @return GetResponse
	 */
	public function getCompanies(array $taxIds, array $supportedProviders, bool $useProvidersWhereSignerSignFirst = false): GetResponse
	{
		$request = new \Bitrix\Sign\Item\Api\Company\GetRequest(
			taxIds: $taxIds,
			supportedProviders: $supportedProviders,
			useProvidersWhereSignerSignFirst: $useProvidersWhereSignerSignFirst,
		);

		return $this->apiCompanyService->get($request);
	}

	public function delete(string $id): DeleteResponse
	{
		$request = new DeleteRequest(id: $id);

		return $this->apiCompanyService->delete($request);
	}
}
