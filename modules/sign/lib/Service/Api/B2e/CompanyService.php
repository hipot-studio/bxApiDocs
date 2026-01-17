<?php

namespace Bitrix\Sign\Service\Api\B2e;

use Bitrix\Main;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Item\Api\Company\DeleteRequest;
use Bitrix\Sign\Item\Api\Company\DeleteResponse;
use Bitrix\Sign\Item\Api\Company\GetRequest;
use Bitrix\Sign\Item\Api\Company\GetResponse;
use Bitrix\Sign\Item\Api\Company\RegisterByClientRequest;
use Bitrix\Sign\Item\Api\Company\RegisterByClientResponse;
use Bitrix\Sign\Service;

class CompanyService
{
	public function __construct(
		private readonly Service\ApiService $api,
		private readonly Contract\Serializer $serializer,
	)
	{
	}

	public function registerByClient(RegisterByClientRequest $request): RegisterByClientResponse
	{
		$result = new Main\Result();

		if ($request->taxId === '')
		{
			$result->addError(new Main\Error('Request: field `taxId` is empty'));
		}

		if ($request->providerCode === '')
		{
			$result->addError(new Main\Error('Request: field `providerCode` is empty'));
		}

		if ($result->isSuccess())
		{
			$result = $this->api->post(
				'v1/b2e.company.registerByClient',
				$this->serializer->serialize($request),
			);
		}

		$data = $result->getData();
		$response = new RegisterByClientResponse(
			id: (string)($data['id'] ?? ''),
		);

		return $response->addErrors($result->getErrors());
	}

	public function get(GetRequest $request): GetResponse
	{
		$result = new Main\Result();

		if (empty($request->taxIds))
		{
			$result->addError(new Main\Error('Request: field `taxIds` is empty'));
		}

		if (empty($request->supportedProviders))
		{
			$result->addError(new Main\Error('Request: field `supportedProviders` is empty'));
		}

		if ($result->isSuccess())
		{
			$result = $this->api->post(
				'v1/b2e.company.get',
				$this->serializer->serialize($request),
			);
		}

		$data = $result->getData();
		$response = new GetResponse(
			companies: $data['companies'] ?? [],
		);

		return $response->addErrors($result->getErrors());
	}

	public function delete(DeleteRequest $request): DeleteResponse
	{
		$result = new Main\Result();

		if ($request->id === '')
		{
			$result->addError(new Main\Error('Request: field `id` is empty'));
		}

		if ($result->isSuccess())
		{
			$result = $this->api->post(
				'v1/b2e.company.delete',
				$this->serializer->serialize($request),
			);
		}

		$response = new DeleteResponse();

		return $response->addErrors($result->getErrors());
	}
}
