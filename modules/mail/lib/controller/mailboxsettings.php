<?php

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Access\MailActionDictionary;
use Bitrix\Mail\Access\MailboxAccessController;
use Bitrix\Mail\Helper\Enum\Mailbox\EntityOptionsType;
use Bitrix\Mail\Helper\Mailbox\Options\EntityDataHelper;
use Bitrix\Mail\Helper\Enum\Mailbox\FolderSortMode;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Internal\Entity\Directory\DirectoriesSettings;
use Bitrix\Mail\Internal\Service\Directory\Settings\MailboxDirectorySettingsService;
use Bitrix\Main;
use Bitrix\Intranet;
use Bitrix\Main\Access\AccessibleController;
use Bitrix\Main\Engine\ActionFilter\Access\ItemIdFromRequestStrategy;
use Bitrix\Main\Engine\ActionFilter\Attribute\Access\ActionAccess;
use Bitrix\Main\Engine\Contract\AccessCheckControllerInterface;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;

class MailboxSettings extends Controller implements AccessCheckControllerInterface
{
	private const MAX_EXPAND_STATE_SIZE = 128 * 1024;

	protected function getDefaultPreFilters(): array
	{
		return [
			new Main\Engine\ActionFilter\Authentication(),
			new Main\Engine\ActionFilter\HttpMethod([Main\Engine\ActionFilter\HttpMethod::METHOD_POST]),
			new Main\Engine\ActionFilter\Csrf(),
			new Intranet\ActionFilter\IntranetUser(),
		];
	}

	public function getAccessController(): AccessibleController
	{
		return MailboxAccessController::getInstance((int)CurrentUser::get()->getId());
	}

	#[ActionAccess(
		action: MailActionDictionary::ACTION_MAILBOX_LIST_ITEM_VIEW,
		strategy: ItemIdFromRequestStrategy::class,
		strategyArgs: ['itemIdRequestKey' => 'mailboxId'],
	)]
	public function getDirectoriesSettingsAction(int $mailboxId): array
	{
		$result = (new MailboxDirectorySettingsService())->getSettings($mailboxId);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		/** @var DirectoriesSettings $settings */
		$settings = $result->getData()['settings'];

		return $settings->toArray();
	}

	#[ActionAccess(
		action: MailActionDictionary::ACTION_MAILBOX_LIST_ITEM_VIEW,
		strategy: ItemIdFromRequestStrategy::class,
		strategyArgs: ['itemIdRequestKey' => 'mailboxId'],
	)]
	public function loadDirectoryChildrenAction(int $mailboxId, string $dirMd5): array
	{
		$result = (new MailboxDirectorySettingsService())->loadChildren($mailboxId, $dirMd5);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		$data = $result->getData();

		return [
			'items' => array_map(static fn ($item) => $item->toArray(), $data['items'] ?? []),
		];
	}

	#[ActionAccess(
		action: MailActionDictionary::ACTION_MAILBOX_LIST_ITEM_EDIT,
		strategy: ItemIdFromRequestStrategy::class,
		strategyArgs: ['itemIdRequestKey' => 'mailboxId'],
	)]
	public function saveDirectoriesSettingsAction(int $mailboxId, array $dirs = [], array $dirsTypes = []): array
	{
		$result = (new MailboxDirectorySettingsService())->save($mailboxId, $dirs, $dirsTypes);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return [];
	}

	public function saveFolderSortModeAction(int $mailboxId, string $mode): void
	{
		$userId = (int)CurrentUser::get()->getId();

		if (!MailboxAccess::isMailboxSharedWithUser($mailboxId, $userId))
		{
			return;
		}

		if (FolderSortMode::tryFrom($mode) === null)
		{
			$this->addError(new Main\Error('Invalid sort mode'));

			return;
		}

		EntityDataHelper::setValue(
			$mailboxId,
			EntityOptionsType::User,
			(string)$userId,
			EntityDataHelper::FOLDER_SORT_MODE,
			$mode,
		);
	}

	public function saveFolderExpandStateAction(int $mailboxId, string $collapsedFolders): void
	{
		$userId = (int)CurrentUser::get()->getId();

		if (!MailboxAccess::isMailboxSharedWithUser($mailboxId, $userId))
		{
			return;
		}

		json_decode($collapsedFolders);
		if (json_last_error() !== JSON_ERROR_NONE
			|| mb_strlen($collapsedFolders, '8bit') > self::MAX_EXPAND_STATE_SIZE)
		{
			$this->addError(new Main\Error('Invalid collapsed folders data'));

			return;
		}

		EntityDataHelper::setValue(
			$mailboxId,
			EntityOptionsType::User,
			(string)$userId,
			EntityDataHelper::FOLDER_EXPAND_STATE,
			$collapsedFolders,
		);
	}
}
