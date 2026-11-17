<?php

namespace Bitrix\Disk\Document\Flipchart;

use Bitrix\Disk\Document\Models\DocumentService;
use Bitrix\Disk\Document\SessionManager as BaseSessionManager;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Main\ArgumentException;

final class SessionManager extends BaseSessionManager
{
	protected DocumentService|null $service = DocumentService::FlipChart;

	protected function buildFields(): array
	{
		$filter = parent::buildFields();
		if (!is_null($this->sessionType))
		{
			$filter['TYPE'] = $this->getEffectiveSessionType();
		}

		return $filter;
	}

	/**
	 * @return DocumentSession[]
	 * @throws ArgumentException
	 */
	public function findAllSessions(): array
	{
		$filter = $this->buildFilter();
		unset($filter['USER_ID']);

		return DocumentSession::getModelList([
			'select' => ['*'],
			'filter' => $filter,
			'order' => ['ID' => 'DESC'],
		]);
	}

	/**
	 * The registered user editing under the hash: the preferred one when that user has an active editing
	 * session of the hash, otherwise the one with the latest such session; null when no registered user does.
	 *
	 * @throws ArgumentException
	 */
	public function findEditingParticipantId(int $preferredUserId = 0): ?int
	{
		$participantId = null;
		foreach ($this->findAllSessions() as $session)
		{
			$userId = $session->getUserId();
			if ($userId <= 0 || $session->getType() !== DocumentSession::TYPE_EDIT)
			{
				continue;
			}

			if ($userId === $preferredUserId)
			{
				return $userId;
			}

			$participantId ??= $userId;
		}

		return $participantId;
	}
}