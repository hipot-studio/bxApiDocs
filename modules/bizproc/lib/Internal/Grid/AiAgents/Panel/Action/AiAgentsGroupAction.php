<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Panel\Action;

use Bitrix\Main\Localization\Loc;

use Bitrix\Main\Grid\Panel\Action\GroupAction;
use Bitrix\Main\Grid\Panel\Actions;
use Bitrix\Main\Grid\Panel\DefaultValue;

use Bitrix\Bizproc\Internal\Grid\AiAgents\Settings\AiAgentsSettings;
use Bitrix\Bizproc\Internal\Grid\AiAgents\Panel\Action\Group\DeleteChildAction;

class AiAgentsGroupAction extends GroupAction
{
	private const ACTIONS = [
		DeleteChildAction::class,
	];

	public function __construct(
		private readonly AiAgentsSettings $settings
	)
	{
	}

	protected function getSettings(): AiAgentsSettings
	{
		return $this->settings;
	}

	final public function getControl(): ?array
	{
		$control = parent::getControl();
		if ($control === null)
		{
			return null;
		}

		foreach ($control['ITEMS'] as &$item)
		{
			if ($item['VALUE'] === 'default')
			{
				$item['NAME'] = Loc::getMessage('BIZPROC_AI_AGENTS_GRID_PANEL_GROUP_CHOOSE_ACTION');

				$item['ONCHANGE'][] = [
					'ACTION' => Actions::SHOW,
					'DATA' => [
						[
							'ID' => DefaultValue::FOR_ALL_CHECKBOX_ID,
						],
					],
				];
			}
		}

		return $control;
	}

	protected function prepareChildItems(): array
	{
		$actions = [];

		foreach (self::ACTIONS as $actionClass)
		{
			$actions[] = new $actionClass($this->getSettings());
		}

		return $actions;
	}
}