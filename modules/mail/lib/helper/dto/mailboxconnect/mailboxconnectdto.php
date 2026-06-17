<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Dto\MailboxConnect;

use Bitrix\Mail\Helper\Enum\CrmEntityType;
use Bitrix\Mail\Helper\Enum\CrmFormField;
use Bitrix\Mail\Helper\Mailbox\MailboxConnector;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Mail\Address;
use Bitrix\Main\Web\Json;

final class MailboxConnectDTO
{
	public function __construct(

		public ?string $email = null,
		public ?string $login = null,
		public ?string $password = null,
		public ?int $serviceId = null,
		public ?string $server = null,
		public ?string $port = null,
		public ?bool $ssl = null,
		/** OAuth UID from socialservices storage. Must be provided for OAuth mailboxes, not recoverable from existing data */
		public ?string $storageOauthUid = null,
		public ?bool $syncAfterConnection = null,

		public ?bool $useSmtp = null,
		public ?string $serverSmtp = null,
		public ?string $portSmtp = null,
		public ?bool $sslSmtp = null,
		public ?string $loginSmtp = null,
		public ?string $passwordSMTP = null,
		public ?bool $useLimitSmtp = null,
		public ?int $limitSmtp = null,

		public ?string $mailboxName = null,
		public ?string $senderName = null,
		public ?bool $iCalAccess = null,
		public ?CrmOptions $crmOptions = null,
		public ?int $userIdToConnect = null,
		public ?int $messageMaxAge = null,
		/** @var ?array{serviceType: string, name: string} */
		public ?array $serviceConfig = null,
		public ?bool $uploadOutgoing = null,
		public ?string $link = null,
		/** @var ?string[] Access codes list (e.g., 'U1', 'DR5', 'D10') */
		public ?array $shareAccess = null,
		public ?bool $useSenderName = null,

		public ?array $service = null,
		public ?array $site = null,
		public bool $skipConnectionValidation = false,
	)
	{
	}

	public static function createFromRequest(HttpRequest $request): self
	{
		$mailbox = $request->get('mailbox');

		if (is_array($mailbox))
		{
			return self::createFromMailboxArray($mailbox);
		}

		return self::createFromFlatRequest($request);
	}

	public static function createFromFormFields(array $fields): self
	{
		$newOwnerId = null;
		if (MailboxAccess::hasCurrentUserAccessToChangeMailboxOwner())
		{
			$parsedOwnerId = self::parseUserCode($fields['owner_id'] ?? '');
			if ($parsedOwnerId > 0)
			{
				$newOwnerId = $parsedOwnerId;
			}
		}

		$password = null;
		if (
			($fields['pass_imap'] ?? '') !== ''
			&& ($fields['pass_imap'] ?? '') !== ($fields['pass_placeholder'] ?? '')
		)
		{
			$password = $fields['pass_imap'];
		}

		$passwordSmtp = null;
		if (
			($fields['pass_smtp'] ?? '') !== ''
			&& ($fields['pass_smtp'] ?? '') !== ($fields['pass_placeholder'] ?? '')
		)
		{
			$passwordSmtp = $fields['pass_smtp'];
		}

		$crmOptions = self::prepareCrmOptions($fields);
		$shareAccess = self::parseShareAccessFromFields($fields);

		return new self(
			password: $password,
			serviceId: !empty($fields['service_id']) ? (int)$fields['service_id'] : null,
			server: isset($fields['server_imap']) ? trim($fields['server_imap']) : null,
			port: isset($fields['port_imap']) ? (string)$fields['port_imap'] : null,
			ssl: isset($fields['ssl_imap']) ? ($fields['ssl_imap'] === 'Y') : null,
			storageOauthUid: $fields['oauth_uid'] ?? null,
			useSmtp: !empty($fields['use_smtp']),
			serverSmtp: isset($fields['server_smtp']) ? trim($fields['server_smtp']) : null,
			portSmtp: isset($fields['port_smtp']) ? (string)$fields['port_smtp'] : null,
			sslSmtp: isset($fields['ssl_smtp']) ? ($fields['ssl_smtp'] === 'Y') : null,
			loginSmtp:  isset($fields['login_smtp']) ? trim($fields['login_smtp']) : null,
			passwordSMTP: $passwordSmtp,
			useLimitSmtp: isset($fields['use_limit_smtp']) ? $fields['use_limit_smtp'] === 'Y' : null,
			limitSmtp: isset($fields['limit_smtp']) ? max((int)$fields['limit_smtp'], 0) : null,
			mailboxName: isset($fields['name']) ? trim($fields['name']) : null,
			senderName: isset($fields['sender']) ? trim($fields['sender']) : null,
			iCalAccess: isset($fields['ical_access']) && $fields['ical_access'] === 'Y',
			crmOptions: $crmOptions,
			userIdToConnect: $newOwnerId,
			serviceConfig: !empty($fields['service_id']) ? MailboxConnector::DEFAULT_SERVICE_CONFIG : null,
			uploadOutgoing: isset($fields['upload_outgoing']) ? (int)$fields['upload_outgoing'] === 1 : null,
			link: isset($fields['link']) ? (string)$fields['link'] : null,
			shareAccess: $shareAccess,
			useSenderName: ($fields['use_sender_name'] ?? 'N') === 'Y',
		);
	}

	public static function createFromFormFieldsForConnect(array $fields, array $site, int $userId): self
	{
		$crmOptions = self::prepareCrmOptions($fields);
		$shareAccess = self::prepareShareAccessWithOwner($fields, $userId);

		$email = (string)($fields['email'] ?? '');
		$isOAuth = !empty($fields['oauth_uid']);
		$login = $isOAuth ? $email : (string)($fields['login_imap'] ?? '');

		return new self(
			email: $email,
			login: $login,
			password: (string)($fields['pass_imap'] ?? ''),
			serviceId: (int)($fields['service_id'] ?? 0),
			server: (string)($fields['server_imap'] ?? ''),
			port: (string)($fields['port_imap'] ?? MailboxConnector::DEFAULT_IMAP_PORT),
			ssl: ($fields['ssl_imap'] ?? 'Y') === 'Y',
			storageOauthUid: (string)($fields['oauth_uid'] ?? ''),
			syncAfterConnection: ($fields['sync_after_connection'] ?? 'N') === 'Y',
			useSmtp: (int)($fields['use_smtp'] ?? 0) === 1,
			serverSmtp: (string)($fields['server_smtp'] ?? ''),
			portSmtp: (string)($fields['port_smtp'] ?? MailboxConnector::DEFAULT_SMTP_PORT),
			sslSmtp: ($fields['ssl_smtp'] ?? '') === 'Y',
			loginSmtp: (string)($fields['login_smtp'] ?? ''),
			passwordSMTP: (string)($fields['pass_smtp'] ?? ''),
			useLimitSmtp: ($fields['use_limit_smtp'] ?? 'N') === 'Y',
			limitSmtp: (int)($fields['limit_smtp'] ?? 0),
			mailboxName: (string)($fields['name'] ?? ''),
			senderName: (string)($fields['sender'] ?? ''),
			iCalAccess: ($fields['ical_access'] ?? 'N') === 'Y',
			crmOptions: $crmOptions,
			userIdToConnect: $userId,
			messageMaxAge: self::resolveMessageMaxAge($fields),
			uploadOutgoing: (int)($fields['upload_outgoing'] ?? 0) === 1,
			link: (string)($fields['link'] ?? ''),
			shareAccess: $shareAccess,
			useSenderName: ($fields['use_sender_name'] ?? 'N') === 'Y',
			site: $site,
		);
	}

	private static function createFromFlatRequest(HttpRequest $request): self
	{
		$email = $request->get('email');
		$login = $request->get('login');

		if ($email === null && ($login !== null || $request->get('loginWithoutDomain') !== null))
		{
			$email = (string)($login ?: $request->get('loginWithoutDomain'));
			$loginWithoutDomain = (string)$request->get('loginWithoutDomain');
			$login = $loginWithoutDomain !== '' ? $loginWithoutDomain : $email;
		}
		else
		{
			$email = $email !== null ? (string)$email : null;
			$login = $login !== null ? (string)$login : null;
		}

		return new self(
			email: $email,
			login: $login,
			password: $request->get('password') !== null ? (string)$request->get('password') : null,
			serviceId: $request->get('serviceId') !== null ? (int)$request->get('serviceId') : null,
			server: $request->get('server') !== null ? (string)$request->get('server') : null,
			port: $request->get('port') !== null ? (string)$request->get('port') : null,
			ssl: self::toBoolFlag($request->get('ssl')),
			storageOauthUid: $request->get('storageOauthUid') !== null ? (string)$request->get('storageOauthUid') : null,
			syncAfterConnection: self::toBoolFlag($request->get('syncAfterConnection')),
			useSmtp: self::toBoolFlag($request->get('useSmtp')),
			serverSmtp: $request->get('serverSmtp') !== null ? (string)$request->get('serverSmtp') : null,
			portSmtp: $request->get('portSmtp') !== null ? (string)$request->get('portSmtp') : null,
			sslSmtp: self::toBoolFlag($request->get('sslSmtp')),
			loginSmtp: $request->get('loginSmtp') !== null ? (string)$request->get('loginSmtp') : null,
			passwordSMTP: $request->get('passwordSMTP') !== null ? (string)$request->get('passwordSMTP') : null,
			useLimitSmtp: self::toBoolFlag($request->get('useLimitSmtp')),
			limitSmtp: $request->get('limitSmtp') !== null ? (int)$request->get('limitSmtp') : null,
			mailboxName: $request->get('mailboxName') !== null ? (string)$request->get('mailboxName') : null,
			senderName: $request->get('senderName') !== null ? (string)$request->get('senderName') : null,
			iCalAccess: self::toBoolFlag($request->get('iCalAccess')),
			crmOptions: self::normalizeCrmOptions($request->get('crmOptions')),
			userIdToConnect: $request->get('userIdToConnect') !== null ? (int)$request->get('userIdToConnect') : null,
			messageMaxAge: $request->get('messageMaxAge') !== null ? (int)$request->get('messageMaxAge') : null,
			serviceConfig: $request->get('serviceConfig'),
			shareAccess: $request->get('shareAccess'),
			useSenderName: self::toBoolFlag($request->get('useSenderName')),
		);
	}

	private static function createFromMailboxArray(array $mailbox): self
	{
		return new self(
			email: isset($mailbox['email']) ? (string)$mailbox['email'] : null,
			login: isset($mailbox['login']) ? (string)$mailbox['login'] : null,
			password: isset($mailbox['password']) ? (string)$mailbox['password'] : null,
			serviceId: isset($mailbox['serviceId']) ? (int)$mailbox['serviceId'] : null,
			server: isset($mailbox['server']) ? (string)$mailbox['server'] : null,
			port: isset($mailbox['port']) ? (string)$mailbox['port'] : null,
			ssl: self::toBoolFlag($mailbox['ssl'] ?? null),
			storageOauthUid: isset($mailbox['storageOauthUid']) ? (string)$mailbox['storageOauthUid'] : null,
			syncAfterConnection: self::toBoolFlag($mailbox['syncAfterConnection'] ?? null),
			useSmtp: self::toBoolFlag($mailbox['useSmtp'] ?? null),
			serverSmtp: isset($mailbox['serverSmtp']) ? (string)$mailbox['serverSmtp'] : null,
			portSmtp: isset($mailbox['portSmtp']) ? (string)$mailbox['portSmtp'] : null,
			sslSmtp: self::toBoolFlag($mailbox['sslSmtp'] ?? null),
			loginSmtp: isset($mailbox['loginSmtp']) ? (string)$mailbox['loginSmtp'] : null,
			passwordSMTP: isset($mailbox['passwordSMTP']) ? (string)$mailbox['passwordSMTP'] : null,
			useLimitSmtp: $mailbox['useLimitSmtp'] ?? null,
			limitSmtp: $mailbox['limitSmtp'] ?? null,
			mailboxName: isset($mailbox['mailboxName']) ? (string)$mailbox['mailboxName'] : null,
			senderName: isset($mailbox['senderName']) ? (string)$mailbox['senderName'] : null,
			iCalAccess: self::toBoolFlag($mailbox['iCalAccess'] ?? null),
			crmOptions: self::normalizeCrmOptions($mailbox['crmOptions'] ?? null),
			userIdToConnect: isset($mailbox['userIdToConnect']) ? (int)$mailbox['userIdToConnect'] : null,
			messageMaxAge: isset($mailbox['messageMaxAge']) ? (int)$mailbox['messageMaxAge'] : null,
			serviceConfig: $mailbox['serviceConfig'] ?? null,
			shareAccess: $mailbox['shareAccess'] ?? null,
			service: $mailbox['service'] ?? null,
			site: $mailbox['site'] ?? null,
		);
	}

	/**
	 * @param int|string|null $value
	 */
	private static function toBoolFlag(mixed $value): ?bool
	{
		if ($value === null)
		{
			return null;
		}

		if ($value === 'Y' || $value === 'N')
		{
			return $value === 'Y';
		}

		return (bool)$value;
	}

	private static function prepareCrmOptions(array $fields): CrmOptions
	{
		if (empty($fields[CrmFormField::UseCrm->value]) || $fields[CrmFormField::UseCrm->value] !== 'Y')
		{
			return CrmOptions::disabled();
		}

		$syncDays = null;
		if ($fields[CrmFormField::SyncOld->value] === 'Y' && isset($fields[CrmFormField::MaxAge->value]))
		{
			$maxAge = (int)$fields[CrmFormField::MaxAge->value];
			if ($maxAge >= 0)
			{
				$syncDays = $maxAge;
			}
		}

		$newLeadFor = [];
		if (!empty($fields[CrmFormField::NewLeadFor->value]))
		{
			$parsed = preg_split('/[\r\n,;]+/', $fields[CrmFormField::NewLeadFor->value]);
			foreach ($parsed as $item)
			{
				$address = new Address($item, ['checkingPunycode' => true]);
				if ($address->validate())
				{
					$newLeadFor[] = $address->getEmail();
				}
			}

			$newLeadFor = array_values(array_unique($newLeadFor));
		}

		return new CrmOptions(
			enabled: true,
			newEntityIn: ($fields[CrmFormField::AllowEntityIn->value] ?? '') === 'Y'
				? CrmEntityType::tryFrom((string)($fields[CrmFormField::EntityIn->value] ?? ''))
				: null,
			newEntityOut: ($fields[CrmFormField::AllowEntityOut->value] ?? '') === 'Y'
				? CrmEntityType::tryFrom((string)($fields[CrmFormField::EntityOut->value] ?? ''))
				: null,
			leadSource: isset($fields[CrmFormField::LeadSource->value]) ? (string)$fields[CrmFormField::LeadSource->value] : null,
			leadResp: self::prepareQueueUsersForLeadResp($fields),
			newLeadFor: $newLeadFor,
			public: ($fields[CrmFormField::Public->value] ?? '') === 'Y',
			vcf: ($fields[CrmFormField::Vcf->value] ?? 'N') === 'Y',
			syncDays: $syncDays,
		);
	}

	private static function normalizeCrmOptions(mixed $options): ?CrmOptions
	{
		if (!is_array($options))
		{
			return null;
		}

		return CrmOptions::fromArray($options);
	}

	private static function prepareShareAccessWithOwner(array $fields, int $userId): array
	{
		$ownerAccessCode = 'U' . $userId;
		$access = [$ownerAccessCode];

		$parsed = self::parseShareAccessFromFields($fields);
		if (!empty($parsed))
		{
			$access = array_merge($access, $parsed);
		}

		return array_unique($access);
	}

	private static function parseShareAccessFromFields(array $fields): array
	{
		$emptyJsonValue = Json::encode([]);
		$shareAccess = (array)Json::decode((string)($fields['share_access'] ?? $emptyJsonValue));
		if (!empty($shareAccess))
		{
			return array_values(array_unique($shareAccess));
		}

		if (empty($fields['access']) || !is_array($fields['access']))
		{
			return [];
		}

		$access = [];
		foreach ($fields['access'] as $code => $list)
		{
			if (!in_array($code, ['U', 'DR', 'D']) || !is_array($list))
			{
				continue;
			}

			$pattern = sprintf('/^%s\d+$/i', preg_quote($code, '/'));
			$access[] = array_filter(
				$list,
				fn($item) => preg_match($pattern, trim($item)),
			);
		}

		return array_values(array_unique(array_merge(...$access)));
	}

	private static function prepareQueueUsersForLeadResp(array $fields): array
	{
		if (empty($fields[CrmFormField::Queue->value]))
		{
			return [];
		}

		$queueUsers = Json::decode($fields[CrmFormField::Queue->value]);
		if (empty($queueUsers))
		{
			return [];
		}

		$userIds = [];
		foreach ($queueUsers as $item)
		{
			$userId = (int)self::parseUserCode($item);
			if ($userId > 0)
			{
				$userIds[] = $userId;
			}
		}

		return $userIds;
	}

	private static function parseUserCode(?string $code): ?int
	{
		$code = trim((string)$code);

		if (preg_match('/^U(\d+)$/i', $code, $matches))
		{
			return (int)$matches[1];
		}

		return null;
	}

	private static function resolveMessageMaxAge(array $fields): ?int
	{
		if (($fields['mail_connect_import_messages'] ?? '') !== 'Y')
		{
			return 0;
		}

		if (isset($fields['msg_max_age']))
		{
			return (int)$fields['msg_max_age'];
		}

		return null;
	}
}
