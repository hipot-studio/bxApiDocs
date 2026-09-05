<?php

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Access\MailActionDictionary;
use Bitrix\Mail\Access\MailboxAccessController;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\Enum\Mailbox\EntityOptionsType;
use Bitrix\Mail\Helper\Mailbox\MailboxGridCounterAggregator;
use Bitrix\Mail\Helper\Mailbox\Options\EntityDataHelper;
use Bitrix\Mail\Helper\Enum\Mailbox\FolderSortMode;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Internal\Entity\Directory\DirectoriesSettings;
use Bitrix\Mail\Internal\Service\Directory\Settings\MailboxDirectorySettingsService;
use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Mail\Helper\MailboxDirectoryHelper;
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

	public function getMailboxGridButtonCounterAction(): array
	{
		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0 || !MailAccess::hasCurrentUserAccessToMailboxGrid())
		{
			return ['count' => 0];
		}

		return ['count' => (new MailboxGridCounterAggregator())->getButtonCounter($userId)];
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

		if (!MailboxAccess::hasUserAccessToMailbox($mailboxId, $userId, withSharedMailboxes: true))
		{
			$this->addError(new Main\Error('Access to mailbox denied'));

			return;
		}

		$folderSortMode = FolderSortMode::tryFrom($mode);
		if ($folderSortMode === null)
		{
			$this->addError(new Main\Error('Invalid sort mode'));

			return;
		}

		if (
			$folderSortMode === FolderSortMode::Manual
			&& !Feature::isFolderManualSortingAvailable()
		)
		{
			$this->addError(new Main\Error('Manual folder sorting is disabled'));

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

	public function saveFolderCustomOrderAction(
		int $mailboxId,
		array $order,
		bool $activateManualMode = true,
	): void
	{
		if (!Feature::isFolderManualSortingAvailable())
		{
			$this->addError(new Main\Error('Manual folder sorting is disabled'));

			return;
		}

		$userId = (int)CurrentUser::get()->getId();

		if (!MailboxAccess::hasUserAccessToMailbox($mailboxId, $userId, withSharedMailboxes: true))
		{
			$this->addError(new Main\Error('Access to mailbox denied'));

			return;
		}

		$availableDirIds = $this->getAvailableDirIds($mailboxId);

		$validatedOrder = [];
		foreach ($this->normalizeDirIds($order) as $dirId)
		{
			if (isset($availableDirIds[$dirId]))
			{
				$validatedOrder[] = $dirId;
			}
		}

		$json = json_encode(['all' => $validatedOrder]);
		if ($json === false || mb_strlen($json, '8bit') > self::MAX_EXPAND_STATE_SIZE)
		{
			$this->addError(new Main\Error('Invalid folder custom order data'));

			return;
		}

		EntityDataHelper::setValue(
			$mailboxId,
			EntityOptionsType::User,
			(string)$userId,
			EntityDataHelper::FOLDER_CUSTOM_ORDER,
			$json,
		);

		if ($activateManualMode && $validatedOrder !== [])
		{
			EntityDataHelper::setValue(
				$mailboxId,
				EntityOptionsType::User,
				(string)$userId,
				EntityDataHelper::FOLDER_SORT_MODE,
				FolderSortMode::Manual->value,
			);
		}
	}

	/**
	 * @return array<int, true>
	 */
	private function getAvailableDirIds(int $mailboxId): array
	{
		$dirs = (new MailboxDirectoryHelper($mailboxId))->getSyncDirs();

		$dirIds = [];
		foreach ($dirs as $dir)
		{
			$dirIds[(int)$dir->getId()] = true;
		}

		return $dirIds;
	}

	/**
	 * @return int[] positive dir ids, de-duplicated, original order preserved
	 */
	private function normalizeDirIds(array $ids): array
	{
		$result = [];
		foreach ($ids as $id)
		{
			if (is_numeric($id) && (int)$id > 0)
			{
				$result[(int)$id] = (int)$id;
			}
		}

		return array_values($result);
	}
}
