<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Controller;

use Bitrix\Crm\V2\Infrastructure\Rest\Controller\Availability\ActivityEnabledProvider;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Activity\ActivityDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Activity\ActivityCollectionMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Activity\ActivityDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Activity\ActivityListRequestMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Exception\Activity\ActivityListAdminOnlyException;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Activity\GetActivityRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Activity\ListActivityRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Activity\ActivityReadingMapper;
use Bitrix\Crm\V2\Public\Provider\Activity\ActivityProvider;
use Bitrix\Crm\V2\Public\Provider\Activity\Param\ActivityListFilter;
use Bitrix\Crm\V2\Public\Provider\Activity\Param\ActivityListSort;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Request;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Schema\CheckEnabledProvider;

/**
 * The activities of CRM addressed by their own id or listed, whatever they are bound to.
 *
 * Transport only: the rights, the reading, the paging and the masking belong to {@see ActivityProvider}, the
 * terms of a list to {@see ActivityListRequestMapper} and the fields of the answer to the two mappers of the
 * contract.
 */
#[DtoType(ActivityDto::class)]
final class Activity extends RestController
{
	private CheckEnabledProvider $availability;

	/** @var \Closure(int, int, string[]): Result */
	private \Closure $reader;

	/** @var \Closure(int, ActivityListFilter, ActivityListSort, Pager, string[]): Result */
	private \Closure $lister;

	/**
	 * @param CheckEnabledProvider|null $availability whether the methods are published, the flag of the wave by
	 *        default.
	 * @param (\Closure(int $userId, int $id, string[] $select): Result)|null $reader reads one activity for a user
	 *        and answers the way {@see ActivityProvider::getById()} does, which is what it is by default.
	 * @param (\Closure(int $userId, ActivityListFilter $filter, ActivityListSort $sort, Pager $pager,
	 *        string[] $select): Result)|null $lister reads a page of activities for a user and answers the way
	 *        {@see ActivityProvider::getList()} does, which is what it is by default.
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
			?? static fn(int $userId, int $id, array $select): Result => (new ActivityProvider($userId))
				->getById($id, $select)
		;
		$this->lister = $lister
			?? static fn(
				int $userId,
				ActivityListFilter $filter,
				ActivityListSort $sort,
				Pager $pager,
				array $select,
			): Result => (new ActivityProvider($userId))->getList($filter, $sort, $pager, $select)
		;
	}

	/**
	 * `crm.activity.get`: one activity, as the user of the request may read it.
	 *
	 * An activity that does not exist and one the user may not read get the same answer, the standard not found
	 * of REST.
	 */
	#[Description(
		'Returns one activity the caller may read. An activity that does not exist and one the caller may not'
		. ' read get the same answer: `BITRIX_REST_V3_EXCEPTION_ENTITYNOTFOUNDEXCEPTION`.'
	)]
	public function getAction(GetActivityRequest $request): GetResponse
	{
		$select = ActivityReadingMapper::selectedNames($request->select);
		$nested = ActivityReadingMapper::nestedNames($request->select);
		$result = ($this->reader)((int)$this->getCurrentUser()?->getId(), $request->id, $select);
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
	 * `crm.activity.list`: a page of the activities the filter selects, in the order of the sort, with no check
	 * of the rights to a single activity. The page carries no total.
	 *
	 * Only an administrator of the portal may call it; anybody else gets the access denied of REST that says so.
	 */
	#[Description(
		'Returns a page of all activities of the portal under the filter and the sort; the page carries no total.'
		. ' The default page size (`limit`) is 50 and the maximum is 200; a larger `limit` is cut to 200.'
		. ' The method is for an administrator of the portal only: the caller must be a member of the'
		. ' "Administrators" group (group 1). A CRM administrator or a user with CRM roles who is not an'
		. ' administrator of the portal is refused with `CRM_ACTIVITY_LIST_ADMIN_ONLY` (HTTP 403).'
		. ' The right to a single activity is not'
		. ' checked: the page may include activities that `crm.activity.get` answers as not found for the same'
		. ' administrator, such as the ones whose provider forbids reading them (documents and call lists).'
		. ' An activity without a deadline answers `deadlineTime` as null, but the storage keeps "no deadline"'
		. ' in two ways, so a sort by `deadlineTime` places such activities at both ends of the list: some'
		. ' before the activities with a deadline and the rest after them. To get all of them, filter'
		. ' by `deadlineTime` equal to null.'
	)]
	public function listAction(ListActivityRequest $request): ListResponse
	{
		$select = ActivityReadingMapper::selectedNames($request->select);
		$nested = ActivityReadingMapper::nestedNames($request->select);
		$mapper = new ActivityListRequestMapper();
		$result = ($this->lister)(
			(int)$this->getCurrentUser()?->getId(),
			$mapper->mapFilter($request->filter),
			$mapper->mapSort($request->order),
			$mapper->mapPager($request->pagination),
			$select,
		);
		if (!$result->isSuccess())
		{
			throw self::listRefusalOf($result);
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
	 * An unpublished method is refused before its request is parsed, with the answer REST gives a method it
	 * knows as disabled. The check lives here because the `Enabled` attribute of REST does not accept a
	 * provider yet.
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
	 * A list knows one refusal of its contract, that of access. Any other is not a REST exception, so REST answers
	 * it as its internal error and keeps the message for the log.
	 */
	private static function listRefusalOf(Result $result): \Throwable
	{
		$error = $result->getErrors()[0] ?? null;
		if ($error?->getCode() === ActivityProvider::ERROR_ACCESS_DENIED)
		{
			return new ActivityListAdminOnlyException();
		}

		return new \RuntimeException($error?->getMessage() ?? 'The activity provider refused without an error.');
	}
}
