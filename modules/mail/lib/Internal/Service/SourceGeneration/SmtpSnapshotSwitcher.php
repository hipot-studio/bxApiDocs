<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Internal\Service\Mailbox\SettingsWriteCoordinator;
use Bitrix\Mail\Internal\Service\SenderCacheCleaner;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Mail\Internal\SenderTable;
use Bitrix\Main\Mail\Sender;
use Bitrix\Main\Result;

class SmtpSnapshotSwitcher
{
	public const ERROR_CONNECTION_UNAVAILABLE = 'MAIL_SOURCE_GENERATION_SMTP_CONNECTION_UNAVAILABLE';
	public const ERROR_SENDER_AMBIGUOUS = 'MAIL_SOURCE_GENERATION_SMTP_SENDER_AMBIGUOUS';
	public const ERROR_SENDER_WRITE_FAILED = 'MAIL_SOURCE_GENERATION_SMTP_SENDER_WRITE_FAILED';

	public function prepare(ConnectionSnapshot $snapshot): Result
	{
		$result = new Result();
		$smtp = $snapshot->smtp();

		$config = [
			'server' => (string)($smtp['SERVER'] ?? ''),
			'port' => (int)($smtp['PORT'] ?? 0),
			'protocol' => (string)($smtp['PROTOCOL'] ?? ''),
			'login' => (string)($smtp['LOGIN'] ?? ''),
			'password' => (string)($smtp['PASSWORD'] ?? ''),
			'isOauth' => false,
		];
		if (isset($smtp['LIMIT']))
		{
			$config['limit'] = (int)$smtp['LIMIT'];
		}
		$preparedLimit = $config['limit'] ?? null;

		$validated = $this->validateConnection($config);
		if (!$validated->isSuccess())
		{
			foreach ($validated->getErrors() as $error)
			{
				$customData = $error->getCustomData();
				$type = is_array($customData) ? ($customData['type'] ?? null) : null;
				$result->addError(new Error(
					MigrationMetrics::withoutSecretsOf($config, $error->getMessage()),
					self::ERROR_CONNECTION_UNAVAILABLE,
					is_string($type) ? ['type' => $type] : null,
				));
			}

			return $result;
		}
		if ($preparedLimit !== null)
		{
			$config['limit'] = $preparedLimit;
		}

		return $result->setData(['smtp' => $config]);
	}

	protected function validateConnection(array &$smtp): Result
	{
		return Sender::prepareSmtpConfigForSender($smtp);
	}

	/**
	 * Joins the caller transaction; cache effects belong to its after-commit phase.
	 * @param array<string, mixed> $mailbox
	 * @param array<string, mixed> $smtp
	 */
	public function applyWithinTransaction(
		int $mailboxId,
		string $email,
		array $mailbox,
		array $smtp,
	): Result
	{
		$result = new Result();
		$connection = Application::getConnection();
		$senders = $connection->query(sprintf(
			"SELECT * FROM %s WHERE PARENT_MODULE_ID = 'mail' AND PARENT_ID = %u ORDER BY ID FOR UPDATE",
			SenderTable::getTableName(),
			$mailboxId,
		))->fetchAll();
		if (count($senders) > 1)
		{
			return $result->addError(new Error(
				sprintf('The mailbox %u has more than one SMTP sender', $mailboxId),
				self::ERROR_SENDER_AMBIGUOUS,
			));
		}
		$sender = $senders[0] ?? null;
		if ($sender !== null)
		{
			$sender['OPTIONS'] = SettingsWriteCoordinator::decodeStoredField(
				SenderTable::class,
				'OPTIONS',
				$sender['OPTIONS'],
			);
		}

		$options = is_array($sender['OPTIONS'] ?? null)
			? $sender['OPTIONS']
			: ['source' => 'mail.client.config'];
		$options['smtp'] = $smtp;
		$storedOptions = SenderTable::getEntity()
			->getField('OPTIONS')
			->modifyValueBeforeSave($options, ['OPTIONS' => $options])
		;

		if ($sender === null)
		{
			$senderId = (int)$connection->add(SenderTable::getTableName(), [
				'EMAIL' => $email,
				'NAME' => (string)($mailbox['USERNAME'] ?? ''),
				'USER_ID' => (int)($mailbox['USER_ID'] ?? 0),
				'IS_CONFIRMED' => 1,
				'IS_PUBLIC' => 0,
				'OPTIONS' => $storedOptions,
				'PARENT_MODULE_ID' => 'mail',
				'PARENT_ID' => $mailboxId,
			]);
			if ($senderId <= 0)
			{
				return $result->addError(new Error(
					'The mailbox SMTP sender was not created',
					self::ERROR_SENDER_WRITE_FAILED,
				));
			}

			return $result->setData(['cacheChange' => [
				'senderId' => $senderId,
				'oldEmail' => null,
				'newEmail' => $email,
			]]);
		}

		$senderId = (int)$sender['ID'];
		$sqlHelper = $connection->getSqlHelper();
		[$update, $binds] = $sqlHelper->prepareUpdate(SenderTable::getTableName(), [
			'EMAIL' => $email,
			'IS_CONFIRMED' => 1,
			'OPTIONS' => $storedOptions,
		]);
		$connection->queryExecute(sprintf(
			'UPDATE %s SET %s WHERE ID = %u',
			SenderTable::getTableName(),
			$update,
			$senderId,
		), $binds);

		return $result->setData(['cacheChange' => [
			'senderId' => $senderId,
			'oldEmail' => (string)$sender['EMAIL'],
			'newEmail' => $email,
		]]);
	}

	/** @param array{senderId: int, oldEmail: ?string, newEmail: string} $change */
	public function afterCommit(array $change, int $mailboxId): void
	{
		$senderId = (int)$change['senderId'];
		$oldEmail = $change['oldEmail'] ?? null;
		$newEmail = (string)$change['newEmail'];

		(new SenderCacheCleaner())->clearForSender(
			$senderId,
			[is_string($oldEmail) ? $oldEmail : '', $newEmail],
			[['parentModuleId' => 'mail', 'parentId' => $mailboxId]],
		);
	}
}
