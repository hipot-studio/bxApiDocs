<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Activity;

use Bitrix\Crm\V2\Internal\Service\Activity\Read\ActivityContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Read\ActivityReader;
use Bitrix\Main\Error;
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

	/** Result data key: the activity read. */
	public const DATA_ACTIVITY = 'activity';

	/** Result data key: the metadata of the files of the activity, null where the select left the files out. */
	public const DATA_FILES = 'files';

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
