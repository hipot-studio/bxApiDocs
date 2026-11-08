<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
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
 * `crm.{entity}.clientRequisite.get` and `.set` - the whole group, on one implementation.
 *
 * The type of the owner is an invariant of the route: it is set as an integer in
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\CustomController\SchemaProvider} and reaches an action by
 * auto-wiring ({@see AbstractController::getEntityType()}). Neither the query string nor the body is
 * ever read for it, so `crm.deal.clientRequisite.set` writes the choice of a deal and of nothing else.
 *
 * The identifier of a request is the one of the entity that owns the choice: the stored pair has no
 * identifier of its own.
 *
 * Nothing of the subject matter is decided here. The rights on the owner, the reach of its client and
 * what the pair may point at are the scenario's; this class turns a request into its arguments, calls
 * it, and turns its answer - successful or not - into the standard answer of REST v3. The user whose
 * rights are checked is the one the infrastructure of the controller resolved, and a user identifier
 * from the body is not read anywhere in this group.
 */
#[DtoType(ClientRequisiteDto::class)]
final class ClientRequisite extends AbstractController
{
	public function getAction(GetRequest $request, EntityType $entityType): GetResponse
	{
		$result = (new ClientRequisiteProvider($this->userId))->get($entityType, $request->id);

		return new GetResponse(
			$this->mapResultToDto($result, $request->id, $request->select?->getStructuredList() ?? []),
		);
	}

	/**
	 * The answer is the pair after the write: the write clears the bank requisite the client left out,
	 * and a client told only that it succeeded would have to read the pair again to learn it.
	 */
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

	private function mapResultToDto(Result $result, int $ownerId, array $fields = []): ClientRequisiteDto
	{
		(new ClientRequisiteErrorMapper())->throwOnFailure($result, $ownerId);

		$pair = $result->getData()[ClientRequisiteProvider::DATA_CLIENT_REQUISITE] ?? null;
		if (!$pair instanceof Requisite\ClientRequisite)
		{
			throw new SystemException('The client requisite scenario returned an invalid pair.');
		}

		/** @var ClientRequisiteDto $dto */
		$dto = (new ClientRequisiteDtoMapper())->mapOne($pair, $fields);

		return $dto;
	}
}
