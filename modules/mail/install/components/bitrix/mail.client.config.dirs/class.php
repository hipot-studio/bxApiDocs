<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Helper\MailboxDirectoryHelper;
use Bitrix\Mail\Internal\Service\Directory\Settings\MailboxDirectorySettingsService;
use Bitrix\Mail\MailboxDirectory;
use Bitrix\Main;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Errorable;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Result;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__DIR__ . '/../mail.client/class.php');

Loader::includeModule('mail');

class CMailClientConfigDirsComponent extends CBitrixComponent implements Controllerable, Errorable
{
	/** @var  ErrorCollection */
	protected $errorCollection;

	/**
	 * @inheritDoc
	 */
	public function configureActions()
	{
		$this->errorCollection = new Main\ErrorCollection();

		return [];
	}

	public function executeComponent()
	{
		global $USER, $APPLICATION;

		$APPLICATION->setTitle(Loc::getMessage('MAIL_CLIENT_CONFIG_DIRS_TITLE'));

		if (!is_object($USER) || !$USER->isAuthorized())
		{
			$APPLICATION->authForm('');
			return;
		}

		$request = Context::getCurrent()->getRequest();
		$mailboxId = (int)$request->getQuery('mailboxId');

		if (!$mailboxId)
		{
			showError(Loc::getMessage('MAIL_CLIENT_ELEMENT_NOT_FOUND'));

			return;
		}

		if (!MailboxAccess::hasCurrentUserAccessToEditMailbox($mailboxId))
		{
			showError(Loc::getMessage('MAIL_CLIENT_DENIED'));

			return false;
		}

		$mailboxHelper = Mailbox::createInstance($mailboxId, false);
		if (!$mailboxHelper)
		{
			LocalRedirect('/mail');

			return false;
		}

		$cacheFailed = $mailboxHelper->cacheDirs() === false;

		$dirHelper = new MailboxDirectoryHelper($mailboxId);
		$dirHelper->reloadDirs();

		if ($cacheFailed && empty($dirHelper->getDirs()))
		{
			$firstError = $mailboxHelper->getErrors()->toArray()[0] ?? null;
			if ($firstError !== null)
			{
				showError($firstError->getMessage());
			}

			return false;
		}

		$this->arResult['DIRS'] = $dirHelper->buildTreeDirs();
		$this->arResult['MAX_LEVEL'] = 1;
		$this->arResult['OUTCOME'] = $dirHelper->getOutcome();
		$this->arResult['TRASH'] = $dirHelper->getTrash();
		$this->arResult['SPAM'] = $dirHelper->getSpam();
		$this->arResult['MAILBOX_ID'] = $mailboxId;
		$this->arResult['MAX_LEVEL_DIRS'] = MailboxDirectoryHelper::getMaxLevelDirs();

		ob_start();
		$this->includeComponentTemplate('dirs');
		$this->arResult['DIRS_TREE'] = ob_get_clean();

		$this->includeComponentTemplate();
	}

	public function saveAction()
	{
		$request = Context::getCurrent()->getRequest();

		$mailboxId = (int)$request->getPost('mailboxId');
		$dirs = (array)$request->getPost('dirs');
		$dirsTypes = (array)$request->getPost('dirsTypes');

		if (!MailboxAccess::hasCurrentUserAccessToEditMailbox($mailboxId))
		{
			$this->errorCollection->setError(new Main\Error(
				Loc::getMessage('MAIL_CLIENT_DENIED') ?: 'Access denied',
				'MAIL_CLIENT_DENIED',
			));

			return false;
		}

		$result = (new MailboxDirectorySettingsService())->save($mailboxId, $dirs, $dirsTypes);
		if (!$result->isSuccess())
		{
			$this->applyResultErrors($result);

			return false;
		}

		return [];
	}

	public function levelAction()
	{
		$request = Context::getCurrent()->getRequest();

		$mailboxId = (int)$request->getPost('mailboxId');
		$dir = (array)$request->getPost('dir');
		$dirMd5 = (string)($dir['dirMd5'] ?? '');

		if (!MailboxAccess::hasCurrentUserAnyAccessToMailbox($mailboxId))
		{
			$this->errorCollection->setError(new Main\Error(
				Loc::getMessage('MAIL_CLIENT_DENIED') ?: 'Access denied',
				'MAIL_CLIENT_DENIED',
			));

			return false;
		}

		$dirHelper = new MailboxDirectoryHelper($mailboxId);
		$loadResult = $this->loadChildrenForTemplate($mailboxId, $dirHelper, $dirMd5);
		if (!$loadResult->isSuccess())
		{
			$this->applyResultErrors($loadResult);

			return false;
		}

		$this->arResult['DIRS'] = $dirHelper->buildTreeDirs();
		$this->arResult['MAX_LEVEL'] = 1;

		ob_start();
		$this->includeComponentTemplate('dirs');

		return ['dirs' => $this->arResult['DIRS'], 'html' => ob_get_clean()];
	}

	private function loadChildrenForTemplate(int $mailboxId, MailboxDirectoryHelper $dirHelper, string $dirMd5): Result
	{
		$result = new Result();

		if (trim($dirMd5) === '')
		{
			$result->addError(new Main\Error(
				Loc::getMessage('MAIL_CLIENT_FORM_ERROR') ?: 'Error processing form',
				'MAIL_CLIENT_FORM_ERROR',
			));

			return $result;
		}

		$parent = MailboxDirectory::fetchOneByMailboxIdAndHash($mailboxId, $dirMd5);
		if ($parent === null)
		{
			$result->addError(new Main\Error(
				Loc::getMessage('MAIL_CLIENT_MAILBOX_NOT_FOUND') ?: 'Mailbox was not found',
				'MAIL_CLIENT_MAILBOX_NOT_FOUND',
			));

			return $result;
		}

		if ($parent->getLevel() >= MailboxDirectoryHelper::getMaxLevelDirs())
		{
			$result->addError(new Main\Error(
				Loc::getMessage('MAIL_CLIENT_CONFIG_DIRS_MAX_LEVEL_DIRS') ?: 'Maximum nesting levels exceeded',
				'MAIL_CLIENT_CONFIG_DIRS_MAX_LEVEL_DIRS',
			));

			return $result;
		}

		if (!$dirHelper->syncChildren($parent))
		{
			foreach ($dirHelper->getErrors()->toArray() as $error)
			{
				$result->addError($error);
			}

			return $result;
		}

		$dirHelper->setDirs($dirHelper->getAllLevelByParentId($parent));

		return $result;
	}

	private function applyResultErrors(Result $result): void
	{
		foreach ($result->getErrors() as $error)
		{
			$this->errorCollection->setError($error);
		}
	}

	/**
	 * Getting array of errors.
	 * @return Error[]
	 */
	public function getErrors()
	{
		return $this->errorCollection->toArray();
	}

	/**
	 * Getting once error with the necessary code.
	 * @param string $code Code of error.
	 * @return Error
	 */
	public function getErrorByCode($code)
	{
		return $this->errorCollection->getErrorByCode($code);
	}
}
