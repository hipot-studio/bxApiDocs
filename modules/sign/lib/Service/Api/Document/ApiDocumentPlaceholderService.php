<?php

namespace Bitrix\Sign\Service\Api\Document;

use Bitrix\Sign\Item;
use Bitrix\Sign\Service;

class ApiDocumentPlaceholderService
{
	private Service\ApiService $api;

	public function __construct(
		Service\ApiService $api,
	)
	{
		$this->api = $api;
	}

	public function getList(Item\Api\Document\Placeholder\ListRequest $request): Item\Api\Document\Placeholder\ListResponse
	{
		$result = $this->api->get(
			"v1/document.placeholder.list/$request->documentUid/",
		);

		$data = $result->getData();

		$response = new Item\Api\Document\Placeholder\ListResponse(
			$data ?? []
		);

		$response->addErrors($result->getErrors());

		return $response;
	}
}