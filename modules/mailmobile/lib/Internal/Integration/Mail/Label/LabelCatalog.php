<?php

declare(strict_types=1);

namespace Bitrix\MailMobile\Internal\Integration\Mail\Label;

use Bitrix\Mail\Internal\Service\Label\LabelService;

/**
 * Labels of the reader for one mailbox, as the mail module states them: the global ones plus those
 * pinned to the mailbox, each with its unread counter. The dependency on the label service of the mail
 * module is kept here, in the integration layer, so the list services of mobile mail do not carry it.
 */
final class LabelCatalog
{
	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function getForMailbox(int $userId, int $mailboxId): array
	{
		$result = (new LabelService())->getList($userId, $mailboxId);

		return $result->isSuccess() ? ($result->getData()['labels'] ?? []) : [];
	}
}
