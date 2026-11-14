<?php

namespace Bitrix\Crm\Component\EntityList\Grid\Panel\Action\Item;

use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Grid\Panel\Action\Action;
use Bitrix\Main\Grid\Panel\Actions;
use Bitrix\Main\Grid\Panel\Snippet\Button;
use Bitrix\Main\Grid\Panel\Snippet\Onchange;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * Button of the client tab panel that unbinds the selected contacts (companies) from the owner of the tab.
 * The button only starts the client tab manager, the unbind controller checks permissions on every step.
 */
final class UnbindClientAction implements Action
{
	/**
	 * Link button with an icon, as the standard "Edit" and "Delete" of the panel.
	 * The icon "o-unlink" of ui.icon-set is drawn by the styles of the crm.contact.list and crm.company.list templates,
	 * the scope class makes the variables of ui.icon-set available on the button.
	 */
	public const BUTTON_CLASS = 'ui-btn-link ui-btn-icon-crm-client-tab-unlink ui-icon-set__scope';

	/**
	 * @throws ArgumentNullException when the grid address is empty and the button id would stay page wide
	 */
	public function __construct(private readonly string $gridId)
	{
		if ($this->gridId === '')
		{
			throw new ArgumentNullException('gridId');
		}
	}

	public static function getId(): string
	{
		return 'client_tab_unbind';
	}

	public function processRequest(HttpRequest $request, bool $isSelectedAllRows, ?Filter $filter): ?Result
	{
		// action is done on frontend via crm.client-tab
		return null;
	}

	public function getControl(): ?array
	{
		$text = (string)Loc::getMessage('CRM_GRID_PANEL_GROUP_ACTION_CLIENT_TAB_UNBIND');

		$onchange = new Onchange();
		$onchange->addAction([
			'ACTION' => Actions::CALLBACK,
			'DATA' => [
				[
					'JS' => $this->buildJsCallback(),
				],
			],
		]);

		// the grid address keeps the id unique: a card shows several panels at once
		return (new Button())
			->setId(self::getId() . '_' . $this->gridId)
			->setClass(self::BUTTON_CLASS)
			->setText($text)
			->setTitle($text)
			->setOnchange($onchange)
			->toArray()
		;
	}

	private function buildJsCallback(): string
	{
		$gridId = \CUtil::JSEscape($this->gridId);

		return "BX.Crm.ClientTab?.Manager?.getByGridId('{$gridId}')?.unbindSelected();";
	}
}
