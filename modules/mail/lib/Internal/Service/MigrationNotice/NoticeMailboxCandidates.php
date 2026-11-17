<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MigrationNotice;

use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\MailServicesTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

final class NoticeMailboxCandidates
{
	private const PAGE_SIZE = 100;

	/**
	 * Every active IMAP mailbox of the portal, whatever its provider: the consent panel of the mailbox
	 * settings is shown whenever the New mail is on, and not only to the portals with Yandex or Mail.ru.
	 *
	 * @param array<int, true>|null $onlyIds
	 * @return iterable<int, array<int, array{id: int, serviceName: string, email: string}>>
	 */
	public function iterateForMailboxSettings(?array $onlyIds = null): iterable
	{
		if ($onlyIds !== null)
		{
			$mailboxIds = array_map('intval', array_keys($onlyIds));
			sort($mailboxIds, SORT_NUMERIC);
			foreach (array_chunk($mailboxIds, self::PAGE_SIZE) as $mailboxIdPage)
			{
				$candidates = $this->readPage(['@ID' => $mailboxIdPage]);
				if ($candidates !== [])
				{
					yield $candidates;
				}
			}

			return;
		}

		$afterId = 0;
		do
		{
			$candidates = $this->readPage(['>ID' => $afterId], self::PAGE_SIZE);
			if ($candidates === [])
			{
				return;
			}

			yield $candidates;
			$afterId = $candidates[array_key_last($candidates)]['id'];
		}
		while (count($candidates) === self::PAGE_SIZE);
	}

	/**
	 * @param array<string, mixed> $extraFilter
	 * @return array<int, array{id: int, serviceName: string, email: string}>
	 */
	private function readPage(array $extraFilter, ?int $limit = null): array
	{
		$filter = array_merge([
			'=ACTIVE' => 'Y',
			'=SERVER_TYPE' => 'imap',
		], $extraFilter);

		$queryParameters = [
			'select' => ['ID', 'EMAIL', 'SERVICE_NAME' => 'SERVICE.NAME'],
			'filter' => $filter,
			'order' => ['ID' => 'ASC'],
			'runtime' => [
				new Reference(
					'SERVICE',
					MailServicesTable::class,
					Join::on('this.SERVICE_ID', 'ref.ID'),
					['join_type' => 'LEFT'],
				),
			],
		];
		if ($limit !== null)
		{
			$queryParameters['limit'] = $limit;
		}

		$query = MailboxTable::getList($queryParameters);

		$candidates = [];
		while ($row = $query->fetch())
		{
			$candidates[] = [
				'id' => (int)$row['ID'],
				'serviceName' => (string)$row['SERVICE_NAME'],
				'email' => (string)$row['EMAIL'],
			];
		}

		return $candidates;
	}
}
