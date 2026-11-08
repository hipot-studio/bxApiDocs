<?php

namespace Bitrix\Mobile\Provider;

use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Mobile\Tourist;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Imbot\Bot\SupportService;
use Bitrix\Main\DI\ServiceLocator;

class SupportProvider
{
	/** @var array<int, bool> */
	private static array $enabledByUserId = [];

	private ?int $userId;

	public function __construct()
	{
		$this->userId = (int)CurrentUser::get()->getId();
	}

	/**
	 * Checks if the bot is enabled.
	 *
	 * The feature flag and the bot lookup ask this twice within one request, and the answer costs a
	 * walk of the imbot session of the user. It is kept per user id: a single process serves more
	 * than one of them.
	 *
	 * @return bool
	 * @throws LoaderException
	 */
	public function isEnabled(): bool
	{
		if (!$this->userId)
		{
			return false;
		}

		return self::$enabledByUserId[$this->userId] ??= $this->resolveEnabled();
	}

	/**
	 * Gets the bot ID based on the current support type.
	 * @return int|null
	 * @throws LoaderException
	 */
	public function getBotId(): ?int
	{
		if (!$this->isEnabled())
		{
			return null;
		}

		return $this->getSupportService()?->getBotId();
	}

	/**
	 * Checks if support is shown for the current user.
	 * @return bool
	 */
	public static function isSupportShownForUser(): bool
	{
		$events = Tourist::getEvents();

		return array_key_exists('show_support', $events);
	}

	private function resolveEnabled(): bool
	{
		$supportService = $this->getSupportService();

		return $supportService?->isEnabled() && $supportService?->isActiveSupportForUser($this->userId);
	}

	/**
	 * Gets support service instance
	 * @return SupportService|null
	 * @throws LoaderException
	 */
	private function getSupportService(): ?SupportService
	{
		if (Loader::includeModule('imbot') && ServiceLocator::getInstance()->has('imbot.bot.support'))
		{
			return ServiceLocator::getInstance()->get('imbot.bot.support');
		}

		return null;
	}
}
