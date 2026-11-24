<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Service\Marketing\Fact;

use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Entity\Marketing\EvaluationContext;
use Bitrix\Mobile\Internal\Entity\Marketing\FactDefinition;
use Bitrix\Mobile\Internal\Entity\Marketing\FactType;
use Bitrix\Mobile\Internal\Integration\Crm\DealReader;
use Bitrix\Mobile\Internal\Integration\Im\ColleagueMessageReader;
use Bitrix\Mobile\Internal\Integration\Intranet\InvitationReader;
use Bitrix\Mobile\Internal\Integration\Intranet\WebLoginReader;
use Bitrix\Mobile\Internal\Integration\ServiceFactory;
use Bitrix\Mobile\Internal\Integration\Tasks\TaskReader;
use Bitrix\Mobile\Internal\Service\Marketing\FactSource;

/**
 * What the user did, each fact answered for the whole pack in one read of its reader. The dated actions
 * count from the "since" parameter of the condition, a moment, inclusive and to the second; without it
 * they are unknown. None of them asks a right: the rights are facts of their own.
 *
 * The web login is the moment of the last one, absent for a user who never logged in from the web. The
 * screen "log in from a computer" has no source yet, so its fact is always unknown and the web login
 * does not stand in for it.
 *
 * A reader that cannot answer - its module is absent - leaves the fact unknown for every user of the
 * pack, never false. A read that fails throws what it met: the evaluator fails the users of the read.
 */
final class ActionFacts implements FactSource
{
	public const TASK_CREATED = 'action.taskCreated';
	public const DEAL_CREATED = 'action.dealCreated';
	public const ADDRESSED_INVITATION_SENT = 'action.addressedInvitationSent';
	public const COLLEAGUE_MESSAGE_SENT = 'action.colleagueMessageSent';
	public const WEB_LOGIN_AT = 'action.webLoginAt';
	public const GO_TO_WEB_SCREEN_OPENED = 'action.goToWebScreenOpened';

	public const PARAM_SINCE = 'since';

	private readonly TaskReader $tasks;
	private readonly DealReader $deals;
	private readonly InvitationReader $invitations;
	private readonly ColleagueMessageReader $messages;
	private readonly WebLoginReader $webLogins;

	public function __construct(
		?TaskReader $tasks = null,
		?DealReader $deals = null,
		?InvitationReader $invitations = null,
		?ColleagueMessageReader $messages = null,
		?WebLoginReader $webLogins = null,
	)
	{
		$this->tasks = $tasks ?? new TaskReader();
		$this->deals = $deals ?? (new ServiceFactory())->createDealReader();
		$this->invitations = $invitations ?? new InvitationReader();
		$this->messages = $messages ?? new ColleagueMessageReader();
		$this->webLogins = $webLogins ?? new WebLoginReader();
	}

	public function getDefinitions(): array
	{
		return [
			new FactDefinition(self::TASK_CREATED, FactType::Boolean, true),
			new FactDefinition(self::DEAL_CREATED, FactType::Boolean, true),
			new FactDefinition(self::ADDRESSED_INVITATION_SENT, FactType::Boolean, true),
			new FactDefinition(self::COLLEAGUE_MESSAGE_SENT, FactType::Boolean, true),
			new FactDefinition(self::WEB_LOGIN_AT, FactType::Date, true),
			new FactDefinition(self::GO_TO_WEB_SCREEN_OPENED, FactType::Boolean, true),
		];
	}

	public function read(string $code, array $params, EvaluationContext $context, array $userIds): array
	{
		$userIds = array_values(array_unique(array_map('intval', $userIds)));
		$since = $params[self::PARAM_SINCE] ?? null;

		return match ($code)
		{
			self::TASK_CREATED => $this->readDated(
				$userIds,
				$since,
				fn(array $ids, DateTime $moment): ?array => $this->tasks->getAuthorsSince($ids, $moment),
			),
			self::DEAL_CREATED => $this->readDated(
				$userIds,
				$since,
				fn(array $ids, DateTime $moment): ?array => $this->deals->getAuthorsSince($ids, $moment),
			),
			self::ADDRESSED_INVITATION_SENT => $this->readDated(
				$userIds,
				$since,
				fn(array $ids, DateTime $moment): ?array => $this->invitations->getAddressedSendersSince($ids, $moment),
			),
			self::COLLEAGUE_MESSAGE_SENT => $this->readDated(
				$userIds,
				$since,
				fn(array $ids, DateTime $moment): ?array => $this->messages->getSendersSince($ids, $moment),
			),
			self::WEB_LOGIN_AT => $this->readWebLogin($userIds),
			default => array_fill_keys($userIds, null),
		};
	}

	/**
	 * @param int[] $userIds
	 * @param \Closure(int[], DateTime): (int[]|null) $readActors
	 * @return array<int, bool|null>
	 */
	private function readDated(array $userIds, mixed $since, \Closure $readActors): array
	{
		if ($userIds === [] || !$since instanceof DateTime)
		{
			return array_fill_keys($userIds, null);
		}

		$actorIds = $readActors($userIds, $since);
		if ($actorIds === null)
		{
			return array_fill_keys($userIds, null);
		}

		$actors = array_flip($actorIds);
		$values = [];
		foreach ($userIds as $userId)
		{
			$values[$userId] = isset($actors[$userId]);
		}

		return $values;
	}

	/**
	 * @param int[] $userIds
	 * @return array<int, DateTime|null>
	 */
	private function readWebLogin(array $userIds): array
	{
		$logins = $userIds === [] ? [] : $this->webLogins->getLastLogin($userIds);
		if ($logins === null)
		{
			return array_fill_keys($userIds, null);
		}

		$values = [];
		foreach ($userIds as $userId)
		{
			$values[$userId] = $logins[$userId] ?? null;
		}

		return $values;
	}
}
