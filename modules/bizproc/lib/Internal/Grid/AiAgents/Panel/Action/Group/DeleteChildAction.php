<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Panel\Action\Group;

use Bitrix\Bizproc\Internal\Grid\AiAgents\AiAgentsActionType;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class DeleteChildAction extends AiAgentsGroupChildAction
{
	public static function getActionType(): AiAgentsActionType
	{
		return AiAgentsActionType::GROUP_DELETE;
	}

	public function getName(): string
	{
		return Loc::getMessage('BIZPROC_AI_AGENTS_GRID_GROUP_ACTION_DELETE') ?? '';
	}

	public function processRequest(HttpRequest $request, bool $isSelectedAllRows, ?Filter $filter): ?Result
	{
		return null;
	}
}