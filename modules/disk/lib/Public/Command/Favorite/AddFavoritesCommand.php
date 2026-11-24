<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\Favorite;

use Bitrix\Disk\Internal\Service\Favorite\FavoriteToggleService;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\ValidationResult;
use Throwable;

/**
 * Adds the objects of the list to the favorites of the user. Every object is a step of its own, without a common
 * transaction: a failed object keeps the changes of the others and does not stop the objects after it.
 *
 * The result carries `items`: one {@see FavoriteItemResult::toArray()} row per object in the order of the input.
 * Invalid ids make {@see run()} throw a validation exception before anything is written.
 */
class AddFavoritesCommand extends AbstractCommand
{
	public const ERROR_INVALID_OBJECT_IDS = 'INVALID_OBJECT_IDS';
	public const ERROR_TOO_MANY_OBJECTS = 'TOO_MANY_OBJECTS';
	public const MAX_OBJECTS = 100;

	/**
	 * @param int[] $objectIds ids of the rows of the list
	 */
	public function __construct(
		public readonly int $userId,
		public readonly array $objectIds,
	)
	{
	}

	/**
	 * Shared by the removal: both commands take the same selection.
	 *
	 * @param array $objectIds
	 */
	public static function validateObjectIds(array $objectIds): ValidationResult
	{
		$result = new ValidationResult();

		if (count($objectIds) > self::MAX_OBJECTS)
		{
			return $result->addError(new Error(
				'No more than ' . self::MAX_OBJECTS . ' objects are taken at once',
				self::ERROR_TOO_MANY_OBJECTS,
			));
		}

		$normalized = self::normalizeObjectIds($objectIds);
		if ($normalized === [] || count($normalized) !== count($objectIds))
		{
			$result->addError(new Error(
				'Object ids must be unique positive integers',
				self::ERROR_INVALID_OBJECT_IDS,
			));
		}

		return $result;
	}

	/**
	 * @internal shared with {@see RemoveFavoritesCommand}
	 * @param array $objectIds
	 * @return int[] unique positive ids in the given order; a value that is no positive integer is dropped
	 */
	public static function normalizeObjectIds(array $objectIds): array
	{
		$normalized = [];
		foreach ($objectIds as $objectId)
		{
			if (is_string($objectId) && preg_match('/^[1-9]\d*$/', $objectId))
			{
				$objectId = (int)$objectId;
			}

			if (is_int($objectId) && $objectId > 0)
			{
				$normalized[$objectId] = $objectId;
			}
		}

		return array_values($normalized);
	}

	/**
	 * @internal shared with {@see RemoveFavoritesCommand}, not an entry point of its own
	 * @param callable(FavoriteToggleService, int): FavoriteItemResult $step
	 */
	public static function runSteps(string $action, int $userId, array $objectIds, callable $step): Result
	{
		$service = ServiceLocator::getInstance()->get(FavoriteToggleService::class);

		$items = [];
		foreach (self::normalizeObjectIds($objectIds) as $objectId)
		{
			try
			{
				$items[] = $step($service, $objectId)->toArray();
			}
			catch (Throwable $exception)
			{
				LoggerFactory::create(feature: 'favorites')->error(
					'Favorite mark {action} failed for object {objectId} of user {userId}: {exception}',
					[
						'action' => $action,
						'objectId' => $objectId,
						'userId' => $userId,
						'exception' => $exception,
					],
				);
				$items[] = FavoriteItemResult::failed($objectId)->toArray();
			}
		}

		return (new Result())->setData(['items' => $items]);
	}

	protected function validate(): ValidationResult
	{
		return self::validateObjectIds($this->objectIds);
	}

	protected function execute(): Result
	{
		return self::runSteps(
			'add',
			$this->userId,
			$this->objectIds,
			fn (FavoriteToggleService $service, int $objectId): FavoriteItemResult
				=> $service->add($this->userId, $objectId),
		);
	}
}
