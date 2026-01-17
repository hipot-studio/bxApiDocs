<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Action;

use Bitrix\Bizproc\Internal\Grid\AiAgents\AiAgentsActionType;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class RestartAction extends JsGridAction
{
	public static function getId(): string
	{
		return AiAgentsActionType::RESTART->value;
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BIZPROC_AI_AGENTS_ACTION_RESTART_TITLE') ?? '';
	}

	/**
	 * @param array{ID: string} $rawFields
	 */
	public function getControl(array $rawFields): ?array
	{
		$templateId = filter_var($rawFields['ID'] ?? '', FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 0,
			],
		]);

		if (!$templateId)
		{
			return null;
		}

		if (!$this->isEnabled($rawFields))
		{
			return null;
		}

		return parent::getControl($rawFields);
	}

	protected function isEnabled(array $rawFields): bool
	{
		$isNotSystemTemplate = empty($rawFields['SYSTEM_CODE']);
		$startedByCurrentUser = $this->isStartedByCurrentUser($rawFields['ACTIVATED_BY'] ?? null);
		$isStarted = !empty($rawFields['ACTIVATED_AT']);
		$isCurrentUserAdmin = $this->isUserAdmin();

		return
			$isNotSystemTemplate
			&& ($isCurrentUserAdmin || $startedByCurrentUser)
			&& $isStarted
		;
	}

	protected function getActionParams(array $rawFields): array
	{
		return [
			'templateId' => $rawFields['ID'] ?? '',
		];
	}
}
