<?php

namespace Bitrix\Mail\Grid\MailboxSettingsGrid\Panel\Action;

use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Grid\Panel\Action\Action;
use Bitrix\Main\Grid\Panel\Actions;
use Bitrix\Main\Grid\Panel\Types;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

abstract class JsPanelAction implements Action
{
	public function __construct(
		private readonly Settings $settings,
	)
	{
	}

	protected function getSettings(): Settings
	{
		return $this->settings;
	}

	abstract public function getName(): string;

	public function processRequest(HttpRequest $request, bool $isSelectedAllRows, ?Filter $filter): ?Result
	{
		return new Result();
	}

	protected function getJsCallback(): string
	{
		$actionParams = Json::encode([
			'actionId' => static::getId(),
			'gridId' => $this->settings->getID(),
		]);

		return "BX.Mail.MailboxList.GridManager.executePanelAction($actionParams)";
	}

	public function getControl(): ?array
	{
		return [
			'TYPE' => Types::BUTTON,
			'ID' => static::getId(),
			'NAME' => static::getId(),
			'TEXT' => $this->getName(),
			'ONCHANGE' => [
				[
					'ACTION' => Actions::CALLBACK,
					'DATA' => [
						['JS' => $this->getJsCallback()],
					],
				],
			],
		];
	}
}
