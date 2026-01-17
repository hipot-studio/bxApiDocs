<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Panel\Action\Group;

use Bitrix\Main\Web\Json;
use Bitrix\Main\Grid\Panel\Action\Group\GroupChildAction;
use Bitrix\Main\Grid\Panel\Actions;
use Bitrix\Main\Grid\Panel\Snippet;

use Bitrix\Bizproc\Internal\Grid\AiAgents\AiAgentsActionType;
use Bitrix\Bizproc\Internal\Grid\AiAgents\Settings\AiAgentsSettings;

abstract class AiAgentsGroupChildAction extends GroupChildAction
{
	public function __construct(
		private readonly AiAgentsSettings $settings
	)
	{
	}

	abstract public static function getActionType(): AiAgentsActionType;

	final public static function getId(): string
	{
		return static::getActionType()->value;
	}

	protected function getSettings(): AiAgentsSettings
	{
		return $this->settings;
	}

	protected function getJsCallBack(): ?string
	{
		$actionParams = Json::encode([
			'actionId' => static::getId(),
			'gridId' => $this->getSettings()->getID(),
			'filter' => $this->getSettings()->getFilterFields(),
		]);

		return sprintf(
			"BX.%s.Panel.executeAction(%s)",
			$this->settings->getExtensionName(),
			$actionParams,
		);
	}

	final protected function getOnchange(): Snippet\Onchange
	{
		$snippet = new Snippet\Onchange();

		$snippet->addAction(
			[
				'ACTION' => Actions::RESET_CONTROLS,
			],
		);

		$snippet->addAction(
			[
				'ACTION' => Actions::CREATE,
				'DATA' => [
					(new Snippet())->getApplyButton([
						'ONCHANGE' => [
							[
								'ACTION' => Actions::CALLBACK,
								'DATA' => [
									[
										'JS' => $this->getJsCallBack(),
									],
								],
							],
						],
					]),
				],
			],
		);

		return $snippet;
	}
}
