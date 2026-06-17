<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Controller;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Entity\Debugger\Collections\DebugSessionCollection;
use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Bizproc\Public\Command\DebugSession\DeleteAllDebugSessionsCommand;
use Bitrix\Bizproc\Public\Provider\DebugSessionProvider;
use Bitrix\Bizproc\Public\Provider\Params\DebugSession\DebugSessionFilter;
use Bitrix\Bizproc\Public\Provider\Params\DebugSession\DebugSessionSelect;
use Bitrix\Bizproc\Public\Provider\Params\DebugSession\DebugSessionSort;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\Pager;
use CBPWorkflowTemplateUser;
use Throwable;

class DebugSession extends Controller
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

	public function getListAction(int $templateId): ?DebugSessionCollection
	{
		if (!$this->isAdmin())
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return null;
		}

		try
		{
			$currentUserId = (int)CurrentUser::get()->getId();

			return (new DebugSessionProvider())->getList(new GridParams(
				pager: (new Pager(100)),
				filter: new DebugSessionFilter([
					'USER_ID' => $currentUserId,
					'TEMPLATE_ID' => $templateId,
				]),
				sort: new DebugSessionSort(['START_TIME' => 'ASC']),
				select: new DebugSessionSelect(['*']),
			));
		}
		catch (Throwable $e)
		{
			$this->addError(ErrorBuilder::buildFromException($e));
			Application::getInstance()->getExceptionHandler()->writeToLog($e);

			return null;
		}
	}

	private function isAdmin(): bool
	{
		return (new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser))->isAdmin();
	}

	public function deleteAllAction(): ?array
	{
		if (!$this->isAdmin())
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return null;
		}

		try
		{
			$currentUserId = (int)CurrentUser::get()->getId();
			$command = new DeleteAllDebugSessionsCommand($currentUserId);
			$result = $command->run();

			if (!$result->isSuccess())
			{
				$this->addErrors($result->getErrors());

				return null;
			}

			return $result->getData();
		}
		catch (Throwable $e)
		{
			$this->addError(ErrorBuilder::buildFromException($e));
			Application::getInstance()->getExceptionHandler()->writeToLog($e);

			return null;
		}
	}
}
