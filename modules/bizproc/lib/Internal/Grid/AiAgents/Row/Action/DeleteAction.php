<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Action;

use Bitrix\Main\Result;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;

use Bitrix\Bizproc\Internal\Grid\AiAgents\AiAgentsActionType;


class DeleteAction extends JsGridAction
{
	public static function getId(): string
	{
		return AiAgentsActionType::DELETE->value;
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BIZPROC_AI_AGENTS_GRID_ACTION_DELETE') ?? '';
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

		return parent::getControl($rawFields);
	}

	protected function isEnabled(array $rawFields): bool
	{
		$isNotSystemTemplate = empty($rawFields['SYSTEM_CODE']);
		$startedByCurrentUser = $this->isStartedByCurrentUser($rawFields['ACTIVATED_BY'] ?? null);
		$isCurrentUserAdmin = $this->isUserAdmin();

		return
			$isNotSystemTemplate
			&& ($isCurrentUserAdmin || $startedByCurrentUser)
		;
	}

	protected function getActionParams(array $rawFields): array
	{
		return [
			'templateId' => $rawFields['ID'] ?? '',
		];
	}
}