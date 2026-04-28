<?php

namespace Bitrix\Sign\Operation\Placeholder;

use Bitrix\Main;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Item\Api\Document\Placeholder\ListRequest;
use Bitrix\Sign\Service\Api\Document\ApiDocumentPlaceholderService;
use Bitrix\Sign\Service\Container;

final class GetPlaceholdersFromDocument implements Contract\Operation
{
	private array $placeholders = [];
	private ApiDocumentPlaceholderService $placeholderApiService;

	public function __construct(
		private readonly string $documentUid,
		?ApiDocumentPlaceholderService $placeholderApiService = null,
	)
	{
		$this->placeholderApiService = $placeholderApiService ?? Container::instance()->getApiDocumentPlaceholderService();
	}

	public function launch(): Main\Result
	{
		$listRequest = new ListRequest($this->documentUid);

		$listResponse = $this->placeholderApiService->getList($listRequest);

		if (!$listResponse->isSuccess())
		{
			return (new Main\Result())->addErrors($listResponse->getErrors());
		}

		$this->placeholders = $listResponse->list ?? [];

		return new Main\Result();
	}

	public function getPlaceholders(): array
	{
		return $this->placeholders;
	}
}
