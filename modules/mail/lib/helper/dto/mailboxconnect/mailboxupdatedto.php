<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Dto\MailboxConnect;

use Bitrix\Main\HttpRequest;

final class MailboxUpdateDTO
{
	public function __construct(
		public ?string $email = null,
		public ?string $login = null,
		public ?string $password = null,
		public ?int $serviceId = null,
		public ?string $server = null,
		public ?string $port = null,
		public ?string $ssl = null,
		/** OAuth UID from socialservices storage. Must be provided for OAuth mailboxes, not recoverable from existing data */
		public ?string $storageOauthUid = null,
		public ?string $syncAfterConnection = null,
		public ?string $useSmtp = null,
		public ?string $serverSmtp = null,
		public ?string $portSmtp = null,
		public ?string $sslSmtp = null,
		public ?string $loginSmtp = null,
		public ?string $passwordSMTP = null,
		public ?bool $useLimitSmtp = null,
		public ?int $limitSmtp = null,
		public ?string $mailboxName = null,
		public ?string $senderName = null,
		public ?string $iCalAccess = null,
		/**
		 * @var ?array{
		 *     enabled: 'Y'|'N',
		 *     config: array{
		 *         crm_new_entity_in?: 'Lead'|'Contact',
		 *         crm_new_entity_out?: 'Lead'|'Contact',
		 *         crm_lead_source?: string,
		 *         crm_lead_resp?: int[],
		 *         crm_new_lead_for?: string,
		 *         crm_public?: 'Y'|'N',
		 *         crm_sync_days?: int,
		 *     },
		 * }
		 */
		public ?array $crmOptions = null,
		public ?int $userIdToConnect = null,
		public ?int $messageMaxAge = null,
		/** @var ?array{serviceType: string, name: string} */
		public ?array $serviceConfig = null,
		/** @var ?string[] Access codes, e.g. ['U1', 'U5', 'DR3'] */
		public ?array $shareAccess = null,
		public ?bool $useSenderName = null,
	)
	{
	}

	public static function createFromRequest(HttpRequest $request): self
	{
		return new self(
			email: $request->get('email') !== null ? (string)$request->get('email') : null,
			login: $request->get('login') !== null ? (string)$request->get('login') : null,
			password: $request->get('password') !== null ? (string)$request->get('password') : null,
			serviceId: $request->get('serviceId') !== null ? (int)$request->get('serviceId') : null,
			server: $request->get('server') !== null ? (string)$request->get('server') : null,
			port: $request->get('port') !== null ? (string)$request->get('port') : null,
			ssl: $request->get('ssl') !== null ? (string)$request->get('ssl') : null,
			storageOauthUid: $request->get('storageOauthUid') !== null ? (string)$request->get('storageOauthUid') : null,
			syncAfterConnection: $request->get('syncAfterConnection') !== null ? (string)$request->get('syncAfterConnection') : null,
			useSmtp: $request->get('useSmtp') !== null ? (string)$request->get('useSmtp') : null,
			serverSmtp: $request->get('serverSmtp') !== null ? (string)$request->get('serverSmtp') : null,
			portSmtp: $request->get('portSmtp') !== null ? (string)$request->get('portSmtp') : null,
			sslSmtp: $request->get('sslSmtp') !== null ? (string)$request->get('sslSmtp') : null,
			loginSmtp: $request->get('loginSmtp') !== null ? (string)$request->get('loginSmtp') : null,
			passwordSMTP: $request->get('passwordSMTP') !== null ? (string)$request->get('passwordSMTP') : null,
			useLimitSmtp: $request->get('useLimitSmtp') !== null ? (bool)$request->get('useLimitSmtp') : null,
			limitSmtp: $request->get('limitSmtp') !== null ? (int)$request->get('limitSmtp') : null,
			mailboxName: $request->get('mailboxName') !== null ? (string)$request->get('mailboxName') : null,
			senderName: $request->get('senderName') !== null ? (string)$request->get('senderName') : null,
			iCalAccess: $request->get('iCalAccess') !== null ? (string)$request->get('iCalAccess') : null,
			crmOptions: $request->get('crmOptions'),
			userIdToConnect: $request->get('userIdToConnect') !== null ? (int)$request->get('userIdToConnect') : null,
			messageMaxAge: $request->get('messageMaxAge') !== null ? (int)$request->get('messageMaxAge') : null,
			serviceConfig: $request->get('serviceConfig'),
			shareAccess: $request->get('shareAccess'),
			useSenderName: $request->get('useSenderName') !== null ? (bool)$request->get('useSenderName') : null,
		);
	}
}
