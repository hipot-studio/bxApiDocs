<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Dictionary;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Dictionary\DictionaryValueListRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Dictionary\DictionaryValueNotFoundException;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Dictionary\AddValueRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Dictionary\DeleteValueRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Dictionary\GetValueRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Dictionary\UpdateValueRequest;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryError;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryType;
use Bitrix\Crm\V2\Internal\Service\Dictionary\DictionaryValueService;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\LogicException;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;
use Bitrix\Rest\V3\Structure\FieldsStructure;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

/**
 * The values of the seven basic dictionaries of CRM over REST:
 * `crm.dictionary.{dealType|contactType|honorific|companyType|employees|industry|source}.{get|list|add|
 * update|delete}`. One controller answers all thirty-five methods.
 *
 * The dictionary is read from the service parameters of the route and from nowhere else: the object of a
 * request is filled from the body of the client, so a controller reading the dictionary off the request
 * would answer about the one the client names. AbstractController of this namespace is no help either -
 * it auto-wires an entity type the dictionaries have none of, and its helper falls back to the body of
 * the request whenever the parameter of the route is not an int.
 *
 * There is no `#[DtoType]` here on purpose: the type comes from the `dtoFqcn` of the route. The attribute
 * wins over the route when the request is built, while `getDtoMapper()` keeps resolving the DTO of the
 * route - the type would end up with two sources instead of one.
 *
 * Every action does the same three things and nothing else: it translates the request into the types of
 * the domain, runs exactly one operation of {@see DictionaryValueService}, and translates the answer
 * back. Permissions, the rules of the fields and every touch of storage belong to that scenario - this
 * controller neither repeats them nor works around them. The name of a value is answered as it is stored:
 * it is data of the portal an administrator edits, and no language of the response applies to it.
 *
 * What a client can fix the scenario reports inside a {@see Result}, and that is what the refusals below
 * translate. Whatever escapes it instead - a failed query above all - says the portal is broken, and it
 * is answered as an internal failure by the platform itself: {@see RestController::runProcessingThrowable()}
 * turns every exception that is no {@see RestException} into one, publishing no word of its text, while
 * the journal keeps the original. So nothing here catches an exception of the scenario to classify it -
 * a second answer for the same case would only differ from that one.
 */
final class DictionaryValue extends RestController
{
	/** The service parameter of the route naming the dictionary the values belong to. */
	private const DICTIONARY_PARAMETER = 'dictionaryTypeId';

	/**
	 * How a client tells one refusal of this group from another, published to the client rather than kept
	 * here: the answer carries no code of a particular reason, so a client that looked for one would end
	 * up reading the text of a message.
	 */
	private const REFUSAL_DESCRIPTION =
		'A refusal is told apart by the `code` of the answer: `BITRIX_REST_V3_EXCEPTION_ACCESSDENIEDEXCEPTION`'
		. ' says the caller may not perform the action, `BITRIX_REST_V3_EXCEPTION_ENTITYNOTFOUNDEXCEPTION`'
		. ' that the dictionary has no such value, and'
		. ' `BITRIX_REST_V3_EXCEPTION_VALIDATION_REQUESTVALIDATIONEXCEPTION` that the request is one the'
		. ' client can fix. The last one reports every refusal at once in `validation`, where an element'
		. ' names the field it is about in `field` and states the reason in `message` alone: no code of a'
		. ' particular reason is published, so branch on `code` and on `field` and never on the text of a'
		. ' message.'
	;

	private const FILTER_FIELD = 'filter';

	private int $userId;

	private ?DictionaryValueService $service = null;

	protected function init(): void
	{
		$this->userId = (int)CurrentUser::get()->getId();

		parent::init();
	}

	#[Description('Returns one value of the dictionary by its identifier. ' . self::REFUSAL_DESCRIPTION)]
	public function getAction(GetValueRequest $request): GetResponse
	{
		$type = $this->getDictionaryType();

		$result = $this->getService()->getById($type, $request->id, $this->userId);
		if (!$result->isSuccess())
		{
			throw self::valueRefusalOf($result, $request->id);
		}

		return new GetResponse(
			$this->getDtoMapper()->mapOne(
				$result->getData()[DictionaryValueService::DATA_KEY_VALUE],
				$request->select?->getStructuredList() ?? [],
			),
		);
	}

	#[Description('Returns the values of the dictionary. ' . self::REFUSAL_DESCRIPTION)]
	public function listAction(ListRequest $request): ListResponse
	{
		self::refuseMalformedFilter($this->malformedFilterOfTheBody(), $request);

		$mapper = new DictionaryValueListRequestMapper();
		$type = $this->getDictionaryType();
		$filter = $mapper->mapFilter($request->filter);
		$sort = $mapper->mapSort($request->order);
		$pager = $mapper->mapPager($request->pagination);

		$result = $this->getService()->getList($type, $this->userId, $filter, $sort, $pager);
		if (!$result->isSuccess())
		{
			throw self::refusalOf($result);
		}

		return new ListResponse(
			$this->getDtoMapper()->mapCollection(
				$result->getData()[DictionaryValueService::DATA_KEY_VALUES],
				$request->select?->getStructuredList() ?? [],
			),
		);
	}

	/**
	 * The answer carries the whole new value rather than its identifier alone, and that is a declared
	 * deviation from the matrix of the standard answers. {@see \Bitrix\Rest\V3\Interaction\Response\AddResponse}
	 * publishes an `int` identifier, while a value of a dictionary is identified by a string. Of the
	 * standard shapes {@see GetResponse} is the one that fits, and returning the created entity is also
	 * what a hand-written `add` of REST v3 is told to answer; the stages of a pipeline and the two
	 * requisite groups deviate the same way for the same reason.
	 *
	 * The whole value is what the client needs here rather than a convenience: an identifier left out is
	 * generated and a position left out is defaulted, both by the domain, so the answer is the only place
	 * the client learns them from.
	 */
	#[Description(
		'Creates a value of the dictionary and answers with the whole of it: an identifier left out is'
		. ' generated and a position left out is defaulted, and the answer is the only place a client'
		. ' learns them from. ' . self::REFUSAL_DESCRIPTION
	)]
	public function addAction(AddValueRequest $request): GetResponse
	{
		$type = $this->getDictionaryType();
		$fields = self::fieldsOf($request->fields, RequiredGroup::Add);

		$result = $this->getService()->add($type, $fields, $this->userId, Scope::Rest);
		if (!$result->isSuccess())
		{
			throw self::refusalOf($result);
		}

		return new GetResponse(
			$this->getDtoMapper()->mapOne($result->getData()[DictionaryValueService::DATA_KEY_VALUE]),
		);
	}

	/**
	 * An empty set of fields is answered by the scenario rather than short-circuited here: it is the only
	 * thing that can tell whether the value exists and whether the caller may change it, and answering a
	 * changing method by the read right alone would be wrong.
	 */
	#[Description(
		'Changes the fields a request carries of one value of the dictionary. ' . self::REFUSAL_DESCRIPTION
	)]
	public function updateAction(UpdateValueRequest $request): UpdateResponse
	{
		self::refuseFilterOfAChange($this->filterOfAChange());

		$type = $this->getDictionaryType();
		$fields = self::fieldsOf($request->fields, RequiredGroup::Update);

		$result = $this->getService()->update($type, $request->id, $fields, $this->userId, Scope::Rest);
		if (!$result->isSuccess())
		{
			throw self::valueRefusalOf($result, $request->id);
		}

		return new UpdateResponse();
	}

	#[Description('Deletes one value of the dictionary. ' . self::REFUSAL_DESCRIPTION)]
	public function deleteAction(DeleteValueRequest $request): DeleteResponse
	{
		self::refuseFilterOfAChange($this->filterOfAChange());

		$type = $this->getDictionaryType();

		$result = $this->getService()->delete($type, $request->id, $this->userId, Scope::Rest);
		if (!$result->isSuccess())
		{
			throw self::valueRefusalOf($result, $request->id);
		}

		return new DeleteResponse();
	}

	/**
	 * The filter a changing request carries, whichever shape the client gave it, or `null` for a request
	 * that carries none. The two shapes survive in two different places, so each one is read where it is,
	 * and {@see conditionsFilterOfTheBody()} and {@see malformedFilterOfTheBody()} tell why.
	 *
	 * A value of null is "no filter" and not a filter of null: a property of a request is filled from the
	 * body by `isset`, so that is what null means for every group.
	 */
	private function filterOfAChange(): mixed
	{
		return $this->conditionsFilterOfTheBody() ?? $this->malformedFilterOfTheBody();
	}

	/**
	 * The filter of the body as a group of conditions, or `null` when the body carried nothing of that
	 * shape.
	 *
	 * It is read off the request of the controller, which is where the transport leaves it. Every route of
	 * this group declares query parameters - the dictionary sits in them - and for such a route the
	 * transport builds the request anew out of the body and those parameters, appending a `filter` of the
	 * body to the `filter` of the route. So what stands here is the filter of the route carrying the one
	 * of the body inside it, and the routes of this group declare none of their own.
	 *
	 * An element of a batch is built the same way and answers the same: the transport makes an http
	 * request of its own out of the body of the element and puts that one through the very same rebuild.
	 */
	private function conditionsFilterOfTheBody(): ?array
	{
		$filter = $this->getRequest()->getJsonList()->getValues()[self::FILTER_FIELD] ?? null;

		return is_array($filter) ? $filter : null;
	}

	/**
	 * The filter of the body that is no group of conditions at all - a string, a number, a boolean - or
	 * `null` when the body carried nothing of that shape.
	 *
	 * It is read off the request of the context, because the rebuild above drops such a value without a
	 * word: the transport appends a `filter` of the body only when it is an array, and lets any other
	 * value go. The request of the context is the http request of the portal and carries the body as the
	 * client wrote it.
	 *
	 * An array standing there is never taken: inside a batch the request of the context holds the body of
	 * the whole batch, so a `filter` of it belongs to no element and is none of this check's business.
	 *
	 * Known limit, and a declared one: inside a batch a malformed filter is not seen here at all. The
	 * transport drops the value while building the http request of the element and keeps the raw body of
	 * an element nowhere, so a `list` called inside a batch with a filter of a scalar answers the whole
	 * dictionary instead of refusing. Nothing is read past a permission that way - the scenario checks the
	 * rights and answers what the caller may read anyway.
	 */
	private function malformedFilterOfTheBody(): mixed
	{
		$filter = Context::getCurrent()->getRequest()->getJsonList()->getValues()[self::FILTER_FIELD] ?? null;

		return is_array($filter) ? null : $filter;
	}

	/**
	 * Refuses a changing request that carries a filter: a typical method of CRM addresses one value by its
	 * identifier, and a set is not something it changes or erases quietly.
	 *
	 * Leaving the property out of the request is not enough on its own - that loses the filter rather than
	 * refusing it: a request is filled from its declared public properties alone and an unknown key of the
	 * body is ignored in silence, so a client sending `id` and `filter` together would be answered with
	 * one value changed instead of a refusal. The neighbouring groups of categories and stages stop at the
	 * missing property, and this is where this group goes further than them.
	 */
	private static function refuseFilterOfAChange(mixed $filter): void
	{
		if ($filter === null)
		{
			return;
		}

		throw new InvalidFilterException($filter);
	}

	/**
	 * A filter that is not a group of conditions never reaches the request of a list: the transport keeps
	 * the `filter` of the body only when its value is an array, and it does so for every route declaring
	 * query parameters - which every route of this group does, to name its dictionary. Left at that, a
	 * client sending a malformed filter would be answered with the whole dictionary instead of a refusal,
	 * while the dictionaries whose routes name nothing refuse it.
	 *
	 * The value is put through the parser of the common filter rather than judged here, so the refusal is
	 * the one those dictionaries answer with, whatever it is.
	 */
	private static function refuseMalformedFilter(mixed $filter, ListRequest $request): void
	{
		if ($filter === null || is_array($filter))
		{
			return;
		}

		FilterStructure::create($filter, $request->getDtoClass(), $request);
	}

	/**
	 * The dictionary all thirty-five methods of the group are about, as the route names it. A route naming
	 * no known dictionary is a misconfiguration of the schema rather than a bad request.
	 */
	private function getDictionaryType(): DictionaryType
	{
		$dictionaryTypeId = $this->getSourceParametersList()[0][self::DICTIONARY_PARAMETER] ?? null;
		$type = is_string($dictionaryTypeId) ? DictionaryType::tryFrom($dictionaryTypeId) : null;
		if ($type === null)
		{
			throw new LogicException(new SystemException(
				'A route of ' . self::class . ' must declare a known dictionary in its "'
				. self::DICTIONARY_PARAMETER . '" query param',
			));
		}

		return $type;
	}

	private function getService(): DictionaryValueService
	{
		return $this->service ??= new DictionaryValueService();
	}

	/**
	 * The set of fields the scenario is given: the fields the request carried and no key at all for the
	 * ones it did not.
	 *
	 * The difference is kept by the value of the contract itself - a field left out of the set stays an
	 * uninitialised property, and only an initialised property becomes a key. Nothing here turns an absent
	 * field into a null one and nothing here makes up a default: which fields a value keeps and what an
	 * absent one is filled with are answers of the domain.
	 *
	 * @return array<string, mixed>
	 */
	private static function fieldsOf(FieldsStructure $fields, RequiredGroup $group): array
	{
		return $fields->convertToDto($group->value)->toArray();
	}

	/**
	 * What REST answers to a refused operation that addresses no particular value: a caller who may not
	 * perform the action at all is answered by access, and everything else is something the client can fix
	 * in its input.
	 *
	 * A code the group does not know is answered the same way rather than as a failure of the portal: an
	 * expected error of a client is not journalled as a system fault. The text of the storage boundary
	 * never reaches a client - the domain does not let it out in the first place. A failure of the storage
	 * itself does not arrive here at all: it escapes the scenario as an exception, and the platform
	 * answers it as an internal failure of the portal.
	 *
	 * Every error of the result travels, not the first one: the rules of the fields are answered all at
	 * once, and a client fixing a request wants the whole of what is wrong with it.
	 */
	private static function refusalOf(Result $result): RestException
	{
		if (self::refusalCodeOf($result) === DictionaryError::ACCESS_DENIED->value)
		{
			return new AccessDeniedException();
		}

		return new RequestValidationException(array_map(self::fieldRefusalOf(...), $result->getErrors()));
	}

	/**
	 * A refusal of the domain as the transport publishes it: the `field` of an element of the answer is the
	 * name of the field, taken from the custom data of the error. The slot is the platform's own and the
	 * platform fills it with a name of a field for the refusals of its own - a code of the domain standing
	 * there would name a field of the form the client has not. The code itself has nowhere else to go: an
	 * element carries a message and a field and nothing more, and the transport reads the custom data of an
	 * error nowhere.
	 *
	 * A refusal about no field at all - a failure of the storage boundary above all - names none rather
	 * than naming something: an element whose code is empty is published without the `field`, and that is
	 * what "not about a field of the request" looks like in this answer.
	 *
	 * So a client is left with the `code` of the answer and the `field` of an element to tell one refusal
	 * from another, and that is said to the client itself rather than only here - {@see
	 * REFUSAL_DESCRIPTION} goes into the published description of every method of the group.
	 *
	 * The message is moved over as it came and not as a text: the domain leaves its phrase unresolved so
	 * that the language of the answer can pick the wording, and reading it out here would settle the
	 * wording in the language of the portal for every client
	 * ({@see \Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryError::toError()}).
	 */
	private static function fieldRefusalOf(Error $error): Error
	{
		return new Error(
			$error->getLocalizableMessage() ?? $error->getMessage(),
			$error->getCustomData()[DictionaryError::CUSTOM_DATA_FIELD] ?? '',
		);
	}

	/**
	 * What REST answers to a refused operation addressing the value $id.
	 *
	 * A value the dictionary has not is answered as missing, and so is one living in another dictionary or
	 * among the stages of a pipeline. A caller who may not perform the action is answered by access
	 * instead: the domain refuses by the permission before it looks the value up, so such a caller is told
	 * nothing about the value either way.
	 */
	private static function valueRefusalOf(Result $result, string $id): RestException
	{
		if (self::refusalCodeOf($result) === DictionaryError::VALUE_NOT_FOUND->value)
		{
			return new DictionaryValueNotFoundException($id);
		}

		return self::refusalOf($result);
	}

	/**
	 * The code a refusal of the domain is recognised by: a case of {@see DictionaryError}. A refusal about
	 * the operation itself carries exactly one error, while the ones about the fields carry neither the
	 * code of access nor the one of absence.
	 */
	private static function refusalCodeOf(Result $result): string
	{
		$errors = $result->getErrors();

		return (string)(($errors[0] ?? null)?->getCode() ?? '');
	}
}
