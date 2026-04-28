<?php

namespace Bitrix\Ldap\Sync;

use Bitrix\Ldap\Internal\Models\SyncSessionTable;
use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\Type\DateTime;

class SessionManager
{
	public function tryStart(int $serverId): void
	{
		if (!$this->hasRunningSession($serverId))
		{
			$this->start($serverId);
		}
	}

	public function hasRunningSession(int $serverId): bool
	{
		$query = SyncSessionTable::query()
			->setSelect(['ID'])
			->addOrder('ID', 'DESC')
			->where('SERVER_ID', $serverId)
			->whereNotIn('STATE', [State::Finished->value, State::Failure->value])
			->setLimit(1)
			->exec();

		return $query->getSelectedRowsCount() > 0;
	}

	protected function start(int $serverId): AddResult
	{
		$now = new DateTime();

		$addResult = SyncSessionTable::add([
			'SERVER_ID' => $serverId,
			'STATE' => State::Idle->value,
			'STARTED_AT' => $now,
			'UPDATED_AT' => $now,
		]);
		if ($addResult->isSuccess())
		{
			$delay = 0;
			$args = [$addResult->getId()];
			Stepper::bind($delay, $args);
		}

		return $addResult;
	}

	public function finish(int $sessionId, string $message = ''): UpdateResult
	{
		return $this->close($sessionId, State::Finished, $message);
	}

	public function failure(int $sessionId, string $message = ''): UpdateResult
	{
		return $this->close($sessionId, State::Failure, $message);
	}

	protected function close(int $sessionId, State $state, string $message = ''): UpdateResult
	{
		$now = new DateTime();
		$fields = [
			'STATE' => $state->value,
			'PROGRESS' => '',
			'UPDATED_AT' => $now,
			'FINISHED_AT' => $now,
		];

		if ($message !== '')
		{
			$fields['MESSAGE'] = $message;
		}

		return SyncSessionTable::update($sessionId, $fields);
	}

	public function step(int $sessionId, State $state, array $progress): UpdateResult
	{
		return SyncSessionTable::update($sessionId, [
			'STATE' => $state->value,
			'PROGRESS' => $progress,
			'UPDATED_AT' => new DateTime(),
		]);
	}

	public function getSessionById(int $sessionId): ?Session
	{
		$row = SyncSessionTable::getRowById($sessionId);

		return $row ? new Session($row) : null;
	}

	public function killRunningSessions(): void
	{
		$rows = SyncSessionTable::query()
			->setSelect(['ID'])
			->whereNotIn('STATE', [State::Finished->value, State::Failure->value])
			->setLimit(10)
			->exec();

		while ($row = $rows->fetch())
		{
			$now = new DateTime();
			SyncSessionTable::update($row['ID'], [
				'STATE' => State::Failure->value,
				'UPDATED_AT' => $now,
				'FINISHED_AT' => $now,
				'MESSAGE' => 'killed',
			]);
		}
	}
}
