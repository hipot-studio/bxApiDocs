<?php

namespace Bitrix\StaffTrack\Internal\Service\Office;

use Bitrix\StaffTrack\Internal\Integration\Pull\PushCommand;
use Bitrix\StaffTrack\Internal\Integration\Pull\PushSender;

class OfficeChangeNotifier
{
	public function notifyAdded(int $officeId): void
	{
		$this->notify(PushCommand::OFFICE_ADD, $officeId);
	}

	public function notifyUpdated(int $officeId): void
	{
		$this->notify(PushCommand::OFFICE_UPDATE, $officeId);
	}

	public function notifyDeleted(int $officeId): void
	{
		$this->notify(PushCommand::OFFICE_DELETE, $officeId);
	}

	/**
	 * Tells the author of a request to see the staff of an office that the state of the request changed.
	 *
	 * @param int $userId the requester
	 * @param int $officeId
	 * @return void
	 */
	public function notifyAccessChanged(int $userId, int $officeId): void
	{
		if ($userId <= 0 || $officeId <= 0)
		{
			return;
		}

		$this->sendToUser($userId, PushCommand::OFFICE_ACCESS_UPDATE, ['officeId' => $officeId]);
	}

	protected function send(PushCommand $command, array $params): void
	{
		PushSender::sendShared($command, $params);
	}

	protected function sendToUser(int $userId, PushCommand $command, array $params): void
	{
		PushSender::send($userId, $command, $params);
	}

	private function notify(PushCommand $command, int $officeId): void
	{
		if ($officeId <= 0)
		{
			return;
		}

		$this->send($command, ['officeId' => $officeId]);
	}
}
