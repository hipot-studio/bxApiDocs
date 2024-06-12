<?php

namespace Bitrix\Im\V2\Chat;

use Bitrix\Im\Recent;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Message;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

class OpenChannelChat extends ChannelChat
{
	public const PULL_TAG_SHARED_LIST = 'IM_SHARED_CHANNEL_LIST';

	protected function sendMessageUsersAdd(array $usersToAdd, bool $skipRecent = false): void
	{
		return;
	}

	protected function sendMessageUserDelete(int $userId, bool $skipRecent = false): void
	{
		return;
	}

	public function extendPullWatch(): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		parent::extendPullWatch();

		if ($this->getSelfRelation() === null)
		{
			\CPullWatch::Add($this->getContext()->getUserId(), "IM_PUBLIC_{$this->getId()}", true);
		}
	}

	protected function getAccessCodesForDiskFolder(): array
	{
		$accessCodes = parent::getAccessCodesForDiskFolder();
		$departmentCode = \CIMDisk::GetTopDepartmentCode();

		if ($departmentCode)
		{
			$driver = \Bitrix\Disk\Driver::getInstance();
			$rightsManager = $driver->getRightsManager();
			$accessCodes[] = [
				'ACCESS_CODE' => $departmentCode,
				'TASK_ID' => $rightsManager->getTaskIdByName($rightsManager::TASK_READ)
			];
		}

		return $accessCodes;
	}

	protected function updateStateAfterUsersAdd(array $usersToAdd): self
	{
		parent::updateStateAfterUsersAdd($usersToAdd);

		if (Loader::includeModule('pull'))
		{
			foreach ($usersToAdd as $userId)
			{
				\CPullWatch::Delete($userId, 'IM_PUBLIC_' . $this->getId());
			}
		}

		return $this;
	}

	public function needToSendPublicPull(): bool
	{
		return true;
	}

	public static function sendSharedPull(array $pull, int $chatId = 0, ?array $userIds = null): void
	{
		Application::getInstance()->addBackgroundJob(function () use ($pull, $chatId, $userIds) {
			$pull['extra']['is_shared_event'] = true;
			if ($userIds !== null)
			{
				$pull['skip_users'] = $userIds;
			}
			elseif ($chatId)
			{
				$pull['skip_users'] = Chat::getInstance($chatId)->getRelations()->getUserIds();
			}
			\CPullWatch::AddToStack(\Bitrix\Im\V2\Chat\OpenChannelChat::PULL_TAG_SHARED_LIST, $pull);
		});
	}

	public function isNew(): bool
	{
		$lastDay = (new DateTime())->add('-1 day');
		$dateCreate = $this->getDateCreate();

		if (!$dateCreate instanceof DateTime)
		{
			return false;
		}

		return $dateCreate->getTimestamp() > $lastDay->getTimestamp();
	}

	protected function getDefaultType(): string
	{
		return self::IM_TYPE_OPEN_CHANNEL;
	}

	protected function checkAccessWithoutCaching(int $userId): bool
	{
		$hasAccess = parent::checkAccessWithoutCaching($userId);

		if ($hasAccess)
		{
			return true;
		}

		return !User::getInstance($userId)->isExtranet();
	}

	public static function extendPullWatchToCommonList(?int $userId = null): void
	{
		$userId ??= User::getCurrent()->getId();

		if (Loader::includeModule('pull'))
		{
			\CPullWatch::Add($userId, static::PULL_TAG_SHARED_LIST, true);
		}
	}
}