<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\OwnerRequisiteDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\OwnerRequisiteRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\AbstractOwnerRequisiteDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\Owner\AddOwnerRequisiteRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\Owner\DeleteOwnerRequisiteRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\Owner\GetOwnerRequisiteRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\Owner\ListOwnerRequisiteRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\Owner\UpdateOwnerRequisiteRequest;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Crm\V2\Public\Command\Requisite\AddRequisiteCommand;
use Bitrix\Crm\V2\Public\Command\Requisite\DeleteRequisiteCommand;
use Bitrix\Crm\V2\Public\Command\Requisite\UpdateRequisiteCommand;
use Bitrix\Crm\V2\Public\Entity\Requisite\RequisiteError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Crm\V2\Public\Provider\Requisite\Exception\NotFoundException;
use Bitrix\Crm\V2\Public\Provider\Requisite\RequisiteProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Group\ValidationGroup;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\Validation\FieldEditableValidator;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Validation\DtoValidationException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\AddResponse;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;

/**
 * `crm.contact.requisite.*` and `crm.company.requisite.*` - the five methods over the requisites of one
 * owner, on one implementation.
 *
 * The type of the owner is an invariant of the route: it is set as an integer in
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\CustomController\SchemaProvider} and reaches an action by
 * auto-wiring ({@see AbstractController::getEntityType()}). Neither the query string nor the body is ever
 * read for it, so `crm.contact.requisite.add` writes the requisites of a contact and of nothing else. It
 * also decides the name the owner is published under - `contactId` or `companyId`
 * ({@see AbstractOwnerRequisiteDto::ownerFieldName()}), which is the one name that differs between the
 * two contracts of the group. Every action states it to the scenario it runs - a read to the provider, a
 * change and a deletion to their command - and none of them checks it here: the scenario reads the row
 * that answers the question anyway, and a check of it here would read that row a second time.
 *
 * Nothing of the subject matter is decided here. The rights, the composition a preset allows, the length
 * of a value and the existence of the owner belong to the scenarios of `V2\Public`, and this class does
 * three things: it turns a request into the arguments of one scenario, it runs it, and it turns the answer
 * - successful or not - into the standard answer of REST v3.
 *
 * ### What a refusal becomes
 *
 * | Refusal of the domain | Answer |
 * |---|---|
 * | {@see RequisiteError::OWNER_NOT_FOUND}, {@see RequisiteError::REQUISITE_NOT_FOUND} | {@see EntityNotFoundException} |
 * | a refusal naming a field - a preset the portal does not offer among them | {@see RequestValidationException} addressed at that field |
 * | {@see RequisiteError::REQUISITE_OPERATION_FAILED} | a system error of REST v3, logged with what the write addressed |
 *
 * Absence is one answer for three causes - gone, hidden by the permissions of the owner, or belonging to
 * another owner than the route names - and the domain collapses them before this class sees them. Every
 * refusal a client can act on leaves as an exception the framework does not log; the one failure that is
 * nobody's mistake is logged, and the client is told nothing of its text.
 */
final class OwnerRequisite extends AbstractController
{
	/**
	 * Where a refusal of the domain names the field it is about: the key of
	 * {@see Error::getCustomData()} the published enumeration of refusals states
	 * ({@see RequisiteError}).
	 */
	private const REFUSED_FIELD = 'field';

	/** The refusals that say the object the call addressed is not one this user gets. */
	private const ABSENCE_CODES = [
		RequisiteError::OWNER_NOT_FOUND->value,
		RequisiteError::REQUISITE_NOT_FOUND->value,
	];

	public function addAction(AddOwnerRequisiteRequest $request, EntityType $entityType): AddResponse
	{
		$dto = $request->fields->convertToDto((RequiredGroup::Add)->value);
		$requisite = (new OwnerRequisiteDtoMapper($entityType))->getRequisiteByDto($dto);
		$this->assertCategoryOwnerAllowed(new ItemId($entityType, (int)$requisite->getOwnerId()));

		$result =
			(new AddRequisiteCommand($requisite, $this->userId))
				->setScope(Scope::Rest)
				->run()
		;

		if (!$result->isSuccess())
		{
			throw self::refusalOf(
				$result,
				(int)$requisite->getOwnerId(),
				self::writeAddress($entityType, $requisite->getOwnerId(), $requisite->getPresetId(), null),
			);
		}

		// A successful command fills the requisite it was given, so the identifier of the new record is
		// already here and a read after write would buy nothing.
		return new AddResponse((int)$requisite->getId());
	}

	/**
	 * An empty `fields` is carried to the scenario rather than short-circuited here: it is the only thing
	 * that can tell whether the requisite exists and whether the caller may change it, and answering a
	 * changing method by the read right alone would be wrong.
	 */
	public function updateAction(UpdateOwnerRequisiteRequest $request, EntityType $entityType): UpdateResponse
	{
		$this->assertRequisiteCategoryAllowed($request->id);
		$dto = $request->fields->convertToDto((RequiredGroup::Update)->value);
		self::refuseOwnerOfAnUpdate($dto, AbstractOwnerRequisiteDto::ownerFieldName($entityType));

		$changes = (new OwnerRequisiteDtoMapper($entityType))->getChangesByDto($dto);

		$result =
			(new UpdateRequisiteCommand($entityType, $request->id, $changes, $this->userId))
				->setScope(Scope::Rest)
				->run()
		;

		if (!$result->isSuccess())
		{
			throw self::refusalOf(
				$result,
				$request->id,
				self::writeAddress($entityType, null, $changes->getPresetId(), $request->id),
			);
		}

		return new UpdateResponse();
	}

	public function getAction(GetOwnerRequisiteRequest $request, EntityType $entityType): GetResponse
	{
		$this->assertRequisiteCategoryAllowed($request->id);
		$requestMapper = new OwnerRequisiteRequestMapper($entityType);
		$selectedFields = $requestMapper->mapSelect($request);

		try
		{
			$requisite = (new RequisiteProvider($this->userId))->getById(
				$entityType->getId(),
				$request->id,
				$requestMapper->mapSelectedFields($selectedFields),
				$requestMapper->mapSelectedRelations($selectedFields),
			);
		}
		catch (NotFoundException)
		{
			throw new EntityNotFoundException($request->id);
		}

		return new GetResponse(
			(new OwnerRequisiteDtoMapper($entityType))
				->getDtoByRequisite($requisite, $request->getDtoClass(), $selectedFields),
		);
	}

	public function deleteAction(DeleteOwnerRequisiteRequest $request, EntityType $entityType): DeleteResponse
	{
		$this->assertRequisiteCategoryAllowed($request->id);
		$result =
			(new DeleteRequisiteCommand($entityType, $request->id, $this->userId))
				->setScope(Scope::Rest)
				->run()
		;

		if (!$result->isSuccess())
		{
			throw self::refusalOf(
				$result,
				$request->id,
				self::writeAddress($entityType, null, null, $request->id),
			);
		}

		return new DeleteResponse();
	}

	/**
	 * A page of the requisites of the owner the filter names.
	 *
	 * The window is settled before the read is reached: a page no read can be taken through is a refusal
	 * of the request, and asking the storage first would answer it with a failure of the portal instead.
	 */
	public function listAction(ListOwnerRequisiteRequest $request, EntityType $entityType): ListResponse
	{
		$requestMapper = new OwnerRequisiteRequestMapper($entityType);
		$selectedFields = $requestMapper->mapSelect($request);
		$ownerId = $requestMapper->mapOwnerId($request);
		$this->assertCategoryOwnerAllowed(new ItemId($entityType, $ownerId));
		$pager = $requestMapper->mapPager($request->pagination);

		try
		{
			$requisites = (new RequisiteProvider($this->userId))->getListByOwner(
				$entityType->getId(),
				$ownerId,
				$requestMapper->mapOrder($request->order),
				$pager->getLimit(),
				$pager->getOffset(),
				$requestMapper->mapSelectedFields($selectedFields),
				$requestMapper->mapSelectedRelations($selectedFields),
			);
		}
		catch (NotFoundException)
		{
			throw new EntityNotFoundException($ownerId);
		}

		return new ListResponse(
			(new OwnerRequisiteDtoMapper($entityType))
				->getDtoCollectionByRequisites($requisites, $request->getDtoClass(), $selectedFields),
		);
	}

	private function assertRequisiteCategoryAllowed(int $requisiteId): void
	{
		$result = (new RequisiteProvider($this->userId))->resolveRequisiteOwner($requisiteId, $this->userId);
		$owner = $result->getData()['owner'] ?? null;
		if (!$result->isSuccess() || !$owner instanceof ItemId)
		{
			throw new EntityNotFoundException($requisiteId);
		}

		$this->assertCategoryOwnerAllowed($owner);
	}

	/**
	 * Refuses a change that carries the owner: a requisite does not move between owners, and a field a
	 * client sent is never dropped in silence.
	 *
	 * The framework does not refuse it on its own - a field declared required in any group never reaches
	 * the check of what may be written ({@see \Bitrix\Rest\V3\Dto\DtoValidatorHelper::validate()}), and
	 * the owner is required in a creation - so the refusal is asked of the very validator that was
	 * skipped, by the same means the controller of pipelines asks for it
	 * ({@see \Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Category\Category}). The answer a client
	 * gets for this field is then the one every other field outside a write gives it.
	 *
	 * Whether the client sent the field is left to the validator, which answers a field it did not send
	 * with success. Asking `isset()` of the DTO instead would answer no every time: the contract of a
	 * route of this group is generated, so every field of it is a dynamic one, and {@see Dto} declares
	 * no `__isset()` for those.
	 *
	 * @throws DtoValidationException
	 */
	private static function refuseOwnerOfAnUpdate(Dto $dto, string $ownerFieldName): void
	{
		$field = $dto->getFields()[$ownerFieldName] ?? null;
		if ($field === null)
		{
			return;
		}

		$refusal =
			(new FieldEditableValidator(ValidationGroup::create((RequiredGroup::Update)->value)))
				->validate($field)
		;

		if (!$refusal->isSuccess())
		{
			throw new DtoValidationException($refusal->getErrors());
		}
	}

	/**
	 * What a refused write of the domain leaves as.
	 *
	 * Three answers, and the third is not the client's: a storage that would not write the row was caused
	 * by nothing the client sent and is avoided by nothing it can send. So it leaves as an ordinary
	 * exception, which REST v3 turns into a system error without its text and puts in the log - carrying
	 * $writeAddress, since a client told nothing about the failure makes the log the only place a portal
	 * can read which write it was.
	 *
	 * @param int $addressedId what the call named and did not get: the owner for a creation, the requisite
	 *        for a change and for a deletion.
	 */
	private static function refusalOf(Result $result, int $addressedId, string $writeAddress): \Throwable
	{
		$codes = array_map(static fn(Error $error): string => (string)$error->getCode(), $result->getErrors());

		if (array_intersect(self::ABSENCE_CODES, $codes) !== [])
		{
			return new EntityNotFoundException($addressedId);
		}

		if (in_array(RequisiteError::REQUISITE_OPERATION_FAILED->value, $codes, true))
		{
			return new \RuntimeException('The storage refused a write of a requisite: ' . $writeAddress);
		}

		return new RequestValidationException(array_map(self::addressedError(...), $result->getErrors()));
	}

	/**
	 * A refusal of the domain as a validation item: the phrase untouched, the code replaced by the field
	 * it is about.
	 *
	 * {@see \Bitrix\Rest\V3\Exception\Validation\ValidationException::output()} publishes the code of an
	 * error as the `field` of the answer, so a code here is an address and nothing else. A refusal that is
	 * about no field of its own keeps an empty address and is answered by its phrase alone.
	 */
	private static function addressedError(Error $error): Error
	{
		$customData = $error->getCustomData();
		$field = is_array($customData) ? $customData[self::REFUSED_FIELD] ?? '' : '';

		return new Error($error->getMessage(), (string)$field);
	}

	/**
	 * What a failed write addressed, for the log: the owner, the preset and the requisite. Each of them is
	 * named only where the call has it - a creation names no requisite yet, and a deletion carries neither
	 * an owner nor a preset.
	 */
	private static function writeAddress(
		EntityType $entityType,
		?int $ownerId,
		?int $presetId,
		?int $requisiteId,
	): string
	{
		return sprintf(
			'owner %s #%s, preset #%s, requisite #%s',
			$entityType->getCode(),
			$ownerId ?? '-',
			$presetId ?? '-',
			$requisiteId ?? '-',
		);
	}
}
