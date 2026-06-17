<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Controller;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Public\Command\Debug\DisableDebugCommand;
use Bitrix\Bizproc\Public\Command\Debug\EnableDebugForTemplateCommand;
use Bitrix\Bizproc\Public\Provider\DebugProvider;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use CBPWorkflowTemplateUser;
use Throwable;

class Debug extends Controller
{
	protected function processBeforeAction(Action $action)
	{
		if (Option::get('bizproc', 'debugger_available', 'N') !== 'Y')
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return false;
		}

		return true;
	}

	public function enableForTemplateAction(int $templateId): ?\Bitrix\Bizproc\Internal\Entity\Debugger\Debug
	{
		if (!$this->isAdmin())
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return null;
		}

		try
		{
			$userId = (int)CurrentUser::get()->getId();
			$command = new EnableDebugForTemplateCommand($userId, $templateId);
			$result = $command->run();

			if (!$result->isSuccess())
			{
				$this->addErrors($result->getErrors());

				return null;
			}

			return $result->getData()['debug'] ?? null;
		}
		catch (Throwable $e)
		{
			$this->addError(new Error(Loc::getMessage('BIZPROC_INFRASTRUCTURE_CONTROLLER_DEBUG_ERROR')));
			Application::getInstance()->getExceptionHandler()->writeToLog($e);

			return null;
		}
	}

	private function isAdmin(): bool
	{
		return (new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser))->isAdmin();
	}

	public function disableAction(int $templateId): ?\Bitrix\Bizproc\Internal\Entity\Debugger\Debug
	{
		if (!$this->isAdmin())
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return null;
		}

		try
		{
			$userId = (int)CurrentUser::get()->getId();
			$provider = new DebugProvider();
			$debug = $provider->getDebug($userId, $templateId);

			if ($debug === null)
			{
				$this->addError(new Error(Loc::getMessage('BIZPROC_INFRASTRUCTURE_CONTROLLER_DEBUG_NOT_FOUND')));

				return null;
			}

			$command = new DisableDebugCommand($userId, $templateId);
			$result = $command->run();

			if (!$result->isSuccess())
			{
				$this->addErrors($result->getErrors());

				return null;
			}

			return $debug;
		}
		catch (Throwable $e)
		{
			$this->addError(new Error(Loc::getMessage('BIZPROC_INFRASTRUCTURE_CONTROLLER_DEBUG_ERROR')));
			Application::getInstance()->getExceptionHandler()->writeToLog($e);

			return null;
		}
	}

	public function getStatusAction(int $templateId): ?\Bitrix\Bizproc\Internal\Entity\Debugger\Debug
	{
		if (!$this->isAdmin())
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return null;
		}

		try
		{
			$userId = (int)CurrentUser::get()->getId();

			return (new DebugProvider())->getDebug($userId, $templateId);
		}
		catch (Throwable $e)
		{
			$this->addError(new Error(Loc::getMessage('BIZPROC_INFRASTRUCTURE_CONTROLLER_DEBUG_ERROR')));
			Application::getInstance()->getExceptionHandler()->writeToLog($e);

			return null;
		}
	}
}
