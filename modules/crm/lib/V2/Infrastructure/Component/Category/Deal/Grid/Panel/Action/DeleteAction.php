<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Category\Deal\Grid\Panel\Action;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Grid\Panel\Actions;
use Bitrix\Main\Grid\Panel\DefaultValue;
use Bitrix\Main\Grid\Panel\Snippet\Button;
use Bitrix\Main\Grid\Panel\Snippet\Onchange;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

final class DeleteAction extends \Bitrix\Main\Grid\Panel\Action\RemoveAction
{
	private const EVENT_NAME = 'Crm.DealCategoryList:onAction';

	public function processRequest(HttpRequest $request, bool $isSelectedAllRows, ?Filter $filter): ?Result
	{
		return null;
	}

	public function getControl(): ?array
	{
		Container::getInstance()->getLocalization()->loadMessages();

		$removeButton = (new Button())
			->setClass(DefaultValue::REMOVE_BUTTON_CLASS)
			->setId(DefaultValue::REMOVE_BUTTON_ID)
			->setText(Loc::getMessage('CRM_COMMON_ACTION_DELETE'))
			->setTitle(Loc::getMessage('CRM_COMMON_ACTION_DELETE'))
		;

		$payload = Json::encode([
			'actionId' => 'bulk-delete',
			'controller' => 'crm.api.dealcategory.grid',
			'method' => 'POST',
		]);
		$onchange = new Onchange();
		$onchange->addAction([
			'ACTION' => Actions::CALLBACK,
			'DATA' => [[
				'JS' => "BX.Event.EventEmitter.emit('" . self::EVENT_NAME . "', {$payload});",
			]],
		]);
		$removeButton->setOnchange($onchange);

		return $removeButton->toArray();
	}
}
