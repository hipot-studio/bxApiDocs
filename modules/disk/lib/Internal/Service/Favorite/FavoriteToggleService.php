<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Favorite;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Internal\Repository\FavoriteMarkRepository;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Disk\Public\Command\Favorite\FavoriteItemResult;
use Bitrix\Main\DB\SqlException;
use Psr\Log\LoggerInterface;

/**
 * Adds or removes the favorite mark of one object of the list for a user. The object is the row the user sees:
 * a link is marked by its own id, never by the id of its original.
 */
final class FavoriteToggleService
{
	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly FavoriteMarkRepository $repository,
		private readonly FavoriteAccessReconciler $reconciler,
		?LoggerInterface $logger = null,
	)
	{
		$this->logger = $logger ?? LoggerFactory::create(feature: 'favorites');
	}

	public function add(int $userId, int $objectId): FavoriteItemResult
	{
		try
		{
			$object = BaseObject::loadById($objectId);
			if (!$object instanceof BaseObject)
			{
				$this->repository->remove($userId, $objectId);

				return FavoriteItemResult::notFound($objectId);
			}

			if (!$this->reconciler->isActiveAndReadable($userId, $object))
			{
				$this->repository->remove($userId, $objectId);

				return FavoriteItemResult::accessDenied($objectId);
			}

			return $this->repository->add($userId, $objectId)
				? FavoriteItemResult::changed($objectId, true)
				: FavoriteItemResult::unchanged($objectId, true)
			;
		}
		catch (SqlException $exception)
		{
			return $this->fail('add', $userId, $objectId, $exception);
		}
	}

	/**
	 * The own mark is removed whatever the access is; the access only decides what the answer says.
	 */
	public function remove(int $userId, int $objectId): FavoriteItemResult
	{
		try
		{
			$isRemoved = $this->repository->remove($userId, $objectId);

			$object = BaseObject::loadById($objectId);
			if (!$object instanceof BaseObject)
			{
				return FavoriteItemResult::notFound($objectId);
			}

			if (!$this->reconciler->isActiveAndReadable($userId, $object))
			{
				return FavoriteItemResult::accessDenied($objectId);
			}

			return $isRemoved
				? FavoriteItemResult::changed($objectId, false)
				: FavoriteItemResult::unchanged($objectId, false)
			;
		}
		catch (SqlException $exception)
		{
			return $this->fail('remove', $userId, $objectId, $exception);
		}
	}

	private function fail(string $action, int $userId, int $objectId, SqlException $exception): FavoriteItemResult
	{
		$this->logger->error('Favorite mark {action} failed for object {objectId} of user {userId}: {message}', [
			'action' => $action,
			'objectId' => $objectId,
			'userId' => $userId,
			'message' => $exception->getMessage(),
		]);

		return FavoriteItemResult::failed($objectId);
	}
}
