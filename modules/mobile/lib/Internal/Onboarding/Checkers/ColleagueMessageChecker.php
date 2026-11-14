<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers;

use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Onboarding\Checkers\Gate\GateContext;
use Bitrix\Mobile\Internal\Onboarding\Checkers\Gate\BulkStepGate;
use Bitrix\Mobile\Internal\Onboarding\Checkers\Gate\StepGate;
use Bitrix\Mobile\Internal\Onboarding\Checkers\Gate\StepGateFactory;
use Bitrix\Mobile\Internal\Onboarding\Checkers\Gate\StepPushDateProvider;
use Bitrix\Mobile\Internal\Onboarding\PushType;

/**
 * "Message" step: the user sent a message in a private 1-1 chat
 * to a real active employee. Counts only when the gate of the step is open - which
 * colleague counts as joined is the gate's business, not the checker's.
 *
 * Without a StepPushDateProvider the gate is judged against the series anchor: the
 * checker degrades to "a colleague appeared after the portal was created" rather than
 * guessing a push date.
 *
 * Only the gate is preloaded. The per-user message query lands on `(AUTHOR_ID, CHAT_ID)` as a `ref`
 * of a few rows, while the same question asked of a selection leads with the chat table and
 * walks every private chat of the portal - and half the time it would be asked for nothing,
 * because the gate closes the step before the table is ever consulted.
 */
class ColleagueMessageChecker implements BulkStepChecker
{
	private StepGate $joinGate;

	/** @var array<int, bool> */
	private array $announcedUsers = [];
	private ?int $announcedSince = null;
	private bool $gatePrepared = false;

	public function __construct(
		?StepGate $joinGate = null,
		private readonly ?StepPushDateProvider $pushDates = null,
	)
	{
		$this->joinGate = $joinGate ?? StepGateFactory::create(null);
	}

	public function preload(array $userIds, DateTime $sinceDate): void
	{
		$userIds = array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0);
		$this->announcedUsers = array_fill_keys($userIds, true);
		$this->announcedSince = $sinceDate->getTimestamp();
		$this->gatePrepared = false;
		if ($this->joinGate instanceof BulkStepGate)
		{
			$this->joinGate->preload([]);
		}
	}

	private function prepareGateSelection(DateTime $sinceDate): void
	{
		if (
			$this->gatePrepared
			|| !$this->joinGate instanceof BulkStepGate
			|| $sinceDate->getTimestamp() !== $this->announcedSince
		)
		{
			return;
		}

		$contexts = [];
		foreach ($this->announcedUsers as $userId => $_)
		{
			$contexts[$userId] = $this->createGateContext($userId, $sinceDate);
		}
		$this->joinGate->preload($contexts);
		$this->gatePrepared = true;
	}

	public function isCompleted(int $userId, DateTime $sinceDate): bool
	{
		if (isset($this->announcedUsers[$userId]))
		{
			$this->prepareGateSelection($sinceDate);
		}

		if ($userId <= 0 || !$this->joinGate->isOpen($userId, $this->createGateContext($userId, $sinceDate)))
		{
			return false;
		}

		if (!Loader::includeModule('im') || !Loader::includeModule('intranet'))
		{
			return false;
		}

		return $this->messageToColleagueExists($userId, $sinceDate);
	}

	/**
	 * The gate of this step waits for the colleague the invite push asked for, so the
	 * invite push is the one whose send date it is judged against.
	 */
	protected function createGateContext(int $userId, DateTime $sinceDate): GateContext
	{
		return new GateContext(
			$sinceDate,
			$this->pushDates?->getStepFirstSentAt($userId, PushType::INVITE),
		);
	}

	protected function messageToColleagueExists(int $userId, DateTime $sinceDate): bool
	{
		return !empty($this->createColleagueMessageQuery($userId, $sinceDate)->fetch());
	}

	/**
	 * The whole question in one bounded query: a message of this user, sent on or after
	 * the bound into a private chat, whose other participant is a colleague. The step only
	 * asks whether such a message exists, so the query stops at the first row instead of
	 * reading the chats of the user and the companions of those chats into PHP.
	 *
	 * The colleague test stays a subquery rather than a join: it belongs to the intranet
	 * entity (USER_TYPE, REAL_USER), while the participant is an im one, and a subquery
	 * keeps each condition in the entity that owns it.
	 */
	protected function createColleagueMessageQuery(int $userId, DateTime $sinceDate): Query
	{
		return MessageTable::query()
			->setSelect(['ID'])
			->where('AUTHOR_ID', $userId)
			->where('DATE_CREATE', '>=', $sinceDate)
			->where('CHAT.TYPE', Chat::IM_TYPE_PRIVATE)
			->whereNot('RELATION.USER_ID', $userId)
			->whereIn('RELATION.USER_ID', $this->createRealEmployeeQuery())
			->setLimit(1)
		;
	}

	/**
	 * The companion must be a colleague, not merely a real user - the same definition the
	 * gate of this step judges the joined colleague by.
	 */
	protected function createRealEmployeeQuery(): Query
	{
		return PortalEmployeeQuery::create();
	}
}
