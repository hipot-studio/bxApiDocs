<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Service\Marketing\Fact;

use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Entity\Marketing\EvaluationContext;
use Bitrix\Mobile\Internal\Entity\Marketing\FactDefinition;
use Bitrix\Mobile\Internal\Entity\Marketing\FactType;
use Bitrix\Mobile\Internal\Integration\Im\MobileVisitReader;
use Bitrix\Mobile\Internal\Integration\Intranet\EmployeeReader;
use Bitrix\Mobile\Internal\Integration\Intranet\PortalRoleReader;
use Bitrix\Mobile\Internal\Integration\ServiceFactory;
use Bitrix\Mobile\Internal\Service\Marketing\FactSource;

/**
 * Facts about the user himself, each answered for the whole pack in one read of its reader:
 * - "user.type" - the intranet type of the account, "other" for an external account it does not name;
 * - "user.workingEmployee" - active, registered, in a department and not an external account;
 * - "user.firstAdmin" - the creator the settings of the cloud name, unknown outside the cloud;
 * - "user.currentAdmin" - a member of the administrators group whose membership is in force at "now" of
 *   the context, by the database and never by a session;
 * - "user.hasActiveColleague" - another active real employee who finished registration, at or after
 *   the "joinedSince" parameter when the condition gives one;
 * - "action.invitationAccepted" - an invitee of the user by address joined, the invitation sent at or
 *   after the "since" parameter, without which the fact is unknown;
 * - "user.firstMobileActivityAt" and "user.lastAppVisitAt" - when the application came into use and
 *   when it was last opened.
 *
 * A reader that cannot answer - its module is absent - leaves the fact unknown for every user of the
 * pack, never false. A read that fails throws what it met: the evaluator fails the users of the read.
 */
class UserFacts implements FactSource
{
	public const PARAM_SINCE = 'since';
	public const PARAM_JOINED_SINCE = 'joinedSince';

	private const OTHER_TYPE = 'other';

	/** The types Intranet\UserTable names, spelled out: the fact is declared without the module too. */
	private const DECLARED_TYPES = [
		'employee',
		'extranet',
		'shop',
		'sale',
		'imconnector',
		'bot',
		'email',
		self::OTHER_TYPE,
	];

	private readonly EmployeeReader $employees;
	private readonly PortalRoleReader $roles;
	private readonly MobileVisitReader $visits;

	public function __construct(
		?EmployeeReader $employees = null,
		?PortalRoleReader $roles = null,
		?MobileVisitReader $visits = null,
	)
	{
		$this->employees = $employees ?? new EmployeeReader();
		$this->roles = $roles ?? (new ServiceFactory())->createPortalRoleReader();
		$this->visits = $visits ?? new MobileVisitReader();
	}

	public function getDefinitions(): array
	{
		return [
			new FactDefinition('user.type', FactType::EnumValue, true, self::DECLARED_TYPES),
			new FactDefinition('user.workingEmployee', FactType::Boolean, true),
			new FactDefinition('user.firstAdmin', FactType::Boolean, true),
			new FactDefinition('user.currentAdmin', FactType::Boolean, true),
			new FactDefinition('user.hasActiveColleague', FactType::Boolean, true),
			new FactDefinition('action.invitationAccepted', FactType::Boolean, true),
			new FactDefinition('user.firstMobileActivityAt', FactType::Date, true),
			new FactDefinition('user.lastAppVisitAt', FactType::Date, true),
		];
	}

	public function read(string $code, array $params, EvaluationContext $context, array $userIds): array
	{
		$userIds = array_values(array_unique(array_map('intval', $userIds)));

		return match ($code)
		{
			'user.type' => self::answerValues($userIds, $this->readUserTypes($userIds)),
			'user.workingEmployee' => self::answerMembers($userIds, $this->employees->getWorkingEmployees($userIds)),
			'user.firstAdmin' => $this->answerFirstAdmin($userIds),
			'user.currentAdmin' => self::answerMembers($userIds, $this->roles->getAdminIds($userIds, $context->now)),
			'user.hasActiveColleague' => self::answerMembers(
				$userIds,
				$this->employees->getJoinedColleagueOwners($userIds, self::joinedSince($userIds, $params)),
			),
			'action.invitationAccepted' => $this->answerInvitationAccepted($userIds, $params),
			'user.firstMobileActivityAt' => self::answerValues($userIds, $this->visits->getFirstActivities($userIds)),
			'user.lastAppVisitAt' => self::answerValues($userIds, $this->visits->getLastVisits($userIds)),
			default => array_fill_keys($userIds, null),
		};
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, string>|null
	 */
	private function readUserTypes(array $userIds): ?array
	{
		$types = $this->employees->getUserTypes($userIds);
		if ($types === null)
		{
			return null;
		}

		return array_map(
			static fn(string $type): string => in_array($type, self::DECLARED_TYPES, true) ? $type : self::OTHER_TYPE,
			$types,
		);
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, bool|null>
	 */
	private function answerFirstAdmin(array $userIds): array
	{
		$creatorId = $this->roles->getCreatorId();
		if ($creatorId === null)
		{
			return array_fill_keys($userIds, null);
		}

		return array_combine($userIds, array_map(static fn(int $userId): bool => $userId === $creatorId, $userIds));
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, bool|null>
	 */
	private function answerInvitationAccepted(array $userIds, array $params): array
	{
		$since = $params[self::PARAM_SINCE] ?? null;
		if (!$since instanceof DateTime)
		{
			return array_fill_keys($userIds, null);
		}

		return self::answerMembers($userIds, $this->employees->getAcceptedInviters($userIds, $since));
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, DateTime>
	 */
	private static function joinedSince(array $userIds, array $params): array
	{
		$since = $params[self::PARAM_JOINED_SINCE] ?? null;

		return $since instanceof DateTime ? array_fill_keys($userIds, $since) : [];
	}

	/**
	 * Every user of the pack is answered: a member of the list is true, anyone else false, and a list
	 * that could not be read leaves them all unknown.
	 *
	 * @param int[] $userIds
	 * @param int[]|null $members
	 * @return array<int, bool|null>
	 */
	private static function answerMembers(array $userIds, ?array $members): array
	{
		if ($members === null)
		{
			return array_fill_keys($userIds, null);
		}

		$members = array_fill_keys($members, true);

		return array_combine($userIds, array_map(static fn(int $userId): bool => isset($members[$userId]), $userIds));
	}

	/**
	 * Every user of the pack is answered; a user the reader has no value for is unknown.
	 *
	 * @param int[] $userIds
	 * @param array<int, mixed>|null $values
	 * @return array<int, mixed>
	 */
	private static function answerValues(array $userIds, ?array $values): array
	{
		$answers = [];
		foreach ($userIds as $userId)
		{
			$answers[$userId] = $values[$userId] ?? null;
		}

		return $answers;
	}
}
