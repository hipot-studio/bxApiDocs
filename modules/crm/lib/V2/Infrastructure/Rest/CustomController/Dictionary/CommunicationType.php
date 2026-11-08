<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Dictionary;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Dictionary\CommunicationTypeListRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Dictionary\CommunicationTypeNotFoundException;
use Bitrix\Crm\V2\Public\Entity\Dictionary\CommunicationChannel;
use Bitrix\Crm\V2\Public\Provider\Dictionary\CommunicationTypeProvider;
use Bitrix\Main\Context;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\LogicException;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

/**
 * `crm.dictionary.{emailType|phoneType|imType|webType}.{get|list}` - the value types a communication
 * channel accepts. One controller answers all eight methods.
 *
 * The channel is read from the service parameters of the route and from nowhere else: the object of
 * a request is filled from the body of the client, so a controller reading the channel off the
 * request would answer about the dictionary the client names. AbstractController::getEntityType()
 * is no help either - it treats a route parameter as trusted only when it is an int.
 *
 * There is no `#[DtoType]` here on purpose: the type comes from the `dtoFqcn` of the route. The
 * attribute wins over the route when the request is built, while `getDtoMapper()` keeps resolving
 * the DTO of the route - the type would end up with two sources instead of one.
 */
final class CommunicationType extends RestController
{
	/** The service parameter of the route naming the channel the dictionary belongs to. */
	private const CHANNEL_PARAMETER = 'multifieldTypeId';

	private const FILTER_FIELD = 'filter';

	private ?CommunicationTypeProvider $provider = null;

	public function getAction(GetRequest $request): GetResponse
	{
		$communicationType = $this->getProvider()->getById(
			$this->getChannel(),
			$request->id,
			$this->getResponseLanguage(),
		);
		if ($communicationType === null)
		{
			throw new CommunicationTypeNotFoundException($request->id);
		}

		return new GetResponse(
			$this->getDtoMapper()->mapOne($communicationType, $request->select?->getStructuredList() ?? []),
		);
	}

	public function listAction(ListRequest $request): ListResponse
	{
		$this->refuseMalformedFilter($request);

		$communicationTypes = (new CommunicationTypeListRequestMapper())->map(
			$this->getProvider()->getList($this->getChannel(), $this->getResponseLanguage()),
			$request,
		);

		return new ListResponse(
			$this->getDtoMapper()->mapCollection($communicationTypes, $request->select?->getStructuredList() ?? []),
		);
	}

	/**
	 * A filter that is not a group of conditions never reaches the request of a list: the transport
	 * keeps the `filter` of the body only when its value is an array, and it does so for every route
	 * declaring query parameters - which every route of this group does, to name its channel. Left at
	 * that, a client sending a malformed filter would be answered with the whole dictionary instead of
	 * a refusal, while the dictionaries whose routes name no channel refuse it.
	 *
	 * The value is put through the parser of the filter rather than judged here, so the refusal is the
	 * one those dictionaries answer with, whatever it is.
	 *
	 * Three details of where the value is read from:
	 *
	 * - it is read off the request of the context, because the request of the controller is the one the
	 *   transport rebuilt and the dropped key is already gone from it;
	 * - a value of null is left alone: a property of a request is filled from the body by `isset`, so
	 *   null is "no filter" for every dictionary, this one included;
	 * - an element of a batch keeps the answer of the transport: the body of a batch is the list of its
	 *   elements itself, the body of an element is built by the batch and never reaches the request of
	 *   the context, so the `filter` read here belongs to no element. Nothing here is keyed off the
	 *   shape of the body either - a key of the body must not be able to switch this check off.
	 */
	private function refuseMalformedFilter(ListRequest $request): void
	{
		$body = Context::getCurrent()->getRequest()->getJsonList()->getValues();
		$filter = $body[self::FILTER_FIELD] ?? null;
		if ($filter === null || is_array($filter))
		{
			return;
		}

		FilterStructure::create($filter, $request->getDtoClass(), $request);
	}

	private function getChannel(): CommunicationChannel
	{
		$channelId = $this->getSourceParametersList()[0][self::CHANNEL_PARAMETER] ?? null;
		$channel = is_string($channelId) ? CommunicationChannel::tryFrom($channelId) : null;
		if ($channel === null)
		{
			// a route naming no known channel is a misconfiguration of the schema, not a bad request
			throw new LogicException(new SystemException(
				'A route of ' . self::class . ' must declare a communication channel in its "'
				. self::CHANNEL_PARAMETER . '" query param',
			));
		}

		return $channel;
	}

	private function getProvider(): CommunicationTypeProvider
	{
		return $this->provider ??= new CommunicationTypeProvider();
	}
}
