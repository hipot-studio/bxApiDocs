<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Access;

use Bitrix\Mail\Helper\AnalyticsHelper;
use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Security\Sign\HmacAlgorithm;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Web\Uri;

class MessageAccessService
{
	/**
	 * @param int $mailboxId
	 * @param int $messageId
	 * @param int $mailUserFieldId
	 * @param int $entityId
	 * @param int $userId
	 *
	 * @return array [bool hasAccess, array|null accessData]
	 */
	public function canRead(
		int $mailboxId,
		int $messageId,
		int $mailUserFieldId,
		int $entityId,
		int $userId,
	): array
	{
		$filter = [
			'=MAILBOX_ID' => $mailboxId,
			'=MESSAGE_ID' => $messageId,
			'=ENTITY_UF_ID' => $mailUserFieldId,
			'=ENTITY_ID' => $entityId,
		];

		$access = MessageAccessTable::getList(['filter' => $filter, 'limit' => 1])->fetch();

		if (!empty($access))
		{
			return [true, $access];
		}

		if (MailboxTable::getUserMailbox($mailboxId, $userId))
		{
			return [true, null];
		}

		return [false, null];
	}

	/**
	 * @throws ArgumentTypeException
	 */
	public function getLinkWithToken(string $link, array $access, int $userId): string
	{
		$signer = new Signer(new HmacAlgorithm('md5'));

		$token = sprintf(
			'%s:%s',
			$access['TOKEN'],
			$signer->getSignature($access['SECRET'], sprintf('user%u', $userId)),
		);

		$link = AnalyticsHelper::addSourceAnalyticsToMessage($link, $access['ENTITY_TYPE'] ?? '');
		$uri = new Uri($link);
		$uri->addParams(['mail_uf_message_token' => $token]);

		return $uri->getUri();
	}
}
