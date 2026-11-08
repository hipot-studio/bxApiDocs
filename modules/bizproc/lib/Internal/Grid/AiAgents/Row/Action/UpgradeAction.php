<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Action;

use Bitrix\Bizproc\Internal\Grid\AiAgents\AiAgentsActionType;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Upgrade\UpgradeFeatureToggle;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class UpgradeAction extends JsGridAction
{
	private const TEST_ATTRIBUTE = 'data-test-id';
	private const TEST_ID = 'bizproc-ai-agents-grid-action-upgrade';

	public static function getId(): string
	{
		return AiAgentsActionType::UPGRADE->value;
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('BIZPROC_AI_AGENTS_GRID_ACTION_UPGRADE') ?? '';
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

		$control = parent::getControl($rawFields);

		if (!isset($control))
		{
			return null;
		}

		// The property map of the base class knows no test markers, but the control array reaches the
		// menu item of main.popup as is, and its attrs are applied to the item node itself. Written out
		// as an attribute rather than through the dataset for the same reason as in ChangeOwnerAction:
		// the row re-read after an upgrade goes through the camel-case converter of the ajax answer.
		$control['attrs'] = [self::TEST_ATTRIBUTE => self::TEST_ID];

		return $control;
	}

	protected function isEnabled(array $rawFields): bool
	{
		$isFeatureEnabled = UpgradeFeatureToggle::isEnabled();

		$isNotSystemTemplate = !$this->isSystemTemplate($rawFields);

		// Upgrade is admin-only; the controller enforces the same guard, this
		// visibility check is only indicative.
		$isCurrentUserAdmin = $this->isUserAdmin();

		// DTO-01: availability is computed server-side, do not recalculate here.
		$hasNewVersion = (bool)($rawFields['hasNewVersion'] ?? false);

		return
			$isFeatureEnabled
			&& $isNotSystemTemplate
			&& $isCurrentUserAdmin
			&& $hasNewVersion
		;
	}

	protected function getActionParams(array $rawFields): array
	{
		return [
			'templateId' => $rawFields['ID'] ?? '',
			'currentVersionLabel' => $rawFields['currentVersionLabel'] ?? '',
			'newVersionLabel' => $rawFields['newVersionLabel'] ?? '',
			'isCustomized' => (bool)($rawFields['isCustomized'] ?? false),
		];
	}
}
