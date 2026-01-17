<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Messageservice;

use Bitrix\Intranet\Internal\Integration\Socialservices\NetworkClient;
use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\MessageService\MessageStatus;
use Bitrix\MessageService\Sender\Base;
use Bitrix\MessageService\Sender\Result\SendMessage;
use Bitrix\MessageService\Sender\SmsManager;

Loader::includeModule('messageservice');

class TwoFaNetworkSender extends Base
{
	private const ALLOWED_TEMPLATES = [
		'SMS_USER_CONFIRM_NUMBER',
		'SMS_USER_OTP_AUTH_CODE',
	];
	private const SENDER_ID = 'network_sender';

	public function getId(): string
	{
		return self::SENDER_ID;
	}

	public function getName(): string
	{
		return 'network';
	}

	public function getShortName(): string
	{
		return $this->getName();
	}

	public function canUse(): bool
	{
		return IsModuleInstalled('bitrix24');
	}

	public function getFromList(): array
	{
		return [
			[
				'id' => 'network',
				'name' => 'Network Sender',
			],
		];
	}

	public function sendMessage(array $messageFieldsFields): SendMessage
	{
		$response = (new NetworkClient())->sendSms(
			$messageFieldsFields['MESSAGE_TO'],
			$messageFieldsFields['MESSAGE_BODY'],
		);

		$result = new SendMessage();
		if (!$response)
		{
			$result->setStatus(MessageStatus::ERROR);
		}
		else
		{
			$result->setStatus(MessageStatus::SENT);
			$result->setExternalId(uniqid());
		}

		return $result;
	}

	public static function useIfCloud(): void
	{
		if (!IsModuleInstalled('bitrix24') || \COption::GetOptionString('bitrix24', 'network', 'N') === 'N')
		{
			return;
		}

		EventManager::getInstance()->addEventHandler('messageservice', 'onGetSmsSenders', function () {
			return [new self()];
		});


		EventManager::getInstance()->addEventHandler(
			'main',
			'onBeforeSendSms',
			function (Event $event) {
				$sender = SmsManager::getSenderById(self::SENDER_ID);
				$message = $event->getParameter('message');
				if (
					!$sender?->canUse()
					&& !in_array($message->getTemplate()?->getEventName(), self::ALLOWED_TEMPLATES, true)
				) {
					throw new SystemException('Network sender can only be used only in Bitrix24 Cloud for 2FA');
				}

				$message->setSender($sender);
				$message->setFrom($sender?->getFirstFromList());
			},
		);
	}
}
