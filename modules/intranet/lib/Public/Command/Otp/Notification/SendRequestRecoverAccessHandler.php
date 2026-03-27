<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Public\Command\Otp\Notification;

use Bitrix\Intranet\Internal\Integration\Im\Notification\Message;
use Bitrix\Intranet\Internal\Integration\Im\Notification\NotifySender;
use Bitrix\Intranet\Internal\Integration\Security\PersonalOtp;
use Bitrix\Intranet\Internal\Integration\Ui\Helpdesk\ArticleLinkProvider;
use Bitrix\Intranet\Service\UserService;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Error;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;

class SendRequestRecoverAccessHandler
{
	/**
	 * @throws ArgumentTypeException
	 * @throws LoaderException
	 * @throws ArgumentOutOfRangeException
	 * @throws SystemException
	 */
	public function __invoke(SendRequestRecoverAccessCommand $command): Result
	{
		$result = new Result();
		$personalOtp = (new PersonalOtp($command->user));
		$userService = new UserService();

		if (!$personalOtp->canSendRequestRecoverAccess() || !NotifySender::isAvailable())
		{
			return $result->addError(new Error('Cannot send request to recover access'));
		}

		$user = $command->user;
		$adminIds = $userService->getAdminUserIds();

		if (empty($adminIds))
		{
			return $result->addError(new Error('No administrators found to notify'));
		}

		$adminIds = array_filter($adminIds, static fn($adminId) => $adminId !== $user->getId());
		$articleLink = (new ArticleLinkProvider())->getByCode('26676294');
		$subject = static fn (?string $languageId = null) => Loc::getMessage('INTRANET_COMMAND_OTP_NOTIFICATION_SEND_REQUEST_RECOVER_ACCESS_SUBJECT', null, $languageId);
		$userDetailLink = $userService->getDetailUrl($user->getId());
		$plainText = static fn (?string $languageId = null) => Loc::getMessage('INTRANET_COMMAND_OTP_NOTIFICATION_SEND_REQUEST_RECOVER_ACCESS_PLAIN_TEXT', [
			'[LINK_PROFILE]' => "<a href='$userDetailLink'>",
			'[/LINK]' => '</a>',
			'[LINK_ARTICLE]' => "<a href='$articleLink'>",
		], $languageId);
		$notifySender = new NotifySender();

		foreach ($adminIds as $adminId)
		{
			$message = new Message(
				toUserId: $adminId,
				fromUserId: $user->getId(),
				notifyTag: 'INTRANET|OTP_RECOVER_ACCESS|' . $user->getId(),
				notifyType: IM_NOTIFY_SYSTEM,
				notifyModule: 'intranet',
				notifyEvent: 'recover_access_request',
				notifyMessage: static fn (?string $languageId = null) => Loc::getMessage('INTRANET_COMMAND_OTP_NOTIFICATION_SEND_REQUEST_RECOVER_ACCESS_SHORT_TEXT', null, $languageId),
			);

			$message->setComponentParams('DefaultEntity', [
				'SUBJECT' => $subject,
				'PLAIN_TEXT' => $plainText,
			]);

			$notifySender->send($message);
		}

		$personalOtp->markRequestRecoverAccessSent();

		return $result;
	}
}
