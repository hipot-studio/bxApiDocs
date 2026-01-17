<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Trigger\Action;

use Bitrix\AiAssistant\Core\Dto\HintDto;
use Bitrix\AiAssistant\Core\Service\AiBot;
use Bitrix\AiAssistant\Trigger\Action\BaseAction;
use Bitrix\AiAssistant\Trigger\Service\Dto\TriggerInitDto;
use Bitrix\BizprocDesigner\Internal\Config\Storage;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Scenario\FirstWorkflowScenario;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;

class FirstTimeWorkflowEditorOpenAction extends BaseAction
{
	protected function shouldRunAction(TriggerInitDto $triggerInitDto): bool
	{
		// @todo only for the first time user opens workflow editor
		return Storage::instance()->isSearchFieldsIndexed();
	}

	protected function runAction(int $userId, int $progressId): bool
	{
		/* Dto is just for test */
		ServiceLocator::getInstance()->get(AiBot::class)->onTriggerHandler(
			new HintDto(
				userId: $userId,
				hintId: 'StartBizprocDesignerHint',
				title: '',
				content: $this->getWidgetMessage(),
				message: Loc::getMessage('BIZPROC_DESIGNER_INTERNAL_INTEGRATION_AI_ASSISTANT_TRIGGER_ACTION_FIRST_TIME_WORKFLOW_EDITOR_OPEN_ACTION_NAME_MESSAGE'),
				skipNotifyMessage: false,
				triggerProgressId: (string)$progressId,
				scenarioId: FirstWorkflowScenario::CODE,
				context: [],
				ttl: 3600,
			),
		);

		return true;
	}

	protected function needCompleteAfterRun(): bool
	{
		return false;
	}

	private function getWidgetMessage(): string
	{
		$firstName = trim(CurrentUser::get()->getFirstName() ?? '');
		if ($firstName === '')
		{
			return Loc::getMessage('BIZPROC_DESIGNER_INTERNAL_INTEGRATION_AI_ASSISTANT_TRIGGER_ACTION_FIRST_TIME_WORKFLOW_EDITOR_OPEN_ACTION_NAME_CONTENT_EMPTY_USER');
		}

		return Loc::getMessage('BIZPROC_DESIGNER_INTERNAL_INTEGRATION_AI_ASSISTANT_TRIGGER_ACTION_FIRST_TIME_WORKFLOW_EDITOR_OPEN_ACTION_NAME_CONTENT', [
			'#FIRST_NAME#' => $firstName,
		]);
	}
}
