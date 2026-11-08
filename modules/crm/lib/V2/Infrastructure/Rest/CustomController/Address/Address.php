<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Address;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Address\OwnerAddressMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\AddRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\DeleteRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\GetRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\ListRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\UpdateRequest;
use Bitrix\Crm\V2\Public\Command\Address\AddOwnerAddressCommand;
use Bitrix\Crm\V2\Public\Command\Address\DeleteOwnerAddressCommand;
use Bitrix\Crm\V2\Public\Command\Address\OwnerAddressError;
use Bitrix\Crm\V2\Public\Command\Address\UpdateOwnerAddressCommand;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Crm\V2\Public\Entity\Address\OwnerAddress;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Crm\V2\Public\Provider\Address\OwnerAddressProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Internal\InternalException;
use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Interaction\Response\AddResponse;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;
use Bitrix\Rest\V3\Structure\PaginationStructure;

/**
 * `crm.{contact|company}.address.*` - the whole group, on one implementation.
 *
 * The type of the owner is an invariant of the route: it is set as an integer in
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\CustomController\SchemaProvider} and reaches an action by
 * auto-wiring ({@see AbstractController::getEntityType()}). Neither the query string nor the body is ever
 * read for it, so `crm.contact.address.add` writes addresses of contacts and of nothing else.
 *
 * Nothing of the subject matter is decided here. The rights, the choice of the requisite an address is
 * stored on, the flag of the primary address and every touch of storage belong to the public group
 * `Address` of CRM V2 ({@see OwnerAddressProvider} and the three commands next to it), and this class does
 * three things: it turns a request into the arguments of one scenario, it calls it, and it turns the
 * answer - successful or not - into the standard answer of REST v3.
 *
 * The user whose rights are checked is the one the infrastructure of the controller resolved
 * ({@see AbstractController::$userId}). A user identifier of the body is not a subject of a rights check
 * and is not read anywhere in this group.
 */
final class Address extends AbstractController
{
	/**
	 * The name the owner of an address is published under, by the entity type of the route.
	 *
	 * The single place the rule is written down. The configuration of the route reads it for the argument
	 * of the DTO generator, and the actions below read it to build their mapper - two consumers of one map
	 * rather than two copies of a name.
	 *
	 * @var array<int, string>
	 */
	public const OWNER_FIELD_NAME_BY_ENTITY_TYPE_ID = [
		OwnerType::CONTACT => 'contactId',
		OwnerType::COMPANY => 'companyId',
	];

	/**
	 * The owner a single address is asked for under: any owner of the type the route names.
	 *
	 * The three actions that address one stored address take its identifier alone - the canonical contract
	 * of the group gives them no owner to send, and asking a client for one it does not have would be a
	 * second way of saying what the identifier already says. The public group accepts that: the type of the
	 * owner still has to match the one of the row, and the right that decides the answer is the right on
	 * the owner the row actually belongs to ({@see OwnerAddressProvider::get()} and the two commands beside
	 * it). Resolving the owner here would be the rights layer's work done twice, and worse.
	 */
	private const ANY_OWNER = 0;

	/** @see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\OwnerAddressDtoGenerator */
	private const TYPE_FIELD = 'typeId';

	private const COUNTRY_CODE_FIELD = 'countryCode';

	/** @see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Address\AddRequest::$fields */
	private const FIELDS_PROPERTY = 'fields';

	public function addAction(AddRequest $request, EntityType $entityType): AddResponse
	{
		$mapper = $this->getMapper($entityType, $request);
		$dto = $request->fields->convertToDto(RequiredGroup::Add->value);
		$ownerId = $mapper->getOwnerIdByDto($dto);
		$this->assertCategoryOwnerAllowed(new ItemId($entityType, $ownerId));

		$command = new AddOwnerAddressCommand(
			$entityType->getId(),
			$ownerId,
			(int)$dto->{self::TYPE_FIELD},
			$mapper->mapToChanges($dto, $request->fields->getItems()),
			$this->userId,
		);

		try
		{
			$result = $command->run();
		}
		catch (ObjectNotFoundException)
		{
			throw new EntityNotFoundException($ownerId);
		}

		if (!$result->isSuccess())
		{
			throw self::refusalOf($result, $entityType);
		}

		return new AddResponse((int)$result->getData()[AddOwnerAddressCommand::DATA_ADDRESS_ID]);
	}

	/**
	 * The fields the client sent, written onto one stored address; the rest of it stays as it is stored.
	 *
	 * Which fields may be written at all is the contract of the route rather than a check of this action:
	 * the type of an address and its owner belong to the group of `add` alone, so a write of either is
	 * refused before the action runs, naming the field ({@see UpdateRequest}).
	 *
	 * A request that sends no field is a change of nothing and not a shortcut past the rights: the command
	 * still locates the address and still asks whether this user may change its owner.
	 */
	public function updateAction(UpdateRequest $request, EntityType $entityType): UpdateResponse
	{
		$this->assertAddressCategoryAllowed($request->id, $entityType);
		$mapper = $this->getMapper($entityType, $request);
		$dto = $request->fields->convertToDto(RequiredGroup::Update->value);

		$command = new UpdateOwnerAddressCommand(
			$request->id,
			$entityType->getId(),
			self::ANY_OWNER,
			$mapper->mapToChanges($dto, $request->fields->getItems()),
			$this->userId,
		);

		try
		{
			$result = $command->run();
		}
		catch (ObjectNotFoundException)
		{
			throw new EntityNotFoundException($request->id);
		}

		if (!$result->isSuccess())
		{
			throw self::refusalOf($result, $entityType);
		}

		return new UpdateResponse();
	}

	/**
	 * One stored address, whole.
	 *
	 * An address that does not exist, one of an owner of another type and one of an owner this user may
	 * not read are the same answer, and the reason is the public group's: which of the three it was is not
	 * something a client without the right to the owner may learn.
	 */
	public function getAction(GetRequest $request, EntityType $entityType): GetResponse
	{
		$this->assertAddressCategoryAllowed($request->id, $entityType);
		try
		{
			$address = (new OwnerAddressProvider())->get(
				$request->id,
				$entityType->getId(),
				self::ANY_OWNER,
				$this->userId,
			);
		}
		catch (ObjectNotFoundException)
		{
			throw new EntityNotFoundException($request->id);
		}

		$mapper = $this->getMapper($entityType, $request);

		return new GetResponse($mapper->mapToDto($address));
	}

	/**
	 * One stored address, removed. A second deletion of the same address is answered as an absent one.
	 */
	public function deleteAction(DeleteRequest $request, EntityType $entityType): DeleteResponse
	{
		$this->assertAddressCategoryAllowed($request->id, $entityType);
		$command = new DeleteOwnerAddressCommand(
			$request->id,
			$entityType->getId(),
			self::ANY_OWNER,
			$this->userId,
		);

		try
		{
			$result = $command->run();
		}
		catch (ObjectNotFoundException)
		{
			throw new EntityNotFoundException($request->id);
		}

		if (!$result->isSuccess())
		{
			throw self::refusalOf($result, $entityType);
		}

		return new DeleteResponse();
	}

	/**
	 * The addresses of one owner. Which owner, and whether one type of address alone was asked for, is
	 * settled by {@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\OwnerAddressListFilterStructure}
	 * before the action runs: a filter this read cannot express is refused there rather than narrowed here.
	 */
	public function listAction(ListRequest $request, EntityType $entityType): ListResponse
	{
		$ownerId = $request->filter->getOwnerId();
		$this->assertCategoryOwnerAllowed(new ItemId($entityType, $ownerId));

		try
		{
			$addresses = (new OwnerAddressProvider())->getList(
				$entityType->getId(),
				$ownerId,
				$this->userId,
				$request->filter->getTypeId(),
				$request->order?->getList() ?? [],
				self::pagerOf($request->pagination),
			);
		}
		catch (ObjectNotFoundException)
		{
			throw new EntityNotFoundException($ownerId);
		}

		$mapper = $this->getMapper($entityType, $request);
		$items = new DtoCollection($request->getDtoClass());
		foreach ($addresses as $address)
		{
			$items->add($mapper->mapToDto($address));
		}

		return new ListResponse($items);
	}

	private function assertAddressCategoryAllowed(int $addressId, EntityType $entityType): void
	{
		try
		{
			$address = (new OwnerAddressProvider())->get(
				$addressId,
				$entityType->getId(),
				self::ANY_OWNER,
				$this->userId,
			);
		}
		catch (ObjectNotFoundException)
		{
			throw new EntityNotFoundException($addressId);
		}

		$this->assertOwnerAddressCategoryAllowed($address);
	}

	private function assertOwnerAddressCategoryAllowed(OwnerAddress $address): void
	{
		$this->assertCategoryOwnerAllowed(new ItemId(
			EntityType::fromId($address->getOwnerTypeId()),
			$address->getOwnerId(),
		));
	}

	/**
	 * @throws SystemException When the route publishes no owner field for its entity type - a route of
	 *         another entity type registered on this controller by mistake.
	 */
	private function getMapper(EntityType $entityType, Request $request): OwnerAddressMapper
	{
		return new OwnerAddressMapper(
			self::ownerFieldNameOf($entityType),
			$request->getDtoClass(),
		);
	}

	private static function ownerFieldNameOf(EntityType $entityType): string
	{
		return self::OWNER_FIELD_NAME_BY_ENTITY_TYPE_ID[$entityType->getId()]
			?? throw new SystemException(
				"The address group publishes no owner field for the entity type {$entityType->getId()}.",
			)
		;
	}

	/**
	 * The page a list is asked for. A request that names no paging gets the default page of the framework
	 * rather than the whole set: an unbounded external read is not on offer.
	 *
	 * The bounds are checked here because {@see PaginationStructure} lets a negative page through while
	 * {@see Pager} throws on one, and a page a client can fix must be answered as a request it can fix -
	 * the same guard the group of product rows keeps
	 * ({@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Item\ProductRow\ProductRowListRequestMapper::mapPager()}).
	 */
	private static function pagerOf(?PaginationStructure $pagination): Pager
	{
		if ($pagination === null)
		{
			return new Pager();
		}

		$limit = $pagination->getLimit();
		$offset = $pagination->getOffset();
		if ($limit <= 0)
		{
			throw new InvalidPaginationException(['limit' => $limit]);
		}
		if ($offset < 0)
		{
			throw new InvalidPaginationException(['offset' => $offset]);
		}

		return new Pager($limit, $offset);
	}

	/**
	 * What REST answers to a refused scenario of the group.
	 *
	 * The refusals the group names itself are the ones a client can act on, and they are a closed set -
	 * {@see OwnerAddressError}. Each of them is a validation refusal of the request, in the words of this
	 * layer rather than of the domain, naming the field the client has to look at:
	 *
	 * | Code of the domain | Field |
	 * |---|---|
	 * | `ADDRESS_TYPE_OCCUPIED` | `typeId` |
	 * | `ADDRESS_TYPE_UNKNOWN` | `typeId` |
	 * | `ADDRESS_IS_EMPTY` | `fields` |
	 * | `ADDRESS_COUNTRY_CODE_MISMATCH` | `countryCode` |
	 * | `ADDRESS_ONLY_REQUISITE_NOT_CREATED` | the owner of the route |
	 *
	 * The messages are rewritten because the domain writes its errors for a developer reading a log ("The
	 * requisite already holds an address of this type.").
	 *
	 * Anything else is not a refusal of the group at all: the requisites of CRM and the ORM underneath them
	 * report their own failures through the same result, and no client can fix a storage that did not
	 * store. Such an answer is the system error of REST v3 - it is journalled with the text of the domain
	 * and answers the client without it, the way every unexpected failure of REST v3 is answered.
	 *
	 * One foreign error makes the whole answer a system error, and a refusal of the group standing next to
	 * it is not reported: an answer either names refusals only or names none. That is decided rather than
	 * left out - a result that carries both is a result nobody understands, and telling the client to fix a
	 * field while the portal is failing underneath is worse than saying it failed. No scenario builds such
	 * a result today, each of them answers with one error at a time.
	 *
	 * An owner or an address the user may not have never gets here - the scenario leaves through
	 * {@see ObjectNotFoundException} for it, and every action answers that as an absent object.
	 */
	private static function refusalOf(Result $result, EntityType $entityType): RestException
	{
		$errors = [];
		foreach ($result->getErrors() as $error)
		{
			$refusal = OwnerAddressError::tryFrom((string)$error->getCode());
			if ($refusal === null)
			{
				return self::failureOf($result);
			}

			$errors[] = self::toValidationError($refusal, $entityType);
		}

		return new RequestValidationException($errors);
	}

	private static function toValidationError(OwnerAddressError $refusal, EntityType $entityType): Error
	{
		return match ($refusal)
		{
			OwnerAddressError::ADDRESS_TYPE_OCCUPIED => new Error(
				new LocalizableMessage('CRM_V2_REST_OWNER_ADDRESS_TYPE_OCCUPIED'),
				self::TYPE_FIELD,
			),
			OwnerAddressError::ADDRESS_TYPE_UNKNOWN => new Error(
				new LocalizableMessage('CRM_V2_REST_OWNER_ADDRESS_TYPE_UNKNOWN'),
				self::TYPE_FIELD,
			),
			OwnerAddressError::ADDRESS_IS_EMPTY => new Error(
				new LocalizableMessage('CRM_V2_REST_OWNER_ADDRESS_IS_EMPTY'),
				self::FIELDS_PROPERTY,
			),
			OwnerAddressError::ADDRESS_COUNTRY_CODE_MISMATCH => new Error(
				new LocalizableMessage('CRM_V2_REST_OWNER_ADDRESS_COUNTRY_CODE_MISMATCH'),
				self::COUNTRY_CODE_FIELD,
			),
			OwnerAddressError::ADDRESS_ONLY_REQUISITE_NOT_CREATED => new Error(
				new LocalizableMessage('CRM_V2_REST_OWNER_ADDRESS_REQUISITE_NOT_CREATED'),
				self::ownerFieldNameOf($entityType),
			),
		};
	}

	/**
	 * A refusal none of the scenarios of the group names, as an internal failure: the client is answered
	 * without a word of the domain in it, and the words themselves reach the log through the exception the
	 * failure is carried by.
	 */
	private static function failureOf(Result $result): InternalException
	{
		return new InternalException(new SystemException(
			'The address scenario failed: ' . implode('; ', $result->getErrorMessages()),
		));
	}
}
