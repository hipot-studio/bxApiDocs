<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\User;
use Bitrix\BIConnector\Integration\Superset\Repository\SupersetUserRepository;
use Bitrix\Main\Result;

/**
 * The Superset account a builder operation passes to the integrator for the actor, worded as
 * BiToolBehaviour::findActAsUser() and requireSupersetUser() of the tools, so both paths go to Superset as
 * the same person.
 *
 * The account is taken for Actor::userId only: never for the global user and never the service account in place
 * of an employee. A refusal comes before any request to Superset.
 *
 * Data of a successful result: `user` - the account, or null when the request goes under the service account.
 */
final class SupersetIdentity
{
	public const NO_ACCOUNT_MESSAGE = 'A Superset user is required to read BI data.';

	/** @var \Closure(int): ?User */
	private readonly \Closure $findSupersetUser;

	/**
	 * @param (\Closure(int): ?User)|null $findSupersetUser Superset account of a portal user; the repository
	 *     by default.
	 */
	public function __construct(?\Closure $findSupersetUser = null)
	{
		$this->findSupersetUser = $findSupersetUser
			?? static fn(int $userId): ?User => (new SupersetUserRepository())->getById($userId)
		;
	}

	/**
	 * A read: while the table permission axis is on it runs as the actor, who has to have an account.
	 * Without the axis nobody is passed and the read goes under the service account, as before act-as.
	 */
	public function forRead(int $userId): Result
	{
		if (!Feature::isTablePermissionsEnabled())
		{
			return (new Result())->setData(['user' => null]);
		}

		return $this->forWrite($userId);
	}

	/**
	 * A creation or SQL Lab: the account of the actor is passed in both states of the axis. It owns a created
	 * dataset and keys the limiter and the audit; the integrator runs the request as the actor only with the axis.
	 */
	public function forWrite(int $userId): Result
	{
		$user = $userId > 0 ? ($this->findSupersetUser)($userId) : null;
		if ($user === null)
		{
			return Refusal::error(FailureCode::AccessDenied, self::NO_ACCOUNT_MESSAGE);
		}

		return (new Result())->setData(['user' => $user]);
	}
}
