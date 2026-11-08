<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Avatar\Resource;

use Bitrix\Mail\Internal\Service\Avatar\AvatarImageProcessor;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class AvatarResourceLifecycle
{
	public function __construct(private readonly AvatarResourceStorage $storage)
	{
	}

	public function attach(
		int $candidateResourceId,
		?int $previousResourceId,
		callable $updatePointer,
	): Result
	{
		$previousResourceId = $previousResourceId !== null && $previousResourceId > 0
			? $previousResourceId
			: null
		;
		if ($candidateResourceId <= 0 || $candidateResourceId === $previousResourceId)
		{
			return $this->failure(AvatarImageProcessor::ERROR_UPLOAD_INVALID);
		}

		$resourceIds = array_values(array_filter([$previousResourceId, $candidateResourceId]));
		sort($resourceIds, SORT_NUMERIC);

		return $this->storage->lock(
			$resourceIds,
			function (array $resources) use ($candidateResourceId, $previousResourceId, $updatePointer): Result {
				$candidate = $resources[$candidateResourceId] ?? null;
				if (($candidate['state'] ?? null) !== AvatarResourceStorage::STATE_PENDING)
			{
					return $this->failure(AvatarImageProcessor::ERROR_UPLOAD_INVALID);
				}
				if (
					$previousResourceId !== null
					&& ($resources[$previousResourceId]['state'] ?? null) !== AvatarResourceStorage::STATE_ATTACHED
				)
				{
					return $this->failure(AvatarImageProcessor::ERROR_SAVE_FAILED);
				}

				$result = $this->storage->changeState($candidateResourceId, AvatarResourceStorage::STATE_ATTACHED);
				if (!$result->isSuccess())
				{
					return $result;
				}
				if ($previousResourceId !== null)
				{
					$result = $this->storage->changeState(
						$previousResourceId,
						AvatarResourceStorage::STATE_ORPHANED,
					);
					if (!$result->isSuccess())
					{
						return $result;
					}
				}

				$result = $updatePointer($candidateResourceId);

				return $result instanceof Result
					? $result
					: $this->failure(AvatarImageProcessor::ERROR_SAVE_FAILED)
				;
			}
		);
	}

	public function detach(?int $previousResourceId, callable $updatePointer): Result
	{
		if ($previousResourceId === null || $previousResourceId <= 0)
		{
			$result = $updatePointer(null);

			return $result instanceof Result ? $result : $this->failure(AvatarImageProcessor::ERROR_SAVE_FAILED);
		}

		return $this->storage->lock(
			[$previousResourceId],
			function (array $resources) use ($previousResourceId, $updatePointer): Result {
				$resource = $resources[$previousResourceId] ?? null;
				if (($resource['state'] ?? null) !== AvatarResourceStorage::STATE_ATTACHED)
				{
					return $this->failure(AvatarImageProcessor::ERROR_SAVE_FAILED);
				}

				$result = $this->storage->changeState($previousResourceId, AvatarResourceStorage::STATE_ORPHANED);
				if (!$result->isSuccess())
				{
					return $result;
				}

				$result = $updatePointer(null);

				return $result instanceof Result
					? $result
					: $this->failure(AvatarImageProcessor::ERROR_SAVE_FAILED)
				;
			},
		);
	}

	public function cleanup(int $resourceId, bool $discardPending = false): Result
	{
		if ($resourceId <= 0)
		{
			return new Result();
		}

		$this->storage->markAttempt($resourceId);
		$prepareResult = $this->storage->lock(
			[$resourceId],
			function (array $resources) use ($resourceId, $discardPending): Result {
				$resource = $resources[$resourceId] ?? null;
				if (
					$resource === null
					|| $this->storage->isBound($resourceId)
					|| !$this->storage->canDelete($resourceId, $resource, $discardPending)
				)
				{
					return (new Result())->setData(['delete' => false]);
				}

				if (($resource['state'] ?? null) !== AvatarResourceStorage::STATE_DELETING)
				{
					$result = $this->storage->changeState($resourceId, AvatarResourceStorage::STATE_DELETING);
					if (!$result->isSuccess())
					{
						return $result;
					}
				}

				return (new Result())->setData(['delete' => true]);
			},
		);
		if (!$prepareResult->isSuccess())
		{
			return $prepareResult;
		}

		return ($prepareResult->getData()['delete'] ?? false)
			? $this->storage->delete($resourceId)
			: new Result()
		;
	}

	private function failure(string $code): Result
	{
		return (new Result())->addError(new Error($code, $code));
	}
}
