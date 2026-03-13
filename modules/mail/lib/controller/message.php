<?php

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Helper;
use Bitrix\Mail\Helper\MessageLoader;
use Bitrix\Main;
use Bitrix\Intranet;
use Bitrix\Mail\MailMessageUidTable;
use Bitrix\Main\Engine\Controller;
use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\PageNavigation;

/**
 * Class Message
 * Processing email actions, such as deleting, moving to folders, etc.
 *
 * @package Bitrix\Mail\Controller
 */
class Message extends Controller
{
	protected function getDefaultPreFilters(): array
	{
		return
			[
				new Main\Engine\ActionFilter\ContentType([Main\Engine\ActionFilter\ContentType::JSON, 'application/x-www-form-urlencoded']),
				new Main\Engine\ActionFilter\Authentication(),
				new Main\Engine\ActionFilter\HttpMethod(
					[Main\Engine\ActionFilter\HttpMethod::METHOD_GET, Main\Engine\ActionFilter\HttpMethod::METHOD_POST]
				),
				new Intranet\ActionFilter\IntranetUser(),
			];
	}

	private const FILTER_PRESET_ALL_INCOME = 'all_income';
	private const FILTER_PRESET_UNREAD = 'unread';
	private const FILTER_PRESET_SENT = 'sent';
	public const MAIL_MESSAGE_TYPE = 0;
	public const CRM_MESSAGE_TYPE = 1;

	public function getMessageListAction(PageNavigation $navigation, CurrentUser $user, array $filterParams = []): array
	{
		$result = [
			'items' => [],
			'dirs' => [],
			'currentMailboxId' => 0,
			'currentFolderPath' => '',
			'mailboxIsNotAvailable' => false,
		];

		if (!Loader::includeModule('mail'))
		{
			return $result;
		}

		$previousSeenMailboxId = (int)\CUserOptions::getOption('mail', 'previous_seen_mailbox_id', 0);

		if (isset($filterParams['mailboxId']))
		{
			$mailboxId = (int)$filterParams['mailboxId'];
		}
		else
		{
			$mailboxId = $previousSeenMailboxId;
		}

		$mailboxHelper = Mailbox::findBy($mailboxId);

		if (is_null($mailboxHelper))
		{
			if ($previousSeenMailboxId === 0)
			{
				$result['mailboxIsNotAvailable'] = true;

				return $result;
			}

			$mailboxId = $previousSeenMailboxId;

			$mailboxHelper = Mailbox::findBy($mailboxId);

			if (is_null($mailboxHelper))
			{
				$result['mailboxIsNotAvailable'] = true;

				return $result;
			}
		}
		else
		{
			if ($previousSeenMailboxId !== $mailboxId)
			{
				\CUserOptions::SetOption('mail', 'previous_seen_mailbox_id', $mailboxId);
			}
		}

		$selectedDirPath = $mailboxHelper->getDirsHelper()->getDefaultDirPath(true);
		$mailboxDirsHelper = $mailboxHelper->getDirsHelper();
		$dir = $mailboxDirsHelper->getDirByPath($selectedDirPath);

		if (empty($dir) || !($dir->isSync()))
		{
			return $result;
		}

		$basicFilters = [
			'=MAILBOX_ID' => $mailboxId,
		];

		if (isset($filterParams['searchString']) && trim($filterParams['searchString']) !== '')
		{
			$basicFilters['*SEARCH_CONTENT'] = Helper\Message::prepareSearchString(Main\Text\Emoji::encode($filterParams['searchString']));
		}

		if (!is_null($filterParams['folderPath']))
		{
			$selectedDirPath = $filterParams['folderPath'];
		}

		$dir = $mailboxDirsHelper->getDirByPath($selectedDirPath);
		$isOutcomeDir = $dir->isOutcome();

		switch ($filterParams['tabId'] ?? null)
		{
			case self::FILTER_PRESET_UNREAD:
				$basicFilters['=MESSAGE_UID.IS_SEEN'] = 'N';

				if ($isOutcomeDir)
				{
					$dirPathIncome = $mailboxDirsHelper->getIncomePath(true);
					$dirIncome = $mailboxDirsHelper->getDirByPath($selectedDirPath);

					if ($dirIncome->isSync())
					{
						$selectedDirPath = $dirPathIncome;
						$isOutcomeDir = false;
					}

				}

				break;
			case self::FILTER_PRESET_SENT:
				$dirPathOutcome = $mailboxDirsHelper->getOutcomePath(true);
				$dirOutcome = $mailboxDirsHelper->getDirByPath($selectedDirPath);
				if ($dirOutcome->isSync())
				{
					$selectedDirPath = $dirPathOutcome;
					$isOutcomeDir = true;
				}
				break;
			default:
				break;
		}

		$result['items'] = MessageLoader::getMessageList($basicFilters, $selectedDirPath, $navigation, $isOutcomeDir);
		$result['dirs'] = $mailboxHelper->getDirsHelper()->buildDirectoryTreeForContextMenu($mailboxId, $mailboxHelper);
		$result['currentMailboxId'] = $mailboxId;
		$result['currentFolderPath'] = $selectedDirPath;
		$result['startEmailSender'] = $mailboxHelper->getMailbox()['EMAIL'];

		return $result;
	}

	/**
	 * @param $ids
	 * @return \Bitrix\Main\Result
	 */
	private function extractIds($ids, $ignoreOld = false): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();
		if (empty($ids))
		{
			return $result->addError(new \Bitrix\Main\Error('validation'));
		}
		$mailboxIds = $messIds = [];
		foreach ($ids as $id)
		{
			[$messId, $mailboxId] = explode('-', $id, 2);

			$mailboxIds[$mailboxId] = $mailboxId;
			$messIds[$messId] = $messId;
		}
		if (count($mailboxIds) > 1)
		{
			return $result->addError(new \Bitrix\Main\Error('validation'));
		}

		if($ignoreOld)
		{
			$oldIds = MailMessageUidTable::getList(array(
				'select' => array('ID'),
				'filter' => array(
					'@ID' => $messIds,
					'=MAILBOX_ID' => current($mailboxIds),
					'=IS_OLD' => 'Y',
				),
			))->fetchAll();

			foreach ($oldIds as $item)
			{
				if(is_set($messIds[$item['ID']]))
				{
					unset($messIds[$item['ID']]);
				}
			}
		}

		if (!count($mailboxIds))
		{
			return $result->addError(new \Bitrix\Main\Error('validation'));
		}
		if (!count($messIds))
		{
			return $result->addError(new \Bitrix\Main\Error('validation'));
		}
		$result->setData([
			'mailboxId' => array_pop($mailboxIds),
			'messagesIds' => array_keys($messIds),
		]);

		return $result;
	}

	/**
	 * @param string[] $ids
	 * @param boolean $deleteImmediately
	 */
	public function deleteAction(array $ids, bool $deleteImmediately = false): void
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::delete($ids, $deleteImmediately);

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}
	}

	/**
	 * @param string[] $ids
	 * @throws Main\ArgumentException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	public function markAsSpamAction(array $ids): void
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::markAsSpam($ids, $this->getCurrentUser()->getId());

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}
	}

	/**
	 * @param string[] $ids
	 * @return void
	 * @throws \Exception
	 */
	public function restoreFromSpamAction(array $ids): void
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::restoreFromSpam($ids, $this->getCurrentUser()->getId());

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}
	}

	/**
	 * @param array $ids
	 * @return void
	 * @throws \Exception
	 */
	public function moveToFolderAction(array $ids, string $folderPath): void
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::moveToFolder($ids, $folderPath, $this->getCurrentUser()->getId());

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}
	}

	/**
	 * @param array $ids
	 * @return void
	 */
	public function markAsUnseenAction(array $ids): void
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::markAsUnseen($ids);

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}
	}

	/**
	 * @param array $ids
	 * @param bool $isRead
	 * @return void
	 */
	public function changeReadStatusAction(array $ids, int $isRead): void
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::markMessages($ids, (int)$isRead);

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}
	}

	/**
	 * @param array $ids
	 * @return void
	 */
	public function markAsSeenAction(array $ids): void
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::markAsSeen($ids);

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}
	}

	/**
	 * @param int $messageId
	 * @param int $iteration
	 * @return void
	 */
	public function createCrmActivityAction(int $messageId, int $iteration = 1): void
	{
		$result = \Bitrix\Mail\Helper\Message\MessageActions::createCrmActivity($messageId, $iteration);

		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$this->addError($errors[0]);
		}
	}

	/**
	 * @param array $ids
	 * @return bool
	 */
	public function createCrmActivitiesAction(array $ids): bool
	{
		foreach ($ids as $id)
		{
			$result = \Bitrix\Mail\Helper\Message\MessageActions::createCrmActivity($id, 1);

			if (!$result->isSuccess())
			{
				$errors = $result->getErrors();
				$this->addError($errors[0]);

				return false;
			}
		}

		return true;
	}
}