<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Directory\Settings;

use Bitrix\Mail\Internal\Service\Directory\ChildrenLoader;
use Bitrix\Mail\Internal\Service\Directory\SyncFlagsUpdater;
use Bitrix\Mail\Internal\Service\Directory\TypesAssigner;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

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

		$syncResult = $this->syncUpdater->update($mailboxId, $dirs);
		if (!$syncResult->isSuccess())
		{
			foreach ($syncResult->getErrors() as $error)
			{
				$result->addError($error);
			}

			return $result;
		}

		$typesResult = $this->typesAssigner->assign($mailboxId, $dirsTypes);
		if (!$typesResult->isSuccess())
		{
			foreach ($typesResult->getErrors() as $error)
			{
				$result->addError($error);
			}

			return $result;
		}

		return $result;
	}
}
