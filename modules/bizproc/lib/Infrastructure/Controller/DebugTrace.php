<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Controller;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Entity\Debugger\Collections\DebugTraceCollection;
use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Bizproc\Public\Provider\DebugSessionProvider;
use Bitrix\Bizproc\Public\Provider\DebugTraceProvider;
use Bitrix\Bizproc\Public\Provider\Params\DebugTrace\DebugTraceFilter;
use Bitrix\Bizproc\Public\Provider\Params\DebugTrace\DebugTraceSelect;
use Bitrix\Bizproc\Public\Provider\Params\DebugTrace\DebugTraceSort;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Provider\Params\GridParams;
use Bitrix\Main\Provider\Params\Pager;
use CBPWorkflowTemplateUser;
use Throwable;

class DebugTrace extends Controller
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

	public function getBySessionIdAction(int $debugSessionId, int $page = 1): ?DebugTraceCollection
	{
		if (!$this->isAdmin())
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return null;
		}

		try
		{
			$session = (new DebugSessionProvider())->getById($debugSessionId, false);

			if ($session === null)
			{
				$this->addError(new Error(
					Loc::getMessage('BIZPROC_INFRASTRUCTURE_CONTROLLER_DEBUG_SESSION_NOT_FOUND'),
				));

				return null;
			}

			if (!$this->canAccess($session))
			{
				$this->addError(ErrorMessage::ACCESS_DENIED->getError());

				return null;
			}

			return (new DebugTraceProvider())->getList(new GridParams(
				pager: (new Pager(50))->setPage($page > 0 ? $page : 1),
				filter: new DebugTraceFilter(['DEBUG_SESSION_ID' => $debugSessionId]),
				sort: new DebugTraceSort(['TIMESTAMP' => 'ASC']),
				select: new DebugTraceSelect(['ID', 'KEY', 'TYPE', 'MESSAGE', 'DATA', 'CONTEXT', 'TIMESTAMP']),
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
		return $this->getUser()->isAdmin();
	}

	private function getUser(): CBPWorkflowTemplateUser
	{
		return new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser);
	}

	private function canAccess(\Bitrix\Bizproc\Internal\Entity\Debugger\DebugSession $debugSession): bool
	{
		return $debugSession->getUserId() === $this->getUser()->getId();
	}
}
