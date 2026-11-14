<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\MyCompanyRequisiteDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\MyCompanyRequisiteDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\MyCompanyRequisite\GetRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\MyCompanyRequisite\SetRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Requisite\MyCompanyRequisiteErrorMapper;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Crm\V2\Public\Command\Requisite\SetMyCompanyRequisiteCommand;
use Bitrix\Crm\V2\Public\Entity\Requisite;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Requisite\MyCompanyRequisiteProvider;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;

/**
 * `crm.{entity}.requisite.getmycompanyrequisite` and `.setmycompanyrequisite` - the choice made among
 * the requisites of the seller, of the company a CRM entity names as "my company", on the group of
 * {@see AbstractRequisitePairController}.
 *
 * The choice of the requisites of the client is a separate one of the same entity, served by
 * {@see ClientRequisite}.
 *
 * @extends AbstractRequisitePairController<Requisite\MyCompanyRequisite>
 */
#[DtoType(MyCompanyRequisiteDto::class)]
final class MyCompanyRequisite extends AbstractRequisitePairController
{
	public function getAction(GetRequest $request, EntityType $entityType): GetResponse
	{
		$result = (new MyCompanyRequisiteProvider($this->userId))->get($entityType, $request->id);

		return new GetResponse(
			$this->mapResultToDto($result, $request->id, $request->select?->getStructuredList() ?? []),
		);
	}

	public function setAction(SetRequest $request, EntityType $entityType): GetResponse
	{
		$result =
			(new SetMyCompanyRequisiteCommand(
				$entityType,
				$request->id,
				$request->getRequisiteId(),
				$request->getBankRequisiteId(),
				$this->userId,
			))
				->setScope(Scope::Rest)
				->run()
		;

		return new GetResponse($this->mapResultToDto($result, $request->id));
	}

	protected function errorMapper(): MyCompanyRequisiteErrorMapper
	{
		return new MyCompanyRequisiteErrorMapper();
	}

	protected function pairMapper(): MyCompanyRequisiteDtoMapper
	{
		return new MyCompanyRequisiteDtoMapper();
	}

	protected function extractPair(Result $result): Requisite\MyCompanyRequisite
	{
		$pair = $result->getData()[MyCompanyRequisiteProvider::DATA_MY_COMPANY_REQUISITE] ?? null;
		if (!$pair instanceof Requisite\MyCompanyRequisite)
		{
			throw new SystemException('The my company requisite scenario returned an invalid pair.');
		}

		return $pair;
	}
}
