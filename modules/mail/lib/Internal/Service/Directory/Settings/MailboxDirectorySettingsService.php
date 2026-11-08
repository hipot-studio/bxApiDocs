<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Directory\Settings;

use Bitrix\Mail\Helper\Dto\MailboxConnect\GeneralSettingsPatch;
use Bitrix\Mail\Integration\MailService\MigrationStatusProvider;
use Bitrix\Mail\Internal\Service\Directory\ChildrenLoader;
use Bitrix\Mail\Internal\Service\Directory\SyncFlagsUpdater;
use Bitrix\Mail\Internal\Service\Directory\TypesAssigner;
use Bitrix\Mail\Internal\Service\Mailbox\GeneralSettingsService;
use Bitrix\Mail\Internal\Service\Mailbox\SettingsWriteCoordinator;
use Bitrix\Mail\Internal\Service\SourceGeneration\GenerationScope;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationActionGuard;
use Bitrix\Mail\MailboxDirectory;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

Loc::loadMessages(__FILE__);

final readonly class MailboxDirectorySettingsService
{
	public function __construct(
		private SettingsReader $reader = new SettingsReader(),
		private ChildrenLoader $childrenLoader = new ChildrenLoader(),
		private SyncFlagsUpdater $syncUpdater = new SyncFlagsUpdater(),
		private TypesAssigner $typesAssigner = new TypesAssigner(),
	)
	{
	}

	/**
	 * @return Result data on success: ['settings' => DirectoriesSettings]
	 */
	public function getSettings(int $mailboxId): Result
	{
		return $this->reader->read($mailboxId);
	}

	/**
	 * @return Result data on success: ['items' => DirectoryItem[]]
	 */
	public function loadChildren(int $mailboxId, string $dirMd5): Result
	{
		return $this->childrenLoader->load($mailboxId, $dirMd5);
	}

	/**
	 * Saves the folder settings of the mailbox.
	 *
	 * @param array<int, array{dirMd5?: string, value?: int|string}> $dirs
	 * @param array<int, array{dirMd5?: string, type?: string}> $dirsTypes
	 */
	public function save(int $mailboxId, array $dirs, array $dirsTypes): Result
	{
		$result = new Result();

		if (empty($dirs) && empty($dirsTypes))
		{
			$result->addError(new Error('Error processing form', 'MAIL_CLIENT_FORM_ERROR'));

			return $result;
		}

		return (new SettingsWriteCoordinator())->execute(
			$mailboxId,
			function (array $state, array &$afterCommit) use ($mailboxId, $dirs, $dirsTypes): Result {
				$mailbox = $state['mailbox'];
				$access = (new GeneralSettingsService())->checkAccess($mailbox, GeneralSettingsPatch::fromArray([]));
				if (!$access->isSuccess())
				{
					return $access;
				}

				$operations = $state['operations'];
				$latest = $operations === [] ? null : $operations[array_key_last($operations)];
				if ((new MigrationStatusProvider())->fromRow($latest)?->visibility === 'active')
				{
					return (new Result())->addError(new Error(
						(string)Loc::getMessage('MAIL_MIGRATION_ACTION_UNAVAILABLE'),
						MigrationActionGuard::ERROR_ACTION_BLOCKED,
					));
				}

				$pointer = (int)($state['sourceGenerationId'] ?? 0);
				$scope = $pointer > 0 ? GenerationScope::active($pointer) : GenerationScope::withoutPreparedGenerations($mailboxId);
				$syncResult = $this->syncUpdater->update($mailboxId, $dirs, $scope, activateSync: false);
				if (!$syncResult->isSuccess())
				{
					return $syncResult;
				}

				$typesResult = $this->typesAssigner->assign($mailboxId, $dirsTypes, $scope);
				if (!$typesResult->isSuccess())
				{
					return $typesResult;
				}

				$this->activateSyncWithinTransaction($mailbox);
				$afterCommit[] = static function () use ($mailboxId, $scope): void {
					MailboxDirectory::invalidateCache($mailboxId, $scope->getStampGenerationId());
					MailboxTable::cleanCachesByMailboxId($mailboxId);
					MailboxTable::cleanEntityCache();
				};

				return new Result();
			},
			withMigrationState: true,
		);
	}

	private function activateSyncWithinTransaction(array $mailbox): void
	{
		$options = $mailbox['OPTIONS'];
		if (!isset($options['activateSync']) || $options['activateSync'] === true)
		{
			return;
		}

		$options['activateSync'] = true;
		$connection = Application::getConnection();
		[$update, $binds] = $connection->getSqlHelper()->prepareUpdate(MailboxTable::getTableName(), [
			'SYNC_LOCK' => 0,
			'OPTIONS' => serialize($options),
		]);
		$connection->queryExecute(sprintf(
			'UPDATE %s SET %s WHERE ID = %u',
			MailboxTable::getTableName(),
			$update,
			(int)$mailbox['ID'],
		), $binds);
	}
}
