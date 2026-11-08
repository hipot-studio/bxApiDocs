<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Controller;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Internal\Entity\Debugger\Collections\DebugTraceCollection;
use Bitrix\Bizproc\Internal\Exception\ErrorBuilder;
use Bitrix\Bizproc\Public\Feature\Debugger\ProcessDebuggerFlag;
use Bitrix\Bizproc\Public\Provider\DebugSessionProvider;
use Bitrix\Bizproc\Public\Provider\DebugTraceProvider;
use Bitrix\Bizproc\Public\Provider\Params\DebugTrace\DebugTraceFilter;
use Bitrix\Bizproc\Public\Provider\Params\DebugTrace\DebugTraceSelect;
use Bitrix\Bizproc\Public\Provider\Params\DebugTrace\DebugTraceSort;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Feature;
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
		if (!Feature::isEnabled(ProcessDebuggerFlag::class))
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return false;
		}

		return true;
	}

	public function getBySessionIdAction(
		int $debugSessionId,
		int $page = 1,
		?int $afterId = null,
	): ?DebugTraceCollection
	{
		try
		{
			$session = (new DebugSessionProvider())->getById($debugSessionId, false);

			if ($session === null)
			{
				$this->addError(new Error(
					Loc::getMessage('BIZPROC_INFRASTRUCTURE_CONTROLLER_DEBUG_SESSION_NOT_FOUND') ?? '',
				));

				return null;
			}

			if (!$this->canAccess($session))
			{
				$this->addError(ErrorMessage::ACCESS_DENIED->getError());

				return null;
			}

			$pager = new Pager(50);
			$filter = ['DEBUG_SESSION_ID' => $debugSessionId];
			$sort = ['TIMESTAMP' => 'ASC'];

			if ($afterId === null)
			{
				$pager->setPage($page > 0 ? $page : 1);
			}
			else
			{
				$filter['>ID'] = $afterId;
				// a cursor by id has to be read in the order of id, or rows are lost on a page border
				$sort = ['ID' => 'ASC'];
			}

			return (new DebugTraceProvider())->getList(new GridParams(
				pager: $pager,
				filter: new DebugTraceFilter($filter),
				sort: new DebugTraceSort($sort),
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

	private function getUser(): CBPWorkflowTemplateUser
	{
		return new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser);
	}

	/** A session without a template has no publish right to check, so it stays with the administrator. */
	private function canAccess(\Bitrix\Bizproc\Internal\Entity\Debugger\DebugSession $debugSession): bool
	{
		$user = $this->getUser();
		if ($debugSession->getUserId() !== $user->getId())
		{
			return false;
		}

		if ($user->isAdmin())
		{
			return true;
		}

		$templateId = $debugSession->getTemplateId();

		return $templateId !== null && (new TemplateAccessService())->canPublish($templateId, $user->getId());
	}
}
