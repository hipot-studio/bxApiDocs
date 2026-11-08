<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Message as MailMessage;
use Bitrix\Mail\MessageView\AvatarManager;
use Bitrix\Main\Mail\Address;

/**
 * Who the sender cell names. An outgoing message names its recipients instead; an address the parser
 * could not read stays plain text and still opens the message.
 */
class SenderPresenter
{
	public function __construct(
		private readonly AvatarPresenter $avatarPresenter = new AvatarPresenter(),
	)
	{
	}

	/**
	 * @param array $avatarConfigs Result of AvatarManager::getAvatarParamsFromMessagesHeaders() for the page
	 * @return array{addresses: array<int, array{text: string, title: string, valid: bool}>, avatar: array}
	 */
	public function present(array $item, string $mailboxEmail, array $avatarConfigs): array
	{
		$from = (string)($item['FIELD_FROM'] ?? '');
		$to = (string)($item['FIELD_TO'] ?? '');
		$rowMailboxEmail = (string)($item['MAILBOX_EMAIL'] ?? '') ?: $mailboxEmail;
		$isOutgoing = $this->isOutgoing($from, $rowMailboxEmail);
		$namesRecipients = !empty($to) && $isOutgoing;

		$sourceField = $namesRecipients ? $to : $from;
		$avatarKey = AvatarManager::getAvatarKeyByString($namesRecipients ? $to : $from);
		$avatarParams = !empty($avatarKey) && !empty($avatarConfigs[$avatarKey])
			? $avatarConfigs[$avatarKey]
			: []
		;
		$messageId = (int)($item['MESSAGE_ID'] ?? 0);
		$senderAvatar = $avatarParams['senderAvatarByMessageId'][$messageId] ?? null;
		if (
			!$isOutgoing
			&& $messageId > 0
			&& is_array($senderAvatar)
			&& empty($avatarParams['mailContact']['IS_EMPLOYEE'])
			&& ($avatarParams['SOURCE'] ?? null) !== 'employee'
		)
		{
			$avatarParams['senderAvatar'] = $senderAvatar;
		}

		return [
			'addresses' => $this->buildAddresses($sourceField),
			'avatar' => $this->avatarPresenter->present($avatarParams),
		];
	}

	private function isOutgoing(string $from, string $mailboxEmail): bool
	{
		foreach (Message::parseAddressList($from) as $candidate)
		{
			$address = new Address($candidate);
			if ($address->validate())
			{
				return $address->getEmail() === $mailboxEmail;
			}
		}

		return false;
	}

	/**
	 * @return array<int, array{text: string, title: string, valid: bool}>
	 */
	private function buildAddresses(string $addressList): array
	{
		$addresses = [];

		foreach (Message::parseAddressList($addressList) as $rawAddress)
		{
			$address = new Address($rawAddress);

			if (!$address->validate())
			{
				$addresses[] = ['text' => $rawAddress, 'title' => $rawAddress, 'valid' => false];

				continue;
			}

			$name = $address->getName() ? MailMessage::stripQuotes($address->getName()) : null;
			$email = $address->getEmail() ? MailMessage::stripQuotes($address->getEmail()) : null;

			$addresses[] = [
				'text' => (string)($name ?: $email),
				'title' => ($name ? $name . ' / ' : '') . $email,
				'valid' => true,
			];
		}

		return $addresses;
	}
}
