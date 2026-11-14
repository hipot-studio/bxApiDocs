<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Pull;

use Bitrix\Main\Loader;
use CPullWatch;
use Throwable;

class SettingsHubPull
{
	public const TAG = 'CRM_SETTINGS_HUB';
	public const COMMAND = 'CRM_SETTINGS_HUB_CHANGED';

	public function subscribe(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		try
		{
			return $this->includePullModule() && $this->addWatch($userId);
		}
		catch (Throwable)
		{
			return false;
		}
	}

	public function publish(?string $eventId): bool
	{
		try
		{
			if (!$this->includePullModule())
			{
				return false;
			}

			return $this->addToStack([
				'module_id' => 'crm',
				'command' => self::COMMAND,
				'params' => ['eventId' => $eventId],
			]);
		}
		catch (Throwable)
		{
			return false;
		}
	}

	protected function includePullModule(): bool
	{
		return Loader::includeModule('pull');
	}

	protected function addWatch(int $userId): bool
	{
		return CPullWatch::Add($userId, self::TAG);
	}

	protected function addToStack(array $event): bool
	{
		return CPullWatch::AddToStack(self::TAG, $event);
	}
}
