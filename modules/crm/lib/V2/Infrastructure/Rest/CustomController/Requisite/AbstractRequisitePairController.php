<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\AbstractRequisitePairDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\AbstractRequisitePairDto;
use Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Requisite\AbstractRequisitePairErrorMapper;
use Bitrix\Main\Result;

/**
 * A group of two methods over a requisite pair chosen in a CRM entity - one read and one write - on
 * one implementation. Whose pair is served is the descendant's to say: it brings its own two names,
 * the scenario behind each of them, the contract it publishes the pair under and its own refusals.
 *
 * The type of the owner is an invariant of the route: it is set as an integer in
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\CustomController\SchemaProvider} and reaches an action by
 * auto-wiring ({@see AbstractController::getEntityType()}). Neither the query string nor the body is
 * ever read for it, so a route of a deal writes the choice of a deal and of nothing else.
 *
 * The identifier of a request is the one of the entity that owns the choice: the stored pair has no
 * identifier of its own.
 *
 * Nothing of the subject matter is decided here. The rights on the owner, the reach of whatever the
 * pair is chosen among and what the pair may point at are the scenario's; this class turns the answer
 * of a scenario - successful or not - into the standard answer of REST v3, while a descendant turns a
 * request into the arguments of its own scenario and calls it. The user whose rights are checked is
 * the one the infrastructure of the controller resolved, and a user identifier from the body is not
 * read anywhere in these groups.
 *
 * ### Why the write answers with the pair
 *
 * The answer of a write is the pair after it: the write clears the bank requisite the client left out,
 * and a client told only that it succeeded would have to read the pair again to learn it.
 *
 * ### Why both actions stay in the descendant
 *
 * An action of REST v3 is built out of the signature of a public method ending in `Action`
 * ({@see \Bitrix\Rest\V3\Schema\ControllerDataManager}), and that signature names the request classes
 * of the group. An action declared here would name a shared request and take the form of a group out
 * of the published description, so only what stands between a scenario and its answer is shared.
 *
 * @template TEntity of object the value class of the group
 */
abstract class AbstractRequisitePairController extends AbstractController
{
	/** The refusals of the group, as the answers REST v3 gives for them. */
	abstract protected function errorMapper(): AbstractRequisitePairErrorMapper;

	/** @return AbstractRequisitePairDtoMapper<TEntity> the contract of the group, over its pair */
	abstract protected function pairMapper(): AbstractRequisitePairDtoMapper;

	/**
	 * The pair a successful scenario of the group answered with, under the key of its own data.
	 *
	 * @return TEntity
	 */
	abstract protected function extractPair(Result $result): object;

	/**
	 * @param array $fields the fields the client selected; an empty list is the whole pair.
	 */
	protected function mapResultToDto(Result $result, int $ownerId, array $fields = []): AbstractRequisitePairDto
	{
		$this->errorMapper()->throwOnFailure($result, $ownerId);

		$pair = $this->extractPair($result);

		/** @var AbstractRequisitePairDto $dto */
		$dto = $this->pairMapper()->mapOne($pair, $fields);

		return $dto;
	}
}
