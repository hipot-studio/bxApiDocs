<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Activity;

use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Service\Activity\Read\ActivityContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Read\ContextActivityListReader;
use Bitrix\Crm\V2\Internal\Service\Activity\Read\ActivityFilter;
use Bitrix\Crm\V2\Internal\Service\Activity\Read\ActivityListReader;
use Bitrix\Crm\V2\Internal\Service\Activity\Read\ActivityReader;
use Bitrix\Crm\V2\Internal\Service\Activity\Read\ActivitySort;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Crm\V2\Public\Provider\Activity\Param\ActivityListFilter;
use Bitrix\Crm\V2\Public\Provider\Activity\Param\ActivityListSort;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Result;

/**
 * Read-side boundary of the activities for REST: the one place the transport reads an activity through.
 *
 * The access user is fixed by the constructor and is always explicit - there is no fallback to the current
 * user and no way to switch the check off. An anonymous user (`0` and below) reads nothing.
 *
 * Every method answers with a {@see Result}: on success the activity under {@see DATA_ACTIVITY} and the
 * metadata of its files under {@see DATA_FILES}; on failure exactly one error, {@see ERROR_NOT_FOUND}. An
 * activity that does not exist and an activity the user may not read are one answer.
 *
 * The list is the administrator's of the portal alone: for any other user {@see getList()} fails with
 * {@see ERROR_ACCESS_DENIED} and reads nothing. The list of one parent, {@see getListInContext()}, is anybody's
 * who may read the parent, and answers what the contextual get would answer for each of its activities.
 *
 * The payload carries the internal read model - {@see \Bitrix\Crm\V2\Internal\Entity\Activity\Activity} and
 * {@see \Bitrix\Crm\V2\Internal\Entity\Activity\ValueObject\ActivityFile} - which the mappers of the REST
 * contract take. An activity has no public model yet, so their shape is no promise to other modules.
 */
final class ActivityProvider
{
	/** The activity does not exist, or the access user may not read it. */
	public const ERROR_NOT_FOUND = 'CRM_ACTIVITY_NOT_FOUND';

	/**
	 * Not localized on purpose: REST answers {@see ERROR_NOT_FOUND} with
	 * {@see \Bitrix\Rest\V3\Exception\EntityNotFoundException}, which carries a phrase of its own.
	 */
	public const MESSAGE_NOT_FOUND = 'Activity not found';

	/** The access user is not an administrator of the portal, the only one who may read the list. */
	public const ERROR_ACCESS_DENIED = 'CRM_ACTIVITY_ACCESS_DENIED';

	/**
	 * Not localized on purpose: REST answers {@see ERROR_ACCESS_DENIED} with
	 * {@see \Bitrix\Rest\V3\Exception\AccessDeniedException}, which carries a phrase of its own.
	 */
	public const MESSAGE_ACCESS_DENIED = 'Access denied';

	/** Result data key: the activity read. */
	public const DATA_ACTIVITY = 'activity';

	/** Result data key: the metadata of the files of the activity, null where the select left the files out. */
	public const DATA_FILES = 'files';

	/** Result data key of a list: its items, each with {@see DATA_ACTIVITY} and {@see DATA_FILES}. */
	public const DATA_ITEMS = 'items';

	/** The most activities one page of {@see getList()} holds. */
	public const MAX_LIMIT = 200;

	private readonly ActivityReader $reader;

	public function __construct(
		private readonly int $userId,
	)
	{
		$this->reader = new ActivityReader();
	}

	/**
	 * A single activity by its id, as the access user may read it.
	 *
	 * @param string[] $select names of the published fields the caller answers; an empty one means the set a
	 *        read answers by default. It decides what is loaded: a collection it leaves out stays null on the
	 *        activity.
	 */
	public function getById(int $id, array $select = []): Result
	{
		$reading = $this->reader->get($this->userId, $id, $select);
		if (!$reading->isSuccess())
		{
			return self::notFound();
		}

		return self::answerOf($reading->getData());
	}

	/**
	 * A single activity by its id, as the access user may read it, where it is bound to at least one object of
	 * the entity type: the right of {@see getById()}, then the type, and only then what the select names. No owner
	 * is named and no right on an object of the type is checked. An activity bound to no object of the type is not
	 * found, as an absent or a forbidden one is.
	 *
	 * @param int $entityTypeId the type the caller reads in, a trusted one: it is not validated here.
	 * @param string[] $select as for {@see getById()}, with the same answer.
	 */
	public function getByIdInContext(int $id, int $entityTypeId, array $select = []): Result
	{
		$context = new ActivityContext($entityTypeId);

		$reading = $this->reader->get($this->userId, $id, $select, $context);
		if (!$reading->isSuccess())
		{
			return self::notFound();
		}

		$data = $reading->getData();
		$context->fitToSelect($data[ActivityReader::DATA_ACTIVITY], $select);

		return self::answerOf($data);
	}

	/**
	 * A page of the activities the filter selects, in the order of the sort: the offset and the limit are those of
	 * the statement, and no right to a single activity is checked. No total is counted. A limit above
	 * {@see MAX_LIMIT} is cut to it.
	 *
	 * Only an administrator of the portal may read the list. Anybody else is refused with
	 * {@see ERROR_ACCESS_DENIED} before the storage of the activities is asked anything.
	 *
	 * @param string[] $select as {@see self::getById()} takes it
	 * @return Result success with the page under {@see DATA_ITEMS}, or exactly one error {@see ERROR_ACCESS_DENIED}
	 * @throws ArgumentException the filter or the sort names a field a list cannot be narrowed or sorted by.
	 */
	public function getList(
		ActivityListFilter $filter,
		ActivityListSort $sort,
		Pager $pager,
		array $select = [],
	): Result
	{
		if (!Container::getInstance()->getUserPermissions($this->userId)->isAdmin())
		{
			return (new Result())->addError(new Error(self::MESSAGE_ACCESS_DENIED, self::ERROR_ACCESS_DENIED));
		}

		$binding = $filter->hasBinding()
			? new ActivityBinding(
				OwnerType::fromId((int)$filter->getBindingEntityTypeId()),
				(int)$filter->getBindingEntityId(),
			)
			: null
		;
		$items = (new ActivityListReader())->list(
			$this->userId,
			new ActivityFilter($binding, $filter->getConditions()),
			new ActivitySort($sort->getOrder()),
			min($pager->getLimit(), self::MAX_LIMIT),
			$pager->getOffset(),
			$select,
		);

		return (new Result())->setData([
			self::DATA_ITEMS => array_map(
				static fn(array $item): array => [
					self::DATA_ACTIVITY => $item[ActivityListReader::ITEM_ACTIVITY],
					self::DATA_FILES => $item[ActivityListReader::ITEM_FILES],
				],
				$items,
			),
		]);
	}

	/**
	 * A page of the activities bound to the parent, each as {@see getByIdInContext()} reads it for the access user:
	 * the parent is checked first, and an activity its provider refuses neither shortens the page nor shifts the
	 * offset. The order, the offset and the limit are those of {@see getList()}, as is the answer. A limit above
	 * {@see MAX_LIMIT} is cut to it.
	 *
	 * The parent is named by the arguments alone. It is found and readable, or the answer is
	 * {@see ERROR_NOT_FOUND} before an activity is read: a type without a factory, an object that does not exist or
	 * lies in the recycle bin, and an object the user may not read are one answer, the administrator's included.
	 *
	 * @param int $entityTypeId the type of the parent, a trusted one: it is not validated here.
	 * @param ActivityListFilter $filter the conditions alone: the parent is the binding of the list.
	 * @param string[] $select as {@see getById()} takes it
	 * @return Result success with the page under {@see DATA_ITEMS}, or exactly one error {@see ERROR_NOT_FOUND}
	 * @throws ArgumentException the filter names a binding, or a field a list cannot be narrowed or sorted by.
	 */
	public function getListInContext(
		int $entityTypeId,
		int $ownerId,
		ActivityListFilter $filter,
		ActivityListSort $sort,
		Pager $pager,
		array $select = [],
	): Result
	{
		if ($filter->hasBinding())
		{
			throw new ArgumentException('A contextual list is bound to its parent alone.', 'filter');
		}
		if (!$this->canReadParent($entityTypeId, $ownerId))
		{
			return self::notFound();
		}

		$items = (new ContextActivityListReader())->list(
			$this->userId,
			new ActivityBinding(OwnerType::fromId($entityTypeId), $ownerId),
			new ActivityFilter(null, $filter->getConditions()),
			new ActivitySort($sort->getOrder()),
			min($pager->getLimit(), self::MAX_LIMIT),
			$pager->getOffset(),
			$select,
		);

		return (new Result())->setData([
			self::DATA_ITEMS => array_map(
				static fn(array $item): array => [
					self::DATA_ACTIVITY => $item[ContextActivityListReader::ITEM_ACTIVITY],
					self::DATA_FILES => $item[ContextActivityListReader::ITEM_FILES],
				],
				$items,
			),
		]);
	}

	/**
	 * Whether the parent exists and the access user may read it: the right a read of an activity asks of its owner.
	 */
	private function canReadParent(int $entityTypeId, int $ownerId): bool
	{
		if ($this->userId <= 0 || $ownerId <= 0)
		{
			return false;
		}

		$container = Container::getInstance();
		$factory = $container->getFactory($entityTypeId);
		if ($factory === null)
		{
			return false;
		}

		$row = $factory->getDataClass()::getList([
			'select' => [Item::FIELD_NAME_ID],
			'filter' => ['=' . Item::FIELD_NAME_ID => $ownerId],
			'limit' => 1,
		])->fetch();
		if (!$row)
		{
			return false;
		}

		return $container->getUserPermissions($this->userId)->item()->canRead($entityTypeId, $ownerId);
	}

	private static function notFound(): Result
	{
		return (new Result())->addError(new Error(self::MESSAGE_NOT_FOUND, self::ERROR_NOT_FOUND));
	}

	/**
	 * @param array<string, mixed> $data the data of a successful reading of {@see ActivityReader}.
	 */
	private static function answerOf(array $data): Result
	{
		return (new Result())->setData([
			self::DATA_ACTIVITY => $data[ActivityReader::DATA_ACTIVITY],
			self::DATA_FILES => $data[ActivityReader::DATA_FILES],
		]);
	}
}
