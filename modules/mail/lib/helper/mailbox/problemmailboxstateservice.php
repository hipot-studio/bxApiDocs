<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Mailbox;

use Bitrix\Mail\Internals\MailEntityOptionsTable;
use Bitrix\Main\Type\DateTime;

final class ProblemMailboxStateService
{
	private const PROBLEM_STATUS_VALUE = 'Y';
	private const SYNC_STATUS_SUCCESS_VALUE = '1';
	private const SYNC_STATUS_FAILURE_VALUE = '0';

	/**
	 * @return array{wasProblem: bool, isProblem: bool, stateChanged: bool}
	 */
	public function setDefaultSyncData(int $mailboxId): array
	{
		return $this->setSyncStatus($mailboxId, true, 0);
	}

	public function setSyncStartedData(int $mailboxId, ?int $time = null): void
	{
		$this->saveSyncStatus($mailboxId, true, $this->normalizeTimestamp($time));
	}

	/**
	 * @return array{wasProblem: bool, isProblem: bool, stateChanged: bool}
	 */
	public function setSyncStatus(int $mailboxId, bool $isSuccess, ?int $time = null): array
	{
		$this->saveSyncStatus($mailboxId, $isSuccess, $this->normalizeTimestamp($time));

		if ($isSuccess)
		{
			return $this->markConnectionRecovered($mailboxId);
		}

		return $this->getCurrentTransitionResult($mailboxId);
	}

	/**
	 * @return array{wasProblem: bool, isProblem: bool, stateChanged: bool}
	 */
	public function registerFailedConnectionAttempt(int $mailboxId, ?DateTime $attemptDate = null): array
	{
		$attemptDate ??= new DateTime();

		$previousAttempt = $this->getMailboxOption(
			$mailboxId,
			MailEntityOptionsTable::CONNECT_ERROR_ATTEMPT_COUNT_PROPERTY_NAME,
		);
		$attemptCount = (int)($previousAttempt['VALUE'] ?? 0);
		$wasProblem = $this->isProblemMailbox($mailboxId);

		if ($attemptCount === 0)
		{
			$attemptCount = 1;
			$this->saveMailboxOption(
				$mailboxId,
				MailEntityOptionsTable::CONNECT_ERROR_ATTEMPT_COUNT_PROPERTY_NAME,
				(string)$attemptCount,
				$attemptDate,
			);
		}
		elseif ($this->shouldIncreaseAttemptCount($previousAttempt['DATE_INSERT'] ?? null, $attemptDate))
		{
			$attemptCount++;
			$this->saveMailboxOption(
				$mailboxId,
				MailEntityOptionsTable::CONNECT_ERROR_ATTEMPT_COUNT_PROPERTY_NAME,
				(string)$attemptCount,
				$attemptDate,
			);
		}

		if ($wasProblem && $attemptCount < MailboxSyncManager::MAX_CONNECTION_ATTEMPTS_BEFORE_UNAVAILABLE)
		{
			$attemptCount = MailboxSyncManager::MAX_CONNECTION_ATTEMPTS_BEFORE_UNAVAILABLE;
			$this->saveMailboxOption(
				$mailboxId,
				MailEntityOptionsTable::CONNECT_ERROR_ATTEMPT_COUNT_PROPERTY_NAME,
				(string)$attemptCount,
				$attemptDate,
			);
		}

		$isProblem = $wasProblem || $attemptCount >= MailboxSyncManager::MAX_CONNECTION_ATTEMPTS_BEFORE_UNAVAILABLE;
		$this->syncProblemStatus($mailboxId, $isProblem, $attemptDate);

		return self::buildTransitionResult($wasProblem, $isProblem);
	}

	/**
	 * @return array{wasProblem: bool, isProblem: bool, stateChanged: bool}
	 */
	public function markConnectionRecovered(int $mailboxId): array
	{
		$wasProblem = $this->isProblemMailbox($mailboxId);

		$this->deleteMailboxOption($mailboxId, MailEntityOptionsTable::CONNECT_ERROR_ATTEMPT_COUNT_PROPERTY_NAME);
		$this->deleteMailboxOption($mailboxId, MailEntityOptionsTable::PROBLEM_STATUS_PROPERTY_NAME);

		return self::buildTransitionResult($wasProblem, false);
	}

	public function isProblemMailbox(int $mailboxId): bool
	{
		$problemStatus = $this->getMailboxOption(
			$mailboxId,
			MailEntityOptionsTable::PROBLEM_STATUS_PROPERTY_NAME,
		);
		if (($problemStatus['VALUE'] ?? null) === self::PROBLEM_STATUS_VALUE)
		{
			return true;
		}

		return $this->getConnectErrorAttemptCount($mailboxId)
			>= MailboxSyncManager::MAX_CONNECTION_ATTEMPTS_BEFORE_UNAVAILABLE;
	}

	/**
	 * @return array{wasProblem: bool, isProblem: bool, stateChanged: bool}
	 */
	private function getCurrentTransitionResult(int $mailboxId): array
	{
		$isProblem = $this->isProblemMailbox($mailboxId);

		return self::buildTransitionResult($isProblem, $isProblem);
	}

	private function getConnectErrorAttemptCount(int $mailboxId): int
	{
		$connectError = $this->getMailboxOption(
			$mailboxId,
			MailEntityOptionsTable::CONNECT_ERROR_ATTEMPT_COUNT_PROPERTY_NAME,
			['VALUE'],
		);

		return (int)($connectError['VALUE'] ?? 0);
	}

	private function saveSyncStatus(int $mailboxId, bool $isSuccess, int $timestamp): void
	{
		$this->saveMailboxOption(
			$mailboxId,
			MailEntityOptionsTable::SYNC_STATUS_PROPERTY_NAME,
			$isSuccess ? self::SYNC_STATUS_SUCCESS_VALUE : self::SYNC_STATUS_FAILURE_VALUE,
			DateTime::createFromTimestamp($timestamp),
		);
	}

	private function syncProblemStatus(int $mailboxId, bool $isProblem, DateTime $attemptDate): void
	{
		if ($isProblem)
		{
			$this->saveMailboxOption(
				$mailboxId,
				MailEntityOptionsTable::PROBLEM_STATUS_PROPERTY_NAME,
				self::PROBLEM_STATUS_VALUE,
				$attemptDate,
			);

			return;
		}

		$this->deleteMailboxOption($mailboxId, MailEntityOptionsTable::PROBLEM_STATUS_PROPERTY_NAME);
	}

	private function saveMailboxOption(
		int $mailboxId,
		string $propertyName,
		string $value,
		DateTime $dateInsert,
	): void
	{
		$primary = [
			'MAILBOX_ID' => $mailboxId,
			'ENTITY_TYPE' => MailEntityOptionsTable::MAILBOX_TYPE_NAME,
			'ENTITY_ID' => $mailboxId,
			'PROPERTY_NAME' => $propertyName,
		];

		if (MailEntityOptionsTable::getCount($this->getMailboxOptionFilter($mailboxId, $propertyName)) > 0)
		{
			MailEntityOptionsTable::update(
				$primary,
				[
					'DATE_INSERT' => $dateInsert,
					'VALUE' => $value,
				],
			);

			return;
		}

		MailEntityOptionsTable::insertIgnore(
			$mailboxId,
			$mailboxId,
			MailEntityOptionsTable::MAILBOX_TYPE_NAME,
			$propertyName,
			$value,
			$dateInsert,
		);
	}

	private function deleteMailboxOption(int $mailboxId, string $propertyName): void
	{
		MailEntityOptionsTable::deleteList($this->getMailboxOptionFilter($mailboxId, $propertyName));
	}

	private function getMailboxOption(
		int $mailboxId,
		string $propertyName,
		array $select = ['DATE_INSERT', 'VALUE'],
	): ?array
	{
		return MailEntityOptionsTable::getRow([
			'select' => $select,
			'filter' => $this->getMailboxOptionFilter($mailboxId, $propertyName),
		]);
	}

	private function getMailboxOptionFilter(int $mailboxId, string $propertyName): array
	{
		return [
			'=MAILBOX_ID' => $mailboxId,
			'=ENTITY_TYPE' => MailEntityOptionsTable::MAILBOX_TYPE_NAME,
			'=ENTITY_ID' => $mailboxId,
			'=PROPERTY_NAME' => $propertyName,
		];
	}

	private function normalizeTimestamp(?int $time): int
	{
		return $time !== null && $time >= 0
			? $time
			: time();
	}

	private function shouldIncreaseAttemptCount(?DateTime $lastAttempt, DateTime $currentAttempt): bool
	{
		if ($lastAttempt === null)
		{
			return true;
		}

		return ($currentAttempt->getTimestamp() - $lastAttempt->getTimestamp())
			>= MailboxSyncManager::MIN_INTERVAL_BETWEEN_CONNECTION_ATTEMPTS;
	}

	/**
	 * @return array{wasProblem: bool, isProblem: bool, stateChanged: bool}
	 */
	private static function buildTransitionResult(bool $wasProblem, bool $isProblem): array
	{
		return [
			'wasProblem' => $wasProblem,
			'isProblem' => $isProblem,
			'stateChanged' => $wasProblem !== $isProblem,
		];
	}
}
