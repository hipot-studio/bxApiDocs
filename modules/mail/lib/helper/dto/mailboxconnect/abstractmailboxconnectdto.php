<?php

namespace Bitrix\Mail\Helper\Dto\MailboxConnect;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\PositiveNumber;

abstract class AbstractMailboxConnectDTO
{
	public function __construct(
		public string $email = '',
		public string $login = '',
		public string $password = '',
		public ?int $serviceId = 0,
		public string $server = '',
		#[PositiveNumber]
		public string $port = '993',
		public string $ssl = 'Y',
		public string $storageOauthUid = '',
		public string $syncAfterConnection = 'Y',
		public string $useSmtp = 'N',
		public string $serverSmtp = '',
		#[PositiveNumber]
		public string $portSmtp = '587',
		public string $sslSmtp = 'Y',
		public string $loginSmtp = '',
		public string $passwordSMTP = '',
		public ?bool $useLimitSmtp = false,
		public ?int $limitSmtp = null,
		public string $mailboxName = '',
		public string $senderName = '',
		public string $iCalAccess = 'N',
		public ?array $crmOptions = [],
		public ?int $userIdToConnect = null,
		public ?array $service = null,
		public ?array $site = null,
		public ?int $messageMaxAge = null,
		public ?array $serviceConfig = [
			'serviceType' => 'imap',
			'name' => 'other',
		],
	)
	{
	}

	abstract public static function createFromRequest(HttpRequest $request): self;
}
