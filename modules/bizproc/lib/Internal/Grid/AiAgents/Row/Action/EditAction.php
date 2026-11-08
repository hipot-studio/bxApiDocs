<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Action;

use Bitrix\Main\Result;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;

use Bitrix\Bizproc\Internal\Config\DesignerV2Feature;
use Bitrix\Bizproc\Internal\Grid\AiAgents\AiAgentsGridHelper;
use Bitrix\Bizproc\Internal\Grid\AiAgents\AiAgentsActionType;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;

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

		if (!$this->isBpEditorOpen())
		{
			return null;
		}

		return parent::getControl($rawFields);
	}

	private function isBpEditorOpen(): bool
	{
		return DesignerV2Feature::isEnabled();
	}

	protected function isEnabled(array $rawFields): bool
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

		if (!$templateId || $this->isSystemTemplate($rawFields))
		{
			return false;
		}

		return $this->canEditTemplate($templateId);
	}

	protected function canEditTemplate(int $templateId): bool
	{
		return (new TemplateAccessService())->canEdit($templateId);
	}

	protected function getActionTitleMessage(array $rawFields): string
	{
		if ($this->isSystemTemplate($rawFields))
		{
			return Loc::getMessage('BIZPROC_AI_AGENTS_GRID_CAN_NOT_EDIT_SYSTEM_AGENT') ?? '';
		}

		return Loc::getMessage('BIZPROC_AI_AGENTS_GRID_NO_ACCESS_RIGHTS') ?? '';
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
