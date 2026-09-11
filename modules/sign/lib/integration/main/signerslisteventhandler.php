<?php

namespace Bitrix\Sign\Integration\Main;

use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Sign\Service\Container;

class SignersListEventHandler
{
	private const FALLBACK_ADMIN_USER_ID = 1;

	/**
	 * @var array<int, true>
	 */
	private static array $scheduledTemplateProcessingUserIds = [];

	public static function OnAfterUserUpdate(array $data, ?callable $inactiveUserHandler = null): void
	{
		$userId = (int)($data['ID'] ?? 0);
		if (
			$userId < 1
			|| !($data['RESULT'] ?? false)
			|| ($data['ACTIVE'] ?? null) !== 'N'
		)
		{
			return;
		}

		$inactiveUserHandler ??= self::handleInactiveUser(...);
		$inactiveUserHandler($userId);
	}

	/**
	 * Unlike the deactivation, the deletion of the account also drops its personal options: there is
	 * nobody left to keep the personal order of the groups for.
	 */
	public static function OnAfterUserDelete(int $userId): void
	{
		self::handleInactiveUser($userId);
		Container::instance()->getSignersListService()->deleteAllUserOptions($userId);
	}

	private static function handleInactiveUser(int $userId): void
	{
		self::deleteUserFromAllLists($userId);
		if (isset(self::$scheduledTemplateProcessingUserIds[$userId]))
		{
			return;
		}

		self::$scheduledTemplateProcessingUserIds[$userId] = true;
		self::addTemplateProcessingBackgroundJob($userId);
	}

	private static function addTemplateProcessingBackgroundJob(
		int $userId,
		?Application $application = null,
		?callable $processor = null,
	): void
	{
		$application ??= Application::getInstance();
		$processor ??= static fn(int $userId): Result => Container::instance()
			->getDocumentTemplateService()
			->markTemplatesWithUserAsIncomplete($userId)
		;

		$application->addBackgroundJob(
			static function () use ($processor, $userId): void {
				try
				{
					$result = $processor($userId);
				}
				catch (\Throwable $exception)
				{
					throw new SystemException('Unable to update document templates.', 0, '', 0, $exception);
				}
				if ($result->isSuccess())
				{
					return;
				}

				$errorMessages = array_map(
					static fn(\Bitrix\Main\Error $error): string => $error->getMessage(),
					$result->getErrors(),
				);
				$message = implode('; ', array_unique(array_filter($errorMessages)));
				throw new SystemException(
					$message === ''
						? 'Unable to update document templates.'
						: "Unable to update document templates. {$message}",
				);
			},
		);
	}

	private static function deleteUserFromAllLists(int $userId): void
	{
		$modifiedById = self::getAdminUserId();
		Container::instance()->getSignersListService()->deleteUserFromAllLists($userId, $modifiedById);
	}

	private static function getAdminUserId(): int
	{
		if (!Loader::includeModule('intranet'))
		{
			return self::FALLBACK_ADMIN_USER_ID;
		}

		$admins = ServiceContainer::getInstance()->getUserService()->getAdminUserIds();
		return !empty($admins) ? (int)reset($admins) : self::FALLBACK_ADMIN_USER_ID;
	}
}
