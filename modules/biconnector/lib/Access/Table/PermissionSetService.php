<?php

namespace Bitrix\BIConnector\Access\Table;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Service\TableGroupMembershipService;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Repository\SupersetUserRepository;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Error;

/**
 * Answers the permission set command of the public BI data endpoint.
 *
 * Owns the shape of the answer and the error codes; the decision about what is allowed belongs to the
 * access subsystem, and this service only asks it. Three consumers in three code bases read this shape,
 * so it is a contract and not an implementation detail.
 */
final class PermissionSetService
{
	public const ERROR_BAD_REQUEST = 'BAD_REQUEST';
	public const ERROR_PERMISSIONS_UNAVAILABLE = 'PERMISSIONS_UNAVAILABLE';

	/**
	 * Operation of the answer -> action of the access subsystem.
	 *
	 * The keys are the contract, the values belong to the permission storage, and the correspondence is
	 * written down here on purpose instead of being derived from the dictionary:
	 * derivation breaks silently the first time an operation is added on either side.
	 */
	private const OPERATION_ACTIONS = [
		'view' => ActionDictionary::ACTION_BIC_TABLE_VIEW,
		'edit' => ActionDictionary::ACTION_BIC_TABLE_EDIT,
		'export' => ActionDictionary::ACTION_BIC_TABLE_EXPORT,
	];

	private const LOGIN_PATTERN = '/^[0-9a-f]{16}$/';

	private const LOGIN_LOG_LENGTH = 64;

	private SupersetUserRepository $userRepository;

	public function __construct(?SupersetUserRepository $userRepository = null)
	{
		$this->userRepository = $userRepository ?? new SupersetUserRepository();
	}

	/**
	 * Builds the answer for the permission set command.
	 *
	 * @param string|null $login Superset account login, as it arrives in the query string.
	 * @return array Ready to be encoded as the endpoint answer.
	 */
	public function getResponse(?string $login): array
	{
		try
		{
			// The flag goes before the login: with the axis off the answer does not depend on who asks.
			// A consumer that passes the login another way than this portal reads it must still learn that
			// the axis is off, or it gets BAD_REQUEST and closes the data the axis does not restrict.
			if (!Feature::isTablePermissionsEnabled())
			{
				// No lists at all, not empty ones. "The axis is off" and "nothing is allowed" have to stay
				// different answers: a cached empty set is ambiguous, and a consumer that cannot tell them
				// apart resolves the ambiguity by opening the data.
				return ['axis_enabled' => false];
			}

			if ($login === null || $login === '')
			{
				// Only a broken caller omits the login, so this is an error and not an empty set.
				return ['error' => self::ERROR_BAD_REQUEST];
			}

			$userId = $this->resolveUserId($login);
			if ($userId === null)
			{
				// The same answer as "known employee with no rights", on purpose - the consumer has no use
				// for the difference. The journal does: a login that does not resolve is an identity
				// incident, and merged into one record it would look like normal work.
				Logger::logWarning(
					[new Error('Permission set requested for an unknown Superset login')],
					['login' => self::loginForLog($login)],
				);

				return $this->buildSet(self::emptySet());
			}

			return $this->buildSet($this->resolveSet($userId));
		}
		catch (\Throwable $exception)
		{
			// Never a fatal in the middle of the JSON, and never an empty set: the consumer has to tell a
			// failure from "nothing is allowed", or it leaves the data open when the rights are missing.
			Logger::logErrors(
				[new Error('Permission set is unavailable: ' . $exception->getMessage())],
				['login' => self::loginForLog($login)],
			);

			return ['error' => self::ERROR_PERMISSIONS_UNAVAILABLE];
		}
	}

	/**
	 * Resolves the allowed table codes per operation for the employee.
	 *
	 * The access subsystem answers in group ids, the consumer speaks table codes, so the answer is
	 * translated through the membership service. A group gives exactly the codes of its binding rows,
	 * so a code bound to no group is allowed to nobody.
	 *
	 * Deliberately no check for "the rights are not enforced here": the rule answers with every group
	 * both for an administrator and on a portal with the rights feature off, and that is the meaning of
	 * the axis being on with nothing to enforce - overriding it here would invent a second answer to a
	 * question the subsystem already answers.
	 *
	 * @param int $userId
	 * @return array<string, list<string>>
	 * @throws \RuntimeException When an operation of the axis is not served by the access rules.
	 */
	private function resolveSet(int $userId): array
	{
		$controller = new AccessController($userId);

		$set = [];
		$codesByGroups = [];
		foreach (self::OPERATION_ACTIONS as $operation => $action)
		{
			$allowedGroupIds = $controller->getAllowedGroupValue($action);
			if ($allowedGroupIds === null)
			{
				// Not an absence of rights but an absence of the rule: the action is not served by the
				// table rule at all. An empty list here would be read as "nothing is allowed" and a broken
				// wiring would look like ordinary work for as long as nobody compares it with the matrix.
				throw new \RuntimeException("Action '{$action}' is not served by the table permission rule");
			}

			// The operations of the axis usually resolve to the same groups, and the catalog behind them
			// costs the same every time, so it is unfolded once per distinct set of groups.
			$groupsKey = self::groupsKey($allowedGroupIds);
			if (!isset($codesByGroups[$groupsKey]))
			{
				$tableCodes = TableGroupMembershipService::getTableCodesByGroupIds($allowedGroupIds);
				// The order of the groups is not the order of the rights: sorted, the answer is a function of
				// what is allowed, and the consumer does not re-cache a set that did not change.
				sort($tableCodes, SORT_STRING);

				$codesByGroups[$groupsKey] = $tableCodes;
			}

			$set[$operation] = $codesByGroups[$groupsKey];
		}

		return $set;
	}

	/**
	 * @param array $groupIds
	 * @return string Order-independent key of the group set.
	 */
	private static function groupsKey(array $groupIds): string
	{
		$ids = array_map('intval', $groupIds);
		sort($ids, SORT_NUMERIC);

		return implode(',', $ids);
	}

	/**
	 * @param string $login
	 * @return int|null Portal user id, or null when the login is unknown or malformed.
	 */
	private function resolveUserId(string $login): ?int
	{
		if (!preg_match(self::LOGIN_PATTERN, $login))
		{
			// A malformed login is an unknown login, not an error: it names nobody either way, and an error
			// would be read as "the rights did not arrive" and leave the data open.
			return null;
		}

		return $this->userRepository->getUserIdByLogin($login);
	}

	/**
	 * @param array<string, list<string>> $tables
	 * @return array
	 */
	private function buildSet(array $tables): array
	{
		return [
			'axis_enabled' => true,
			'tables' => $tables,
		];
	}

	/**
	 * The login comes from the query string of a branch deliberately left outside the load protection,
	 * so what reaches the journal is bounded here and not at the caller.
	 *
	 * @param string|null $login
	 * @return string
	 */
	private static function loginForLog(?string $login): string
	{
		$login = (string)$login;
		if (mb_strlen($login) <= self::LOGIN_LOG_LENGTH)
		{
			return $login;
		}

		return mb_substr($login, 0, self::LOGIN_LOG_LENGTH) . '...[truncated]';
	}

	/**
	 * @return array<string, list<string>>
	 */
	private static function emptySet(): array
	{
		return array_fill_keys(array_keys(self::OPERATION_ACTIONS), []);
	}
}
