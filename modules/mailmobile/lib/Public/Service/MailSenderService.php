<?php

namespace Bitrix\MailMobile\Public\Service;

use Bitrix\MailMobile\Internal\Integration\Mail\SignatureProvider;
use Bitrix\MailMobile\Internal\Integration\Main\SenderProvider;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Mail\Converter;

class MailSenderService
{
	private SenderProvider $senderProvider;
	private SignatureProvider $signatureProvider;

	public function __construct()
	{
		$this->senderProvider = new SenderProvider();
		$this->signatureProvider = new SignatureProvider();
	}

	/**
	 * @throws ObjectPropertyException|ArgumentException|SystemException
	 */
	public function getSenderList(?int $userId = null): array
	{
		$mailboxes = $this->senderProvider->getUserAvailableSenders($userId);
		$senders = [];

		foreach ($mailboxes as $sender)
		{
			$builtSender = $this->buildSender([
				'email' => $sender['email'] ?? '',
				'name' => $sender['name'] ?? '',
				'id' => $sender['userId'] ?? 0,
				'isUser' => true,
			]);

			$htmlSignature = $this->signatureProvider->getSignature(
				$sender['email'] ?? '',
				$sender['name'] ?? '',
				$userId
			);
			$builtSender['signature'] = Converter::htmlToText($htmlSignature);

			$senders[] = $builtSender;
		}

		return $senders;
	}

	private function buildSender(array $props): array
	{
		$whiteListKeys = [
			'email' => '',
			'name' => '',
			'id' => 0,
			'isUser' => false,
		];

		$sender = [];
		foreach ($whiteListKeys as $key => $value)
		{
			if (isset($props[$key]) && $props[$key])
			{
				if ($key === 'id')
				{
					$props[$key] = (int)$props[$key];
				}

				$sender[$key] = $props[$key];
			}
			else
			{
				$sender[$key] = $value;
			}
		}

		return $sender;
	}
}
