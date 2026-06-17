<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Async\Receiver;

use Bitrix\Main;
use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Mail\Integration\HumanResources\NodeAccessCodeResolver;
use Bitrix\Mail\Integration\HumanResources\NodeMemberService;
use Bitrix\Mail\Integration\Im\Notification;
use Bitrix\Mail\Internal\Async\Message\MailboxAccessNotificationMessage;

class MailboxAccessNotificationReceiver extends AbstractReceiver
{
	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof MailboxAccessNotificationMessage)
		{
			throw new UnprocessableMessageException($message);
		}

		if (!Main\Loader::includeModule('im'))
		{
			return;
		}

		$previousUserIds = self::resolveAccessCodesToUserIds($message->previousAccessCodes);
		$currentUserIds = self::resolveAccessCodesToUserIds($message->currentAccessCodes);

		$grantedUserIds = array_diff($currentUserIds, $previousUserIds);
		$revokedUserIds = array_diff($previousUserIds, $currentUserIds);

		$excludeUserIds = [$message->editorUserId, $message->mailboxOwnerId];

		foreach ($grantedUserIds as $userId)
		{
			if (!in_array($userId, $excludeUserIds, true))
			{
				Notification::notifyUserAboutAccessGranted(
					$userId,
					$message->editorUserId,
					$message->mailboxId,
					$message->mailboxEmail,
				);
			}
		}

		foreach ($revokedUserIds as $userId)
		{
			if (!in_array($userId, $excludeUserIds, true))
			{
				Notification::notifyUserAboutAccessRevoked(
					$userId,
					$message->editorUserId,
					$message->mailboxId,
					$message->mailboxEmail,
				);
			}
		}
	}

	/**
	 * @param string[] $accessCodes
	 * @return int[]
	 */
	private static function resolveAccessCodesToUserIds(array $accessCodes): array
	{
		if (empty($accessCodes))
		{
			return [];
		}

		$userIds = [];
		$departmentAccessCodes = [];
		$departmentWithSubAccessCodes = [];

		foreach ($accessCodes as $code)
		{
			if (preg_match('/' . AccessCode::AC_USER . '/', $code, $matches))
			{
				$userIds[] = (int)$matches[2];
			}
			elseif (preg_match('/' . AccessCode::AC_ALL_DEPARTMENT . '/', $code))
			{
				$departmentWithSubAccessCodes[] = $code;
			}
			elseif (preg_match('/' . AccessCode::AC_DEPARTMENT . '/', $code))
			{
				$departmentAccessCodes[] = $code;
			}
		}

		if ($departmentAccessCodes)
		{
			$nodeIds = NodeAccessCodeResolver::resolveNodeIds($departmentAccessCodes);
			foreach (NodeMemberService::getMembersByDepartmentIds($nodeIds) as $member)
			{
				$userIds[] = (int)$member['id'];
			}
		}

		if ($departmentWithSubAccessCodes)
		{
			$nodeIds = NodeAccessCodeResolver::resolveNodeIds($departmentWithSubAccessCodes);
			array_push($userIds, ...NodeMemberService::getMembersWithSubDepartments($nodeIds));
		}

		return array_values(array_unique($userIds));
	}
}
