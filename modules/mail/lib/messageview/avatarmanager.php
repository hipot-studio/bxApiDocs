<?php
namespace Bitrix\Mail\MessageView;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Helper\Message\Loader\QueryBuilder;
use Bitrix\Mail\Integration\HumanResources\ActiveEmployeeIdFilter;
use Bitrix\Mail\Internal\Service\Sender\Avatar\SenderAvatarViewModelBuilder;
use Bitrix\Mail\Internal\Service\User\UserAddressLookup;
use Bitrix\Mail\Internals\MailContactTable;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\Internals\SenderAvatarTable;
use Bitrix\Mail\Internals\Service\Mailbox\EmailNormalizer;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

class AvatarManager
{
	private $currentUserId;
	private string $messageAccessToken;
	private \Closure $filterActiveEmployeeIds;

	public function __construct(
		$currentUserId = null,
		string $messageAccessToken = '',
		?\Closure $filterActiveEmployeeIds = null,
	)
	{
		$this->currentUserId = $currentUserId;
		$this->messageAccessToken = $messageAccessToken;
		$this->filterActiveEmployeeIds = $filterActiveEmployeeIds
			?? static fn(array $userIds): ?array => (new ActiveEmployeeIdFilter())->filter($userIds)
		;
	}

	public static function getAvatarKeyByString($string): ?string
	{
		foreach (Message::parseAddressList($string) as $candidate)
		{
			$address = new Main\Mail\Address($candidate);
			if ($address->validate())
			{
				return $address->getEmail();
			}
		}

		return null;
	}

	public function getAvatarParamsFromEmails($filedFromList)
	{
		$messages = [];
		foreach ($filedFromList as $filed)
		{
			$messages[] = [
				'FIELD_FROM' => $filed,
				'FIELD_TO' => '',
				'FIELD_CC' => '',
				'FIELD_BCC' => '',
			];
		}

		return $this->getAvatarParamsFromMessagesHeaders($messages);
	}

	public function getAvatarParamsFromMessagesHeaders($messages)
	{
		$mailsNames = $this->getEmailsNames($messages);
		$senderAvatarCandidates = $this->collectSenderAvatarCandidates($messages);
		$incomingMessageIds = array_fill_keys($this->fetchIncomingMessageIds($senderAvatarCandidates), true);
		$senderAvatarCandidates = array_intersect_key($senderAvatarCandidates, $incomingMessageIds);

		$mailContacts = $this->fetchMailContacts(array_map(
			function ($item)
			{
				return $item['email'];
			},
			$mailsNames
		));
		$employeeAvatars = $this->fetchEmployeeAvatars(array_values(array_unique(array_column(
			$senderAvatarCandidates,
			'normalized',
		))));
		if ($employeeAvatars === null)
		{
			$senderAvatarCandidates = [];
			$employeeAvatars = [];
		}
		foreach ($senderAvatarCandidates as $candidate)
		{
			$employee = $employeeAvatars[$candidate['normalized']] ?? null;
			if ($employee === null)
			{
				continue;
			}
			$key = $candidate['key'];
			$mailContacts[$key] = array_merge($mailContacts[$key] ?? [], $employee, [
				'EMAIL' => $key,
				'IS_EMPLOYEE' => true,
			]);
		}

		$mailContacts = $this->fillFileIdColumn($mailContacts);
		$senderAvatarEmails = array_fill_keys(array_column($senderAvatarCandidates, 'key'), true);

		foreach ($mailsNames as $email => $data)
		{
			if (!empty($mailContacts[$email]))
			{
				if (
					(!$data['name'] || strcasecmp($data['name'], $data['email']) === 0)
					&& !empty($mailContacts[$email]['NAME'])
					&& $mailContacts[$email]['NAME'] !== $mailContacts[$email]['EMAIL']
				)
				{
					$mailsNames[$email]['name'] = $mailContacts[$email]['NAME'];
				}
				$mailsNames[$email]['mailContact'] = $mailContacts[$email];
			}
			else
			{
				$mailsNames[$email]['mailContact'] = [
					'EMAIL' => $data['email'],
					'NAME' => $data['name'],
				];
			}
			if (isset($senderAvatarEmails[$email]))
			{
				$mailsNames[$email]['SOURCE'] = !empty($mailsNames[$email]['mailContact']['IS_EMPLOYEE'])
					? 'employee'
					: (!empty($mailsNames[$email]['mailContact']['FILE_ID']) ? 'contact' : 'initials')
				;
			}
		}

		$this->appendSenderAvatars($mailsNames, $senderAvatarCandidates);

		return $mailsNames;
	}

	private function collectSenderAvatarCandidates(array $messages): array
	{
		// Without candidates the whole shared photo drops out of the chain at once: no setting is read,
		// no protected url is built and the letter falls back to the personal contact and the initials.
		if (!Feature::isSenderAvatarAvailable())
		{
			return [];
		}

		$candidates = [];
		$normalizer = new EmailNormalizer();
		foreach ($messages as $message)
		{
			$messageId = (int)($message['ID'] ?? 0);
			if (
				$messageId <= 0
				|| empty($message['__access_level'])
			)
			{
				continue;
			}

			$email = self::getAvatarKeyByString((string)($message['FIELD_FROM'] ?? ''));
			$normalized = $email !== null ? $normalizer->normalize($email) : null;
			if (
				$normalized === null
				|| $normalized === $normalizer->normalize((string)($message['__email'] ?? ''))
			)
			{
				continue;
			}

			$candidates[$messageId] = [
				'key' => $email,
				'normalized' => $normalized,
				'mailboxId' => (int)($message['MAILBOX_ID'] ?? 0),
			];
		}

		return $candidates;
	}

	protected function fetchIncomingMessageIds(array $candidates): array
	{
		$mailboxIds = [];
		$messageIds = [];
		foreach ($candidates as $messageId => $candidate)
		{
			$mailboxId = (int)($candidate['mailboxId'] ?? 0);
			if ($mailboxId > 0)
			{
				$mailboxIds[$mailboxId] = $mailboxId;
				$messageIds[(int)$messageId] = (int)$messageId;
			}
		}
		if (empty($mailboxIds))
		{
			return [];
		}

		try
		{
			$directions = [];
			$mailboxIds = array_values($mailboxIds);
			$filter = array_merge(
				[
					'@MAILBOX_ID' => $mailboxIds,
					'@MESSAGE_ID' => array_values($messageIds),
					'=DELETE_TIME' => 0,
				],
				QueryBuilder::generationScopeFilter($mailboxIds, ''),
				QueryBuilder::generationScopeFilter($mailboxIds, 'DIRECTORY.'),
			);
			$rows = MailMessageUidTable::getList([
				'runtime' => [
					new Reference(
						'DIRECTORY',
						MailboxDirectoryTable::class,
						Join::on('this.MAILBOX_ID', 'ref.MAILBOX_ID')
							->whereColumn('this.DIR_MD5', 'ref.DIR_MD5'),
						['join_type' => Join::TYPE_INNER],
					),
				],
				'select' => ['MESSAGE_ID', 'IS_OUTCOME' => 'DIRECTORY.IS_OUTCOME'],
				'filter' => $filter,
			]);
			while ($row = $rows->fetch())
			{
				$messageId = (int)$row['MESSAGE_ID'];
				if ((int)$row['IS_OUTCOME'] === MailboxDirectoryTable::ACTIVE)
				{
					$directions[$messageId] = true;
				}
				elseif (!isset($directions[$messageId]))
				{
					$directions[$messageId] = false;
				}
			}
		}
		catch (\Throwable)
		{
			return [];
		}

		return array_keys(array_filter($directions, static fn(bool $isOutcome): bool => !$isOutcome));
	}

	private function appendSenderAvatars(array &$avatarParams, array $candidates): void
	{
		foreach ($candidates as $messageId => $candidate)
		{
			$key = $candidate['key'];
			if (!isset($avatarParams[$key]) || !empty($avatarParams[$key]['mailContact']['IS_EMPLOYEE']))
			{
				unset($candidates[$messageId]);
			}
		}
		if (empty($candidates))
		{
			return;
		}

		$emails = array_values(array_unique(array_column($candidates, 'normalized')));
		$settings = $this->fetchSenderAvatarSettings($emails);
		$canManage = $this->canManageSenderAvatars();
		$urls = $this->createSenderAvatarUrls($candidates, $settings);
		foreach ($candidates as $messageId => $candidate)
		{
			$key = $candidate['key'];
			$setting = $settings[$candidate['normalized']] ?? [];
			$revision = (int)($setting['REVISION'] ?? 0);
			$hasAvatar = (int)($setting['RESOURCE_ID'] ?? 0) > 0;
			$url = $hasAvatar ? ($urls[$candidate['normalized']] ?? null) : null;
			$management = SenderAvatarViewModelBuilder::build(
				$messageId,
				$this->messageAccessToken,
				[
					'revision' => $revision,
					'hasAvatar' => $hasAvatar,
					'canManage' => $canManage,
				],
				$url,
			);
			$messageAvatar = [
				'IMAGE_URL' => $management['url'],
				'SOURCE' => $hasAvatar ? 'sender' : $avatarParams[$key]['SOURCE'],
				'MANAGEMENT' => $management,
			];
			if ($hasAvatar && !empty($management['url']))
			{
				$messageAvatar['CACHE_IDENTITY'] = hash('sha256', $candidate['normalized'] . "\0" . $revision);
			}
			$avatarParams[$key]['senderAvatarByMessageId'][$messageId] = $messageAvatar;
		}
	}

	/**
	 * One sender gets one representative message URL per batch. Access is checked for that message on
	 * every no-store request. The smallest ID makes the URL independent of row order; the list may reuse
	 * a decoded image under the sender and revision while an authorized row remains present.
	 *
	 * @return array<string, string>
	 */
	private function createSenderAvatarUrls(array $candidates, array $settings): array
	{
		$firstMessageIds = [];
		foreach ($candidates as $messageId => $candidate)
		{
			$email = $candidate['normalized'];
			$messageId = (int)$messageId;
			if (!isset($firstMessageIds[$email]) || $messageId < $firstMessageIds[$email])
			{
				$firstMessageIds[$email] = $messageId;
			}
		}

		$urls = [];
		foreach ($firstMessageIds as $email => $messageId)
		{
			$setting = $settings[$email] ?? [];
			if ((int)($setting['RESOURCE_ID'] ?? 0) > 0)
			{
				$urls[$email] = $this->createSenderAvatarUrl($messageId, (int)($setting['REVISION'] ?? 0));
			}
		}

		return $urls;
	}

	private function getEmailsNames($messages, $fieldList = false)
	{
		$emailNames = [];
		$seenHeaders = [];

		if($fieldList)
		{
			$messages = $fieldList;
		}

		foreach ($messages as $message)
		{
			foreach (['FIELD_FROM', 'FIELD_TO', 'FIELD_CC', 'FIELD_BCC'] as $field)
			{
				$header = (string)($message[$field] ?? '');
				if ($header === '')
				{
					continue;
				}

				$key = "\0" . $header;
				if (isset($seenHeaders[$key]))
				{
					continue;
				}
				$seenHeaders[$key] = true;

				foreach ($this->extractMailsNamesFrom($header) as $emailName)
				{
					$emailNames[] = $emailName;
				}
			}
		}
		$emailNames = $this->getBestNameChoices($emailNames);

		return $emailNames;
	}

	private function getBestNameChoices($emailNames)
	{
		$results = [];
		$bestNames = [];
		foreach ($emailNames as $index => $data)
		{
			if (!isset($bestNames[$data['email']]))
			{
				$bestNames[$data['email']] = $data['name'];
				$results[$data['email']] = [
					'email' => $data['email'],
					'name' => $data['name'],
				];
				continue;
			}
			$newName = $data['name'];
			$oldName = $bestNames[$data['email']];
			if (!$oldName || strcasecmp($oldName, $data['email']) === 0)
			{
				$bestNames[$data['email']] = $newName;
				$results[$data['email']] = [
					'email' => $data['email'],
					'name' => $newName,
				];
			}
		}

		return $results;
	}

	private function extractMailsNamesFrom($parsedListOfEmails)
	{
		$emailNames = [];

		if ($parsedListOfEmails)
		{
			foreach (Message::parseAddressList($parsedListOfEmails) as $mailCopy)
			{
				$address = new Main\Mail\Address(trim($mailCopy));
				if ($address->validate())
				{
					$name = \Bitrix\Mail\Message::stripQuotes($address->getName());
					$emailNames[] = [
						'email' => $address->getEmail(),
						'name' => $name ?: $address->getEmail(),
					];
				}
			}
		}
		return $emailNames;
	}

	private function fillFileIdColumn($mailContacts)
	{
		foreach ($mailContacts as $mail => $mailContact)
		{
			if (!empty($mailContacts[$mail]['AVATAR_ID']))
			{
				$mailContacts[$mail]['FILE_ID'] = $mailContacts[$mail]['AVATAR_ID'];
			}
		}
		return $mailContacts;
	}

	protected function fetchMailContacts(array $emails): array
	{
		if (empty($emails))
		{
			return [];
		}

		static $cachedContacts = [];

		$newEmails = array_diff($emails, array_keys($cachedContacts));

		if (!empty($newEmails))
		{
			$fetchedContacts = MailContactTable::getList(array(
				'runtime' => array(
					new Main\ORM\Fields\Relations\Reference(
						'USER',
						Main\UserTable::class,
						array(
							'=this.EMAIL' => 'ref.EMAIL',
							'=ref.ACTIVE' => new Main\DB\SqlExpression('?', 'Y'),
						)
					)
				),
				'select' => array(
					'NAME', 'EMAIL', 'ICON', 'FILE_ID',
					'AVATAR_ID' => 'USER.PERSONAL_PHOTO',
				),
				'filter' => array(
					'=USER_ID' => $this->currentUserId,
					'@EMAIL' => $emails,
				),
			))->fetchAll();

			foreach ($fetchedContacts as $contact)
			{
				$cachedContacts[$contact['EMAIL']] = $contact;
			}
		}

		return array_intersect_key($cachedContacts, array_flip($emails));
	}

	protected function fetchEmployeeAvatars(array $emails): ?array
	{
		if (empty($emails))
		{
			return [];
		}

		try
		{
			$employees = [];
			$normalizer = new EmailNormalizer();
			$users = $this->fetchUsersByEmails($emails);
			$userIds = [];
			foreach ($users as $user)
			{
				$userId = (int)($user['ID'] ?? 0);
				if ($userId > 0)
				{
					$userIds[$userId] = $userId;
				}
			}
			if (empty($userIds))
			{
				return [];
			}
			$employeeIds = ($this->filterActiveEmployeeIds)(array_values($userIds));
			if (!is_array($employeeIds))
			{
				return null;
			}
			$employeeIdSet = array_fill_keys(array_map('intval', $employeeIds), true);
			foreach ($users as $user)
			{
				if (!isset($employeeIdSet[(int)$user['ID']]))
				{
					continue;
				}
				$email = $normalizer->normalize((string)$user['EMAIL']);
				if ($email !== null)
				{
					$employees[$email] = ['AVATAR_ID' => (int)$user['PERSONAL_PHOTO']];
				}
			}

			return $employees;
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	protected function fetchUsersByEmails(array $emails): array
	{
		return (new UserAddressLookup())->fetchActiveUsers($emails, ['ID', 'EMAIL', 'PERSONAL_PHOTO']);
	}

	protected function fetchSenderAvatarSettings(array $emails): array
	{
		if (empty($emails))
		{
			return [];
		}

		$settings = [];
		$rows = SenderAvatarTable::getList([
			'select' => ['EMAIL_NORMALIZED', 'RESOURCE_ID', 'REVISION'],
			'filter' => ['@EMAIL_NORMALIZED' => $emails],
		]);
		while ($row = $rows->fetch())
		{
			$settings[(string)$row['EMAIL_NORMALIZED']] = $row;
		}

		return $settings;
	}

	protected function canManageSenderAvatars(): bool
	{
		return MailAccess::hasCurrentUserAccessToSenderAvatarManagement();
	}

	protected function createSenderAvatarUrl(int $messageId, int $revision): string
	{
		$parameters = [
			'messageId' => $messageId,
			'revision' => $revision,
		];
		if ($this->messageAccessToken !== '')
		{
			$parameters['messageAccessToken'] = $this->messageAccessToken;
		}

		return Main\Engine\UrlManager::getInstance()
			->create('mail.api.senderavatar.view', $parameters)
			->getUri()
		;
	}
}
