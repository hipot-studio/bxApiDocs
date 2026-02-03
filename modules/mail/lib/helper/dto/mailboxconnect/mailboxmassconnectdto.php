<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Dto\MailboxConnect;

use Bitrix\Main\HttpRequest;

final class MailboxMassconnectDTO extends AbstractMailboxConnectDTO
{
	public static function createFromRequest(HttpRequest $request): self
	{
		$mailbox = $request->get('mailbox');

		$constructorData = [
			'email' => (string)$mailbox['email'],
			'login' => (string)$mailbox['login'],
			'password' => (string)$mailbox['password'],
			'serviceId' => (int)$mailbox['serviceId'],
			'server' => (string)$mailbox['server'],
			'port' => (string)$mailbox['port'],
			'ssl' => (string)($mailbox['ssl']),
			'storageOauthUid' => (string)$mailbox['storageOauthUid'],
			'syncAfterConnection' => (string)($mailbox['syncAfterConnection']),
			'useSmtp' => (string)($mailbox['useSmtp']),
			'serverSmtp' => (string)$mailbox['serverSmtp'],
			'portSmtp' => (string)$mailbox['portSmtp'],
			'sslSmtp' => (string)($mailbox['sslSmtp']),
			'loginSmtp' => (string)$mailbox['loginSmtp'],
			'passwordSMTP' => (string)$mailbox['passwordSMTP'],
			'useLimitSmtp' => $mailbox['useLimitSmtp'],
			'limitSmtp' => $mailbox['limitSmtp'],
			'mailboxName' => (string)$mailbox['mailboxName'],
			'senderName' => (string)$mailbox['senderName'],
			'iCalAccess' => (string)$mailbox['iCalAccess'],
			'crmOptions' => $mailbox['crmOptions'],
			'userIdToConnect' => (int)$mailbox['userIdToConnect'],
			'service' => $mailbox['service'],
			'site' => $mailbox['site'],
			'messageMaxAge' => (int)$mailbox['messageMaxAge'],
			'serviceConfig' => $mailbox['serviceConfig'],
		];

		$constructorData = array_filter($constructorData);

		return new self(...$constructorData);
	}
}
