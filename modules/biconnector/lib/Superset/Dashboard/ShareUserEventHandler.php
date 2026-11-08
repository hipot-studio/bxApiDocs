<?php

namespace Bitrix\BIConnector\Superset\Dashboard;

use Bitrix\BIConnector\Public\Command\Share\DeactivateUserSharesCommand;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Application;
use Bitrix\Main\Result;

/**
 * Revokes the public share links of an employee whose account is made inactive.
 *
 * The links keep working under the rights of their author, and those rights survive the
 * account being switched off, so the revocation has an event of its own here instead of
 * riding along with the Superset user synchronization.
 */
class ShareUserEventHandler
{
	public static function onAfterUserUpdate(array $fields): void
	{
		/**
		 * UF_CONNECTOR_MD5 uses in imconnector for OL user
		 * Skip this user
		 */
		if ($fields['UF_CONNECTOR_MD5'] ?? false)
		{
			return;
		}

		// The core sends this event after a rejected update too, carrying the verdict in RESULT.
		if (empty($fields['RESULT']))
		{
			return;
		}

		$userId = (int)($fields['ID'] ?? 0);
		if (!$userId)
		{
			return;
		}

		// ACTIVE is in the fields only when it was passed to CUser::Update, so the strict
		// comparison also skips every update that did not touch the activity.
		if (($fields['ACTIVE'] ?? null) !== 'N')
		{
			return;
		}

		Application::getInstance()->addBackgroundJob(
			static function () use ($userId) {
				$result = (new DeactivateUserSharesCommand($userId))->run();
				if (!$result->isSuccess())
				{
					self::logFailure($userId, $result);
				}
			}
		);
	}

	/**
	 * addBackgroundJob ignores whatever the job returns and logs exceptions only, so a
	 * partial failure of the revocation leaves no trace unless it is written down here.
	 */
	private static function logFailure(int $userId, Result $result): void
	{
		$shareIds = [];
		$steps = [];
		foreach ($result->getErrors() as $error)
		{
			$customData = $error->getCustomData();

			$shareId = (int)($customData['shareId'] ?? 0);
			if ($shareId)
			{
				$shareIds[] = $shareId;
			}

			$step = (string)($customData['step'] ?? '');
			if ($step !== '')
			{
				$steps[] = $step;
			}
		}

		Logger::logErrors($result->getErrors(), [
			'stage' => 'user_deactivation.revoke_shares',
			'userId' => $userId,
			'shareIds' => array_values(array_unique($shareIds)),
			'steps' => array_values(array_unique($steps)),
		]);
	}
}
