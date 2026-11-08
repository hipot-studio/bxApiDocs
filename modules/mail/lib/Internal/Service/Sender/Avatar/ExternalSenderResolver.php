<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Sender\Avatar;

use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Mail\Internal\Service\User\EmployeeAddressClassifier;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\Internals\Service\Mailbox\EmailNormalizer;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Error;
use Bitrix\Main\Mail\Address;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Result;

final class ExternalSenderResolver
{
	public const ERROR_ACCESS_DENIED = MessageAccessCheck::ERROR_ACCESS_DENIED;
	public const ERROR_INVALID_SENDER = 'MAIL_SENDER_AVATAR_INVALID_SENDER';

	private \Closure $loadMessageEnvelope;
	private \Closure $loadMessageDetails;
	private \Closure $isEmployeeEmail;

	/**
	 * @param \Closure|null $isEmployeeEmail fn(string $email, bool $requireCurrentStatus): ?bool
	 */
	public function __construct(
		private readonly MessageAccessCheck $messageAccess,
		?\Closure $loadMessageEnvelope = null,
		?\Closure $loadMessageDetails = null,
		?\Closure $isEmployeeEmail = null,
	)
	{
		$this->loadMessageEnvelope = $loadMessageEnvelope ?? $this->loadMessageEnvelope(...);
		$this->loadMessageDetails = $loadMessageDetails ?? $this->loadMessageDetails(...);
		$this->isEmployeeEmail = $isEmployeeEmail ?? self::employeeClassification();
	}

	/**
	 * @param bool $requireCurrentEmployeeStatus the caller is about to bind a shared photo to the address,
	 *     so the status of the sender is the boundary of the feature and not a hint for the display of a
	 *     photo: a remembered answer is not good enough for it.
	 */
	public function resolve(
		int $messageId,
		int $userId,
		string $accessToken = '',
		bool $requireCurrentEmployeeStatus = false,
	): Result
	{
		if ($messageId <= 0 || $userId <= 0)
		{
			return $this->failure(self::ERROR_ACCESS_DENIED);
		}
		$envelope = ($this->loadMessageEnvelope)($messageId);
		$mailboxId = (int)($envelope['MAILBOX_ID'] ?? 0);
		if ((int)($envelope['ID'] ?? 0) !== $messageId || $mailboxId <= 0)
		{
			return $this->failure(self::ERROR_ACCESS_DENIED);
		}
		$access = $this->messageAccess->check($mailboxId, $messageId, $userId, $accessToken);
		if (!$access->isSuccess())
		{
			return $this->failure(self::ERROR_ACCESS_DENIED);
		}

		$details = ($this->loadMessageDetails)($messageId, $mailboxId);
		$address = new Address((string)($details['FIELD_FROM'] ?? ''));
		if (!$address->validate())
		{
			return $this->failure(self::ERROR_INVALID_SENDER);
		}
		$normalizer = new EmailNormalizer();
		$email = $normalizer->normalize($address->getEmail());
		if ($email === null)
		{
			return $this->failure(self::ERROR_INVALID_SENDER);
		}
		$isOutcome = $details['IS_OUTCOME'] ?? null;
		if ($isOutcome === true || $isOutcome === 1 || $isOutcome === '1')
		{
			return $this->failure(self::ERROR_INVALID_SENDER);
		}
		if ($isOutcome === null)
		{
			foreach (['MAILBOX_EMAIL', 'MAILBOX_NAME', 'MAILBOX_LOGIN'] as $field)
			{
				if ($normalizer->normalize((string)($details[$field] ?? '')) === $email)
				{
					return $this->failure(self::ERROR_INVALID_SENDER);
				}
			}
		}
		try
		{
			$isEmployee = ($this->isEmployeeEmail)($email, $requireCurrentEmployeeStatus);
		}
		catch (\Throwable)
		{
			$isEmployee = null;
		}
		if ($isEmployee !== false)
		{
			return $this->failure(self::ERROR_INVALID_SENDER);
		}

		return (new Result())->setData([
			'messageId' => $messageId,
			'mailboxId' => $mailboxId,
			'email' => $email,
		]);
	}

	private static function employeeClassification(): \Closure
	{
		$classifier = new EmployeeAddressClassifier();

		return static fn(string $email, bool $requireCurrent): ?bool => $requireCurrent
			? $classifier->classifyCurrent($email)
			: $classifier->classify($email)
		;
	}

	private function loadMessageEnvelope(int $messageId): ?array
	{
		return MailMessageTable::query()
			->setSelect(['ID', 'MAILBOX_ID'])
			->where('ID', $messageId)
			->fetch() ?: null
		;
	}

	private function loadMessageDetails(int $messageId, int $mailboxId): ?array
	{
		$message = MailMessageTable::query()
			->setSelect(['FIELD_FROM'])
			->where('ID', $messageId)
			->where('MAILBOX_ID', $mailboxId)
			->fetch()
		;
		$mailbox = MailboxTable::query()
			->setSelect(['EMAIL', 'NAME', 'LOGIN'])
			->where('ID', $mailboxId)
			->fetch()
		;
		if (!$message || !$mailbox)
		{
			return null;
		}

		return [
			'FIELD_FROM' => $message['FIELD_FROM'],
			'MAILBOX_EMAIL' => $mailbox['EMAIL'],
			'MAILBOX_NAME' => $mailbox['NAME'],
			'MAILBOX_LOGIN' => $mailbox['LOGIN'],
			'IS_OUTCOME' => $this->resolveOutcomeDirection($messageId, $mailboxId),
		];
	}

	private function resolveOutcomeDirection(int $messageId, int $mailboxId): ?bool
	{
		$scope = GenerationScope::forMailbox($mailboxId);
		$filter = $scope->apply([
			'=MESSAGE_ID' => $messageId,
			'=MAILBOX_ID' => $mailboxId,
			'=DELETE_TIME' => 0,
		]);
		$filter = $scope->apply($filter, 'DIRECTORY.');
		$rows = MailMessageUidTable::getList([
			'runtime' => [
				new Reference(
					'DIRECTORY',
					MailboxDirectoryTable::class,
					Join::on('this.MAILBOX_ID', 'ref.MAILBOX_ID')
						->whereColumn('this.DIR_MD5', 'ref.DIR_MD5'),
				),
			],
			'select' => ['IS_OUTCOME' => 'DIRECTORY.IS_OUTCOME'],
			'filter' => $filter,
		])->fetchAll();
		if ($rows === [])
		{
			return null;
		}
		foreach ($rows as $row)
		{
			if ((int)$row['IS_OUTCOME'] === MailboxDirectoryTable::ACTIVE)
			{
				return true;
			}
		}

		return false;
	}

	private function failure(string $code): Result
	{
		return (new Result())->addError(new Error($code, $code));
	}
}
