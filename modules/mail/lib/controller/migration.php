<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Access\MailActionDictionary;
use Bitrix\Mail\Access\MailboxAccessController;
use Bitrix\Mail\Access\Model\MailboxModel;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Integration\MailService\MigrationStatusProvider;
use Bitrix\Mail\Integration\MailService\AdminMigrationProgressAccess;
use Bitrix\Mail\Integration\MailService\AdminMigrationProgressProvider;
use Bitrix\Mail\Internal\Service\SourceGeneration\ManualMigrationControl;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Intranet;

final class Migration extends Controller
{
	public const ERROR_STATUS_BATCH_TOO_LARGE = 'MAIL_MIGRATION_STATUS_BATCH_TOO_LARGE';

	private const STATUS_BATCH_LIMIT = 100;

	protected function getDefaultPreFilters(): array
	{
		return [
			new Main\Engine\ActionFilter\Authentication(),
			new Main\Engine\ActionFilter\HttpMethod([Main\Engine\ActionFilter\HttpMethod::METHOD_POST]),
			new Main\Engine\ActionFilter\Csrf(),
			new Intranet\ActionFilter\IntranetUser(),
		];
	}

	public function getAdminProgressAction(int $mailboxId): ?array
	{
		if (!(new AdminMigrationProgressAccess())->canRead((int)CurrentUser::get()->getId()))
		{
			$this->addError(new Error('Access to migration progress is denied', 403));

			return null;
		}

		if ($mailboxId <= 0)
		{
			$this->addError(new Error('The mailbox ID must be positive', 400));

			return null;
		}

		try
		{
			$progress = (new AdminMigrationProgressProvider())->getLatestForDisplay($mailboxId);
		}
		catch (\Throwable)
		{
			$this->addError(new Error('Unable to read migration progress', 'MAIL_MIGRATION_PROGRESS_READ_FAILED'));

			return null;
		}

		if ($progress === null)
		{
			$this->addError(new Error('The mailbox is not found', 404));

			return null;
		}

		return ['progress' => $progress];
	}

	public function setManualControlAction(
		int $mailboxId,
		string $operationId,
		string $command,
		int $expectedVersion,
		?string $expectedCheckpoint = null,
	): ?array
	{
		if (!(new AdminMigrationProgressAccess())->canControl((int)CurrentUser::get()->getId()))
		{
			$this->addError(new Error('Access to migration control is denied', 403));

			return null;
		}

		$operationId = trim($operationId);
		$commands = [
			ManualMigrationControl::COMMAND_ENABLE,
			ManualMigrationControl::COMMAND_GRANT,
			ManualMigrationControl::COMMAND_AUTOMATIC,
		];
		$grantWithoutCheckpoint = $command === ManualMigrationControl::COMMAND_GRANT
			&& !in_array($expectedCheckpoint, [
				ManualMigrationControl::CHECKPOINT_PREPARATION,
				ManualMigrationControl::CHECKPOINT_CHECKING,
				ManualMigrationControl::CHECKPOINT_LOADING,
				ManualMigrationControl::CHECKPOINT_TAIL,
				ManualMigrationControl::CHECKPOINT_COMPLETION,
			], true);
		if (
			$mailboxId <= 0
			|| $operationId === ''
			|| mb_strlen($operationId) > 64
			|| !in_array($command, $commands, true)
			|| $expectedVersion < 0
			|| $grantWithoutCheckpoint
		)
		{
			$this->addError(new Error('The manual migration control request is invalid', 400));

			return null;
		}

		try
		{
			$provider = new AdminMigrationProgressProvider();
			$progress = $provider->getLatestForDisplay($mailboxId);
			if (
				$progress === null
				|| ($progress['availability'] ?? null) !== 'available'
				|| ($progress['operationId'] ?? null) !== $operationId
			)
			{
				$this->addError(new Error(
					'The migration operation is unavailable',
					ManualMigrationControl::ERROR_UNAVAILABLE,
				));

				return null;
			}

			$result = (new ManualMigrationControl())->execute(
				$mailboxId,
				$operationId,
				$command,
				$expectedVersion,
				$expectedCheckpoint,
			);
			if (!$result->isSuccess())
			{
				$this->addErrors($result->getErrors());

				return null;
			}

			$progress = $provider->getLatestForDisplay($mailboxId);
			if ($progress === null)
			{
				throw new \RuntimeException('The migration progress was lost');
			}

			return ['progress' => $progress];
		}
		catch (\Throwable)
		{
			$this->addError(new Error(
				'Unable to change manual migration control',
				ManualMigrationControl::ERROR_UNAVAILABLE,
			));

			return null;
		}
	}

	public function getStatusAction(int $mailboxId): ?array
	{
		if (!$this->canViewMailbox($mailboxId) && !MailboxAccess::hasCurrentUserAnyAccessToMailbox($mailboxId))
		{
			$this->addError(new Error('Access to the mailbox is denied', 403));

			return null;
		}

		$mailboxExists = (bool)MailboxTable::getList([
			'select' => ['ID'],
			'filter' => ['=ID' => $mailboxId],
			'limit' => 1,
		])->fetch();
		if (!$mailboxExists)
		{
			$this->addError(new Error('The mailbox is not found', 404));

			return null;
		}

		$this->registerMailboxWatches((int)CurrentUser::get()->getId(), [$mailboxId]);
		$status = (new MigrationStatusProvider())->getLatestForDisplay($mailboxId);

		return [
			'status' => $status?->toPublicArray() ?? [
				'mailboxId' => $mailboxId,
				'visibility' => 'hidden',
				'status' => null,
				'reason' => null,
			],
		];
	}

	/** @return array{statuses: array<int, array<string, mixed>>} */
	public function getStatusesAction(array $mailboxIds): array
	{
		$mailboxIds = array_values(array_unique(array_filter(array_map('intval', $mailboxIds))));
		if ($mailboxIds === [])
		{
			return ['statuses' => []];
		}
		if (count($mailboxIds) > self::STATUS_BATCH_LIMIT)
		{
			$this->addError(new Error(
				'Too many mailbox migration statuses were requested',
				self::ERROR_STATUS_BATCH_TOO_LARGE,
			));

			return ['statuses' => []];
		}

		$models = MailboxModel::createFromIds($mailboxIds);
		$allowedIds = MailboxAccessController::getInstance((int)CurrentUser::get()->getId())->withMailboxBatch(
			$models,
			fn (): array => array_values(array_filter(
				array_keys($models),
				fn (int $mailboxId): bool => $this->canViewMailbox($mailboxId)
					|| MailboxAccess::hasCurrentUserAnyAccessToMailbox($mailboxId),
			)),
		);
		$this->registerMailboxWatches((int)CurrentUser::get()->getId(), $allowedIds);
		$known = (new MigrationStatusProvider())->getLatestForMailboxes($allowedIds);
		$statuses = [];
		foreach ($allowedIds as $mailboxId)
		{
			$status = $known[$mailboxId] ?? null;
			$statuses[$mailboxId] = $status?->toPublicArray() ?? [
				'mailboxId' => $mailboxId,
				'visibility' => 'hidden',
				'status' => null,
				'reason' => null,
			];
		}

		return ['statuses' => $statuses];
	}

	private function canViewMailbox(int $mailboxId): bool
	{
		return $mailboxId > 0 && MailboxAccessController::getInstance((int)CurrentUser::get()->getId())->check(
			MailActionDictionary::ACTION_MAILBOX_LIST_ITEM_VIEW,
			MailboxModel::createFromId($mailboxId),
		);
	}

	/** @param int[] $mailboxIds */
	private function registerMailboxWatches(int $userId, array $mailboxIds): void
	{
		if ($userId <= 0 || $mailboxIds === [] || !Main\Loader::includeModule('pull'))
		{
			return;
		}

		$readableMailboxes = MailboxTable::getUserMailboxes($userId, true);
		$tags = [];
		foreach ($mailboxIds as $mailboxId)
		{
			$tags[] = 'mail_mailbox_migration_' . (int)$mailboxId;
			if (isset($readableMailboxes[$mailboxId]))
			{
				$tags[] = 'mail_mailbox_' . (int)$mailboxId;
			}
		}
		if (method_exists(\CPullWatch::class, 'AddMulti'))
		{
			\CPullWatch::AddMulti($userId, $tags);
		}
		else
		{
			foreach ($tags as $tag)
			{
				\CPullWatch::Add($userId, $tag);
			}
		}
		\CPullWatch::DeferredSql($userId);
	}
}
