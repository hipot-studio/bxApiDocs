<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Infrastructure\Agent;

use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Reading\Counter\CountersUpdater;
use Bitrix\Main\DI\ServiceLocator;

class DeleteFiredUserAgent
{
	private const DELAY = 604800; // 1 week

	public function __construct(
		protected readonly CountersUpdater $updater
	) {}

	public static function register(int $userId): void
	{
		\CAgent::AddAgent(
			self::getAgentName($userId),
			'im',
			'N',
			self::DELAY,
			'',
			'Y',
			\ConvertTimeStamp(time() + \CTimeZone::GetOffset() + self::DELAY, "FULL"),
		);
	}

	public static function unregister(int $userId): void
	{
		\CAgent::RemoveAgent(self::getAgentName($userId), 'im');
	}

	protected static function getAgentName(int $userId): string
	{
		return "\\Bitrix\\Im\\V2\\Reading\\Counter\\Infrastructure\\Agent\\DeleteFiredUserAgent::execute({$userId});";
	}

	public static function execute(int $userId): string
	{
		ServiceLocator::getInstance()->get(self::class)->handle($userId);
		return '';
	}

	protected function handle(int $userId): void
	{
		$user = User::getInstance($userId);
		if ($user->isExist() && $user->isActive())
		{
			return;
		}

		$this->updater->delete()->all()->forUser($userId)->execute();
	}
}
