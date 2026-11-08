<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\BankRequisiteListFilterDto;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

final class RequisiteBankRequisiteListRequest extends ListRequest
{
	#[FilterRequired(['requisiteId'])]
	#[NotEmpty]
	public ?FilterStructure $filter = null;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, BankRequisiteListFilterDto::class, $options);

		return $request;
	}
}
