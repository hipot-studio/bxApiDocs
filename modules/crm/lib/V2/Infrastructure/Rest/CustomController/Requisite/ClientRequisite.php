<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\ClientRequisiteDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\ClientRequisiteDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\ClientRequisite\GetRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\ClientRequisite\SetRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Requisite\ClientRequisiteErrorMapper;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Crm\V2\Public\Command\Requisite\SetClientRequisiteCommand;
use Bitrix\Crm\V2\Public\Entity\Requisite;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Requisite\ClientRequisiteProvider;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;

/**
 * `crm.{entity}.clientRequisite.get` and `.set` - the choice made among the requisites of the client
 * of a CRM entity, on the group of {@see AbstractRequisitePairController}.
 *
 * @extends AbstractRequisitePairController<Requisite\ClientRequisite>
 */
#[DtoType(ClientRequisiteDto::class)]
final class ClientRequisite extends AbstractRequisitePairController
{
	public function getAction(GetRequest $request, EntityType $entityType): GetResponse
	{
		$result = (new ClientRequisiteProvider($this->userId))->get($entityType, $request->id);

		return new GetResponse(
			$this->mapResultToDto($result, $request->id, $request->select?->getStructuredList() ?? []),
		);
	}

	public function setAction(SetRequest $request, EntityType $entityType): GetResponse
	{
		$result =
			(new SetClientRequisiteCommand(
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

	protected function errorMapper(): ClientRequisiteErrorMapper
	{
		return new ClientRequisiteErrorMapper();
	}

	protected function pairMapper(): ClientRequisiteDtoMapper
	{
		return new ClientRequisiteDtoMapper();
	}

	protected function extractPair(Result $result): Requisite\ClientRequisite
	{
		$pair = $result->getData()[ClientRequisiteProvider::DATA_CLIENT_REQUISITE] ?? null;
		if (!$pair instanceof Requisite\ClientRequisite)
		{
			throw new SystemException('The client requisite scenario returned an invalid pair.');
		}

		return $pair;
	}
}
