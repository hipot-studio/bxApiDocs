<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Socialnetwork;

use Bitrix\Main\Application;
use Bitrix\Socialnetwork\V2\Public\AccessProvider;
use Bitrix\Socialnetwork\V2\Public\CallMode;
use Bitrix\Socialnetwork\V2\Public\ProjectAction;

final class GroupBlogAccess
{
	private const OPERATIONS = ['write_post', 'moderate_post', 'full_post'];

	private static ?bool $isFacadeAvailable = null;

	/**
	 * Checks a blog post operation of the group: by the role model of the project when socialnetwork has its
	 * public access API, by the legacy feature permissions otherwise. The answer of the role model is final,
	 * neither its denial nor its failure falls back to the legacy permissions.
	 */
	public static function canPerformOperation(int $userId, ?string $groupId, string $operation): bool
	{
		if (!in_array($operation, self::OPERATIONS, true))
		{
			return false;
		}

		if (!self::isFacadeAvailable())
		{
			return (bool)\CSocNetFeaturesPerms::canPerformOperation(
				$userId,
				\SONET_ENTITY_GROUP,
				$groupId,
				'blog',
				$operation
			);
		}

		return self::canByFacade($userId, (int)$groupId, $operation);
	}

	private static function isFacadeAvailable(): bool
	{
		// The autoloader does not remember a missing class, so the lookup is made once per hit.
		self::$isFacadeAvailable ??=
			class_exists(AccessProvider::class)
			&& class_exists(CallMode::class)
			&& enum_exists(ProjectAction::class)
		;

		return self::$isFacadeAvailable;
	}

	private static function canByFacade(int $userId, int $groupId, string $operation): bool
	{
		try
		{
			$action = match ($operation)
			{
				'write_post' => ProjectAction::BlogWritePost,
				'moderate_post' => ProjectAction::BlogModeratePost,
				'full_post' => ProjectAction::BlogFullPost,
			};

			return AccessProvider::get()->can($userId, $groupId, $action, CallMode::regular());
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return false;
		}
	}
}
