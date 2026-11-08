<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Location\Grid\Panel;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Grid\Panel\Action\Action;
use Bitrix\Main\Grid\Panel\Actions;
use Bitrix\Main\Grid\Panel\DefaultValue;
use Bitrix\Main\Grid\Panel\Snippet\Button;
use Bitrix\Main\Grid\Panel\Snippet\Onchange;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class BatchDeleteAction implements Action
{
	public static function getId(): string
	{
		return 'batch-delete';
	}

	public function processRequest(HttpRequest $request, bool $isSelectedAllRows, ?Filter $filter): ?Result
	{
		return null;
	}

	public function getControl(): array
	{
		Container::getInstance()->getLocalization()->loadMessages();
		$text = (string)Loc::getMessage('CRM_COMMON_ACTION_DELETE');

		$removeButton = new Button();
		$removeButton
			->setClass(DefaultValue::REMOVE_BUTTON_CLASS)
			->setId(DefaultValue::REMOVE_BUTTON_ID)
			->setText($text)
			->setTitle($text)
		;

		$onchange = new Onchange();
		$onchange->addAction([
			'ACTION' => Actions::CALLBACK,
			'DATA' => [
				[
					'JS' => 'BX.Crm.Config.LocationGridManager.Instance.deleteSelected()',
				],
			],
		]);

		$removeButton->setOnchange($onchange);

		return $removeButton->toArray();
	}
}
