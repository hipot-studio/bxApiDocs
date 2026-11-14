<?php

namespace Bitrix\Disk\Security;

use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\Internals\Error\ErrorCollection;
use Bitrix\Disk\Internals\Error\IErrorable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Type\DateTime;

abstract class SecurityContext implements IErrorable
{
	const GUEST_USER = 'guest_user';

	/** @var  ErrorCollection */
	protected $errorCollection;
	/** @var  int */
	protected $userId;

	/**
	 * Creates new instance of SecurityContext specific by user.
	 * @param \CUser|int $user User.
	 */
	public function __construct($user)
	{
		$this->userId = static::GUEST_USER;

		if ($user instanceof \CUser)
		{
			if($user->isAuthorized())
			{
				$this->userId = $user->getId();
			}
		}
		elseif ($user instanceof CurrentUser)
		{
			$this->userId = (int)$user->getId();
		}
		elseif ((int)$user > 0)
		{
			$this->userId = (int)$user;
		}

		$this->errorCollection = new ErrorCollection;
	}

	public function getUserId()
	{
		return $this->userId;
	}

	/**
	 * @param $targetId
	 * @return bool
	 */
	abstract public function canAdd($targetId);

	/**
	 * @param $objectId
	 * @return bool
	 */
	abstract public function canChangeRights($objectId);

	/**
	 * @param $objectId
	 * @return bool
	 */
	abstract public function canChangeSettings($objectId);

	/**
	 * @param $objectId
	 * @return bool
	 */
	abstract public function canCreateWorkflow($objectId);

	/**
	 * @param $objectId
	 * @return bool
	 */
	abstract public function canDelete($objectId);

	/**
	 * @param $objectId
	 * @return bool
	 */
	abstract public function canMarkDeleted($objectId);

	/**
	 * @param $objectId
	 * @param $targetId
	 * @return bool
	 */
	abstract public function canMove($objectId, $targetId);

	/**
	 * @param $objectId
	 * @return bool
	 */
	abstract public function canRead($objectId);

	/**
	 * @param $objectId
	 * @return bool
	 */
	public function canDownload($objectId)
	{
		return $this->canRead($objectId);
	}

	/**
	 * @param $objectId
	 * @return bool
	 */
	abstract public function canRename($objectId);

	/**
	 * @param $objectId
	 * @return bool
	 */
	abstract public function canRestore($objectId);

	/**
	 * @param $objectId
	 * @return bool
	 */
	abstract public function canShare($objectId);

	/**
	 * @param $objectId
	 * @return bool
	 */
	abstract public function canUpdate($objectId);

	/**
	 * @param $objectId
	 * @return bool
	 */
	abstract public function canStartBizProc($objectId);

	/**
	 * @param $columnObjectId
	 * @return string
	 */
	abstract public function getSqlExpressionForList($columnObjectId, $columnCreatedBy);

	/**
	 * Returns rights check expression for a list where every candidate row is a root object (PARENT_ID IS NULL).
	 * Calling it on a list that may contain non-root objects is not allowed: the expression is free to rely
	 * on the fact that the nearest rights checkpoint of a root object is the object itself.
	 *
	 * @param string $columnObjectId
	 * @param string $columnCreatedBy
	 * @return string
	 */
	public function getSqlExpressionForRootObjectList(string $columnObjectId, string $columnCreatedBy): string
	{
		return $this->getSqlExpressionForList($columnObjectId, $columnCreatedBy);
	}

	/**
	 * Returns rights check expression for a list bounded by the closure subtree of the given root.
	 * Calling it is allowed only when every candidate row of the query provably lies inside that subtree,
	 * the root itself included: the expression is free to resolve the rights of the whole subtree at once.
	 * A filter on PATH_CHILD.PARENT_ID = <root> satisfies it, a filter on STORAGE_ID does not, and the root
	 * given here must be the very same expression the closure filter of the query stands on.
	 *
	 * @param string $columnObjectId
	 * @param string $columnCreatedBy
	 * @param int $rootObjectId
	 * @param DateTime|null $candidatesChangedSince the lower bound the query puts on SYNC_UPDATE_TIME of
	 *        its candidates; the epoch when it puts none. Passing it declares the candidate set, and the
	 *        expression is then free to weigh which of its two forms is cheaper on a set that size. Left
	 *        out, it weighs nothing and answers as it did before the weighing existed. Either way the
	 *        value is read by that choice alone and never by the expression it returns, so a bound wider
	 *        or narrower than the filter of the query costs a worse choice of form and nothing besides:
	 *        both forms select the same rows in the same order.
	 * @return string
	 */
	public function getSqlExpressionForSubtreeList(
		string $columnObjectId,
		string $columnCreatedBy,
		int $rootObjectId,
		?DateTime $candidatesChangedSince = null,
	): string
	{
		return $this->getSqlExpressionForList($columnObjectId, $columnCreatedBy);
	}

	/**
	 * Load operations if we show one level with objects.
	 * @param $parentObjectId
	 * @return void
	 */
	public function preloadOperationsForChildren($parentObjectId, bool $shouldPreloadDownloadAccess = false)
	{
	}

	public function preloadOperationsForSpecifiedObjects(
		$parentObjectId,
		array $objectIds,
		bool $shouldPreloadDownloadAccess = false,
	)
	{
	}

	public function preloadOperationsForObjects(array $objects): void
	{
	}

	public function preloadOperationsForObjectIds(array $objectIds): void
	{
	}

	public function preloadUnifiedLinkAccessLevelsForObjects(array $objects): void
	{
	}

	/**
	 * @return Error[]
	 */
	public function getErrors()
	{
		return $this->errorCollection->toArray();
	}

	/**
	 * @inheritdoc
	 */
	public function getErrorsByCode($code)
	{
		return $this->errorCollection->getErrorsByCode($code);
	}

	/**
	 * @inheritdoc
	 */
	public function getErrorByCode($code)
	{
		return $this->errorCollection->getErrorByCode($code);
	}
}
