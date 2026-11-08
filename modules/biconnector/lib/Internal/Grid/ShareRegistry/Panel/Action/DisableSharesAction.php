<?php

namespace Bitrix\BIConnector\Internal\Grid\ShareRegistry\Panel\Action;

use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Grid\Panel;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class DisableSharesAction implements Panel\Action\Action
{
	public static function getId(): string
	{
		return 'disable';
	}

	public function processRequest(HttpRequest $request, bool $isSelectedAllRows, ?Filter $filter): ?Result
	{
		return null;
	}

	public function getControl(): ?array
	{
		$onchange = new Panel\Snippet\Onchange();
		$onchange->addAction([
			'ACTION' => Panel\Actions::CALLBACK,
			'DATA' => [
				[
					'JS' => 'BX.BIConnector.ShareRegistry.GridManager.Instance.disableSelected();',
				],
			],
		]);

		$button = new Panel\Snippet\Button();
		$button->setId('biconnector_share_registry_disable');
		$button->setOnchange($onchange);
		$button->setText(Loc::getMessage('BICONNECTOR_SHARE_REGISTRY_PANEL_DISABLE') ?? '');

		return $button->toArray();
	}
}
