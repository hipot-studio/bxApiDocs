<?php

namespace Bitrix\TasksMobile\Provider;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\UserTable;
use Bitrix\Mobile\Tourist;

final class AhaMomentProvider
{
	private const AHA_MOMENT_TASK_CHAT_ENABLED = 'chat_button_moment_enabled';

	public function isAhaMomentAlreadyShown(string $ahaMoment): bool
	{
		return match ($ahaMoment)
		{
			self::AHA_MOMENT_TASK_CHAT_ENABLED => $this->isTaskChatFeatureMomentAlreadyShown(),
			default => true,
		};
	}

	private function isTaskChatFeatureMomentAlreadyShown(): bool
	{
		$releaseTimestamp = 1764115200; // 2025-11-26 00:00:00

		if ($this->isAlreadyShown(self::AHA_MOMENT_TASK_CHAT_ENABLED))
		{
			return true;
		}

		if ($this->isNewUser($releaseTimestamp))
		{
			return true;
		}

		return false;
	}

	private function isNewUser(int $timestamp): bool
	{
		$userId = CurrentUser::get()->getId();

		$user = UserTable::getById($userId)->fetchObject();

		if ($user && $user->getDateRegister())
		{
			return $user->getDateRegister()->getTimestamp() >= $timestamp;
		}

		return false;
	}

	private function isAlreadyShown(string $ahaMoment): bool
	{
		$events = Tourist::getEvents();
		if (array_key_exists($ahaMoment, $events))
		{
			return true;
		}

		return false;
	}
}