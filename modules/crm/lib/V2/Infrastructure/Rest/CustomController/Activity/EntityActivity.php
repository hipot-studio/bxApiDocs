<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Activity;

use Bitrix\Crm\V2\Infrastructure\Rest\Controller\Availability\ActivityEnabledProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Activity\ActivityDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Activity\ActivityCollectionMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Activity\ActivityDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Activity\ContextGetActivityRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Activity\ActivityReadingMapper;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Activity\ActivityProvider;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Request;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Schema\CheckEnabledProvider;

/**
 * The activities of CRM read in the context of an entity type: `crm.{lead|deal|contact|company}.activity.get`
 * and `crm.smartprocess<id>.activity.get`: one action behind the routes of
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\CustomController\SchemaProvider}.
 *
 * The entity type is the trusted one of the route and nothing else: a body naming another is not heard, and a
 * route of a type the family does not serve is refused. Transport only: the right, the rule of the type and the
 * reading belong to {@see ActivityProvider::getByIdInContext()}, the fields of the answer to the mappers of the
 * contract, which is the one of `crm.activity.get`.
 */
#[DtoType(ActivityDto::class)]
final class EntityActivity extends AbstractController
{
	/** The scopes every route of the family is registered with. */
	public const SCOPES = ['crm'];

	private CheckEnabledProvider $availability;

	/** @var \Closure(int, int, int, string[]): Result */
	private \Closure $reader;

	/**
	 * @param CheckEnabledProvider|null $availability whether the methods are published, the flag of the wave by
	 *        default, as for `crm.activity.get`.
	 * @param (\Closure(int $userId, int $id, int $entityTypeId, string[] $select): Result)|null $reader reads one
	 *        activity for a user in the context of a type and answers the way
	 *        {@see ActivityProvider::getByIdInContext()} does, which is what it is by default.
	 */
	public function __construct(
		?Request $request = null,
		?CheckEnabledProvider $availability = null,
		?\Closure $reader = null,
	)
	{
		parent::__construct($request);

		$this->availability = $availability ?? new ActivityEnabledProvider();
		$this->reader = $reader
			?? static fn(int $userId, int $id, int $entityTypeId, array $select): Result =>
				(new ActivityProvider($userId))->getByIdInContext($id, $entityTypeId, $select)
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
