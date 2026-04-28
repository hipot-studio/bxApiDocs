<?php

namespace Bitrix\Main\Controller\Mail;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main;
use Bitrix\Main\Mail;
use Bitrix\Main\Mail\Sender\UserSenderDataProvider;
use Bitrix\Main\Error;

class Sender extends Controller
{
	public function getSenderDataAction(int $senderId): ?array
	{
		$checkResult = Main\Mail\Sender::canEditSender($senderId);
		if (!$checkResult->isSuccess())
		{
			$this->addErrors($checkResult->getErrors());

			return null;
		}

		$sender = Main\Mail\Sender::getById($senderId);
		if (!$sender)
		{
			return null;
		}

		$smtp = $sender['OPTIONS']['smtp'] ?? [];

		if (!empty($smtp))
		{
			$response['smtp'] = [
				'server' => $smtp['server'] ?? null,
				'port' => $smtp['port'] ?? null,
				'protocol' => $smtp['protocol'] ?? null,
				'login' => $smtp['login'] ?? null,
				'limit' => $smtp['limit'] ?? null,
			];
		}

		return array_merge(
			$response['smtp'] ?? [],
			[
				'email' => $sender['EMAIL'],
				'name' => !empty($sender['NAME']) ? $sender['NAME'] : Mail\Sender\UserSenderDataProvider::getUserFormattedName((int)$sender['USER_ID']),
				'isPublic' => (int)$sender['IS_PUBLIC'] === 1,
				'useName' => Mail\Sender\UserSenderDataProvider::shouldUseCustomSenderName($sender),
			]
		);
	}

	public function getAvailableSendersAction(): array
	{
		return Main\Mail\Sender::prepareUserMailboxes();
	}

	public function getSenderTransitionalDataAction(int $senderId): ?array
	{
		if (!$this->canAccessSender($senderId))
		{
			$this->addError(new Error(Loc::getMessage('MAIN_MAIL_SENDER_EDIT_ERROR')));

			return null;
		}

		return Mail\Sender\UserSenderDataProvider::getSenderTransitionalData($senderId);
	}

	public function getSenderByMailboxIdAction(int $mailboxId, bool $getSenderWithoutSmtp = false): ?array
	{
		$senders = Main\Mail\Sender::getByParentId($mailboxId, 'mail');
		if (!empty($senders) && !$this->canAccessSender((int)$senders[0]['ID']))
		{
			$this->addError(new Error(Loc::getMessage('MAIN_MAIL_SENDER_EDIT_ERROR')));

			return null;
		}

		return Mail\Sender\UserSenderDataProvider::getSenderInfoByMailboxId($mailboxId, $getSenderWithoutSmtp);
	}

	public function getDefaultSenderNameAction(): string
	{
		return Mail\Sender\UserSenderDataProvider::getUserFormattedName() ?? '';
	}

	public function submitSenderAction(array $data): ?array
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		if (!$userId)
		{
			$this->addError(new Error('User not found', 'ERR_NO_USER'));

			return null;
		}

		$useName = ($data['useName'] ?? 'N') === 'Y';
		$name = $useName ? (trim($data['name'] ?? '')) : '';
		$email = mb_strtolower(trim((string)($data['email'] ?? '')));
		$isPublic = ($data['public'] ?? 'N') === 'Y';
		$smtp =  $data['smtp'] ?? [];

		if (empty($smtp) || !is_array($smtp))
		{
			$this->addError(new Error(Loc::getMessage('MAIN_CONTROLLER_MAIL_SENDER_AJAX_ERROR'), 'ERR_EMAIL'));

			return null;
		}

		$fields = [
			'NAME' => $name,
			'EMAIL' => $email,
			'IS_PUBLIC' => $isPublic,
		];

		$senderId = (int)($data['id'] ?? 0);
		if ($senderId)
		{
			$checkResult = Main\Mail\Sender::canEditSender($senderId);
			if (!$checkResult->isSuccess())
			{
				$this->addErrors($checkResult->getErrors());

				return null;
			}

			$fields['OPTIONS']['smtp'] = $smtp;
			$fields['OPTIONS']['useSenderName'] = $useName;
			$updateResult = Mail\Sender::updateSender($senderId, $fields);
			if (!$updateResult->isSuccess())
			{
				$this->addError($updateResult->getErrors()[0]);

				return null;
			}

			return [
				'senderId' => $senderId,
				'name' => UserSenderDataProvider::getSenderNameBySender($fields, $userId),
			];
		}

		if (Mail\Sender::hasUserSenderWithEmail($email))
		{
			$this->addError(new Error(Loc::getMessage('MAIN_CONTROLLER_MAIL_SENDER_EXISTS_SENDER'), 'ERR_EXISTS_SENDER'));

			return null;
		}

		$result = Mail\Sender::prepareSmtpConfigForSender($smtp);
		if (!$result->isSuccess())
		{
			$error = $result->getErrors()[0];
			$this->addError(new Error($error->getMessage(), 'ERR_SMTP_CONFIG'));

			return null;
		}

		$fields['USER_ID'] = $userId;
		$fields['IS_CONFIRMED'] = true;
		$fields['OPTIONS']['smtp'] = $smtp;
		$fields['OPTIONS']['useSenderName'] = $useName;

		$result = Main\Mail\Sender::add($fields);

		if (!empty($result['error']))
		{
			$this->addError($result['error']);

			return null;
		}

		if (!empty($result['errors']))
		{
			$this->addError($result['errors'][0]);

			return null;
		}

		$this->prepareLimits($smtp, $email);
		$senderId = $result['senderId'] ?? 0;

		return [
			'senderId' => $senderId,
			'name' => UserSenderDataProvider::getSenderNameBySender($fields, $userId),
		];
	}

	/**
	 * Add Sender without smtp-server settings
	 */
	public function addAliasAction(string $name, string $email): ?array
	{
		$userId = (int)CurrentUser::get()->getId();

		if (!$userId)
		{
			return null;
		}

		if (!Main\Mail\Sender::hasUserAvailableSmtpSenderByEmail($email, $userId))
		{
			return null;
		}

		$result = Main\Mail\Sender::add([
			'NAME' => $name,
			'EMAIL' => $email,
			'IS_CONFIRMED' => true,
			'USER_ID' => $userId,
		]);

		if (!empty($result['error']))
		{
			$this->addError(new Error($result['error'], 'ERR_ADD_SENDER'));

			return null;
		}

		$userData = Mail\Sender\UserSenderDataProvider::getUserInfo($userId);
		$result['avatar'] = $userData['userAvatar'] ?? null;
		$result['userUrl'] = $userData['userUrl'] ?? null;

		return $result;
	}

	public function deleteSenderAction(int $senderId): void
	{
		$checkResult = Main\Mail\Sender::canEditSender($senderId);
		if (!$checkResult->isSuccess())
		{
			$this->addErrors($checkResult->getErrors());

			return;
		}

		Main\Mail\Sender::delete([$senderId]);
	}

	public function updateSenderNameAction(int $senderId, string $name): void
	{
		$result = Main\Mail\Sender::updateSender($senderId, ['NAME' => $name]);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}
	}

	private function canAccessSender(int $senderId): bool
	{
		$userId = (int)CurrentUser::get()->getId();
		if (!$userId)
		{
			return false;
		}

		$sender = Main\Mail\Sender::getById($senderId);
		if (!$sender)
		{
			return false;
		}

		if (UserSenderDataProvider::isAdmin())
		{
			return true;
		}

		if ((int)$sender['USER_ID'] === $userId)
		{
			return true;
		}

		if ((int)($sender['IS_PUBLIC'] ?? 0) === 1)
		{
			return true;
		}

		if (
			$sender['PARENT_MODULE_ID'] === 'mail'
			&& Main\Loader::includeModule('mail')
		)
		{
			$userMailboxes = \Bitrix\Mail\MailboxTable::getUserMailboxes($userId);
			if (isset($userMailboxes[$sender['PARENT_ID']]))
			{
				return true;
			}
		}

		return false;
	}

	private function prepareLimits(array $smtp, string $email): void
	{
		if ($smtp && $smtp['limit'] !== null)
		{
			Main\Mail\Sender::setEmailLimit($email, $smtp['limit']);
		}
		elseif ($smtp && !isset($smtp['limit']))
		{
			Main\Mail\Sender::removeEmailLimit($email);
		}
	}
}
