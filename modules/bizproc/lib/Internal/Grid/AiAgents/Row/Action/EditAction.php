<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Action;

use Bitrix\Main\Result;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;

use Bitrix\Bizproc\Internal\Grid\AiAgents\AiAgentsGridHelper;
use Bitrix\Bizproc\Internal\Grid\AiAgents\AiAgentsActionType;

class EditAction extends JsGridAction
{
	public static function getId(): string
	{
		return AiAgentsActionType::EDIT->value;
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BIZPROC_AI_AGENTS_ACTION_EDIT_TITLE') ?? '';
	}

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
		return empty($rawFields['SYSTEM_CODE']) && $this->isUserAdmin();
	}

	protected function prepareEditUri(int $templateId): string
	{
		$baseBizprocDesignerUri = (new AiAgentsGridHelper())->getBaseBizprocDesignerUri();
		return $baseBizprocDesignerUri->withQuery('ID=' . $templateId);
	}

	protected function getActionParams(array $rawFields): array
	{
		$templateId = filter_var(
			$rawFields['ID'] ?? '',
			FILTER_VALIDATE_INT,
			[
				'options' => [
					'min_range' => 0,
				],
			],
		);

		$editUri = $this->prepareEditUri($templateId);

		return [
			'templateId' => $templateId,
			'editUri' => $editUri,
		];
	}
}