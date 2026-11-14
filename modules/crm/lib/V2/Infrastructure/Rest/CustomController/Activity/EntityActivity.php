<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Activity;

use Bitrix\Crm\V2\Infrastructure\Rest\Controller\Availability\ActivityEnabledProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Activity\ActivityDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Activity\ActivityCollectionMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Activity\ActivityDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Activity\ActivityListRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Activity\ContextGetActivityRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Activity\ContextListActivityRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Activity\ActivityReadingMapper;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Activity\ActivityProvider;
use Bitrix\Crm\V2\Public\Provider\Activity\Param\ActivityListFilter;
use Bitrix\Crm\V2\Public\Provider\Activity\Param\ActivityListSort;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Request;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Schema\CheckEnabledProvider;

/**
 * The activities of CRM read in the context of an entity type: `crm.{lead|deal|contact|company}.activity.get`
 * and `crm.smartprocess<id>.activity.get`, and the same names ending with `list`: one action per ending behind
 * the routes of {@see \Bitrix\Crm\V2\Infrastructure\Rest\CustomController\SchemaProvider}.
 *
 * The entity type is the trusted one of the route and nothing else: a body naming another is not heard, and a
 * route of a type the family does not serve is refused. Transport only: the right, the rule of the type and the
 * reading belong to {@see ActivityProvider::getByIdInContext()} and {@see ActivityProvider::getListInContext()},
 * the fields of the answer to the mappers of the contract, which is the one of `crm.activity.get`.
 */
#[DtoType(ActivityDto::class)]
final class EntityActivity extends AbstractController
{
	/** The scopes every route of the family is registered with. */
	public const SCOPES = ['crm'];

	/**
	 * The contract of the list as a client is told it, by the documentation of REST and by the tool of MCP alike:
	 * {@see RouteBuilder} hands the same text to the tool of every route.
	 */
	public const LIST_DESCRIPTION =
		'Returns a page of the activities bound to one CRM item of the entity type of the route; the page carries'
		. ' no total. The item is named in `filter` and nowhere else: `filter` is required and must hold exactly'
		. ' one condition `["ownerId", "=", N]` among its top-level conditions, where N is a positive integer.'
		. ' `ownerId` in a nested list, in a group or under a negation, with another operator or named twice is'
		. ' refused, and so is `ownerId` in the body outside `filter` (it is not silently ignored, unlike'
		. ' `crm.{entity}.productrow.list`). The other conditions are joined to it by AND.'
		. ' An item that does not exist, an item in the recycle bin and an item the caller may not read get the'
		. ' same answer for every caller, an administrator included: not found'
		. ' (`BITRIX_REST_V3_EXCEPTION_ENTITYNOTFOUNDEXCEPTION`), not an empty page. The activities of an item the'
		. ' caller may not read are not listed at all, even the ones the caller is responsible for or reads'
		. ' through another binding.'
		. ' The page lists every activity bound to the item by any of its bindings, each exactly as'
		. ' `crm.{entity}.activity.get` answers it to the caller, with all the bindings of the activity.'
		. ' The default page size (`limit`) is 50 and the maximum is 200; a larger `limit` is cut to 200.'
		. ' An activity without a deadline answers `deadlineTime` as null, but the storage keeps "no deadline"'
		. ' in two ways, so a sort by `deadlineTime` places such activities at both ends of the list: some'
		. ' before the activities with a deadline and the rest after them. To get all of them, filter'
		. ' by `deadlineTime` equal to null.'
		. ' Differences from the legacy `crm.activity.list`: that method filters by the primary owner of the'
		. ' activity (`OWNER_ID`, `OWNER_TYPE_ID`) and does not ask the provider of an activity whether it may'
		. ' be read; this one lists by any binding and leaves out the activities whose provider forbids reading'
		. ' them (documents and call lists). Difference from REST V3 `crm.activity.list` with `filter.binding`,'
		. ' which is for an administrator of the portal only: there the activities hidden by their provider are'
		. ' listed, here they are not, for an administrator either.'
	;

	private CheckEnabledProvider $availability;

	/** @var \Closure(int, int, int, string[]): Result */
	private \Closure $reader;

	/** @var \Closure(int, int, int, ActivityListFilter, ActivityListSort, Pager, string[]): Result */
	private \Closure $lister;

	/**
	 * @param CheckEnabledProvider|null $availability whether the methods are published, the flag of the wave by
	 *        default, as for `crm.activity.get`.
	 * @param (\Closure(int $userId, int $id, int $entityTypeId, string[] $select): Result)|null $reader reads one
	 *        activity for a user in the context of a type and answers the way
	 *        {@see ActivityProvider::getByIdInContext()} does, which is what it is by default.
	 * @param (\Closure(int $userId, int $entityTypeId, int $ownerId, ActivityListFilter $filter,
	 *        ActivityListSort $sort, Pager $pager, string[] $select): Result)|null $lister reads a page of the
	 *        activities of a parent for a user and answers the way {@see ActivityProvider::getListInContext()}
	 *        does, which is what it is by default.
	 */
	public function __construct(
		?Request $request = null,
		?CheckEnabledProvider $availability = null,
		?\Closure $reader = null,
		?\Closure $lister = null,
	)
	{
		parent::__construct($request);

		$this->availability = $availability ?? new ActivityEnabledProvider();
		$this->reader = $reader
			?? static fn(int $userId, int $id, int $entityTypeId, array $select): Result =>
				(new ActivityProvider($userId))->getByIdInContext($id, $entityTypeId, $select)
		;
		$this->lister = $lister
			?? static fn(
				int $userId,
				int $entityTypeId,
				int $ownerId,
				ActivityListFilter $filter,
				ActivityListSort $sort,
				Pager $pager,
				array $select,
			): Result => (new ActivityProvider($userId))
				->getListInContext($entityTypeId, $ownerId, $filter, $sort, $pager, $select)
		;
	}

	public static function isSupportedEntityTypeId(int $entityTypeId): bool
	{
		return
			in_array($entityTypeId, RouteBuilder::STATIC_ENTITY_TYPE_IDS, true)
			|| EntityType::isPossibleSmartProcessTypeId($entityTypeId)
		;
	}

	/**
	 * One activity bound to at least one object of the type of the route, as the user of the request may read
	 * it. An activity that does not exist, one the user may not read and one bound to no object of the type get
	 * the same answer, the standard not found of REST.
	 */
	public function getAction(
		ContextGetActivityRequest $request,
		CurrentUser $currentUser,
		EntityType $entityType,
	): GetResponse
	{
		$select = ActivityReadingMapper::selectedNames($request->select);
		$nested = ActivityReadingMapper::nestedNames($request->select);
		$result = ($this->reader)((int)$currentUser->getId(), $request->id, $entityType->getId(), $select);
		if (!$result->isSuccess())
		{
			throw ActivityReadingMapper::refusalOf($result, $request->id);
		}

		$data = $result->getData();
		$activity = $data[ActivityProvider::DATA_ACTIVITY];

		$dto = (new ActivityDtoMapper())->map($activity, $select);
		(new ActivityCollectionMapper())->fill($dto, $activity, $data[ActivityProvider::DATA_FILES], $select, $nested);

		return new GetResponse($dto);
	}

	/**
	 * A page of the activities bound to one object of the type of the route, the parent the filter names by
	 * `ownerId`, each as {@see getAction()} answers it to the user of the request. A parent that does not exist,
	 * one in the recycle bin and one the user may not read get the same answer, the standard not found of REST
	 * naming the owner of the request. The page carries no total.
	 */
	#[Description(self::LIST_DESCRIPTION)]
	public function listAction(
		ContextListActivityRequest $request,
		CurrentUser $currentUser,
		EntityType $entityType,
	): ListResponse
	{
		$select = ActivityReadingMapper::selectedNames($request->select);
		$nested = ActivityReadingMapper::nestedNames($request->select);
		$mapper = new ActivityListRequestMapper();
		$ownerId = $request->filter->getOwnerId();
		$result = ($this->lister)(
			(int)$currentUser->getId(),
			$entityType->getId(),
			$ownerId,
			$mapper->mapContextFilter($request->filter),
			$mapper->mapSort($request->order),
			$mapper->mapPager($request->pagination),
			$select,
		);
		if (!$result->isSuccess())
		{
			throw ActivityReadingMapper::refusalOf($result, $ownerId);
		}

		$dtoMapper = new ActivityDtoMapper();
		$collectionMapper = new ActivityCollectionMapper();
		$items = new DtoCollection(ActivityDto::class);
		foreach ($result->getData()[ActivityProvider::DATA_ITEMS] as $item)
		{
			$activity = $item[ActivityProvider::DATA_ACTIVITY];
			$dto = $dtoMapper->map($activity, $select);
			$collectionMapper->fill($dto, $activity, $item[ActivityProvider::DATA_FILES], $select, $nested);
			$items->add($dto);
		}

		return new ListResponse($items);
	}

	/**
	 * An unpublished method is refused before its request is parsed, as `crm.activity.get` is.
	 */
	protected function processBeforeAction(Action $action): bool
	{
		if (!$this->availability->isEnabled())
		{
			throw new AccessDeniedException();
		}

		return parent::processBeforeAction($action) === true;
	}

	/**
	 * Only the route names the type. Unlike the parent, a route without one does not fall back to the body:
	 * a type the client could name is a type it could substitute.
	 */
	protected function getEntityType(string $paramName = 'entityTypeId'): EntityType
	{
		$routeEntityTypeId = $this->getSourceParametersList()[0][$paramName] ?? null;
		if (!is_int($routeEntityTypeId) || !self::isSupportedEntityTypeId($routeEntityTypeId))
		{
			throw new EntityNotFoundException(is_int($routeEntityTypeId) ? $routeEntityTypeId : 0);
		}

		return EntityType::fromId($routeEntityTypeId);
	}
}
