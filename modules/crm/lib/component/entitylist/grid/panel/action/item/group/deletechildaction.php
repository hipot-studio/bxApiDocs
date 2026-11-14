<?php

namespace Bitrix\Crm\Component\EntityList\Grid\Panel\Action\Item\Group;

use Bitrix\Crm\Component\EntityList\Grid\Panel\ApplyButton;
use Bitrix\Crm\Component\EntityList\Grid\Panel\Event;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Grid\Panel\Action\Group\GroupChildAction;
use Bitrix\Main\Grid\Panel\Actions;
use Bitrix\Main\Grid\Panel\Snippet\Onchange;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * Deletion from the dropdown of the group actions, for the panels without a separate "Delete" button.
 * Runs the same process as \Bitrix\Crm\Component\EntityList\Grid\Panel\Action\Item\DeleteAction.
 */
final class DeleteChildAction extends GroupChildAction
{
	public function __construct(private int $entityTypeId, private string $gridId)
	{
	}

	public static function getId(): string
	{
		return 'delete';
	}

	public function getName(): string
	{
		Container::getInstance()->getLocalization()->loadMessages();

		return (string)Loc::getMessage('CRM_COMMON_ACTION_DELETE');
	}

	public function processRequest(HttpRequest $request, bool $isSelectedAllRows, ?Filter $filter): ?Result
	{
		// deletion done on frontend via crm.autorun
		return null;
	}

	protected function getOnchange(): Onchange
	{
		$callback =
			(new Event('BatchManager:executeDeletion'))
				->addEntityTypeId($this->entityTypeId)
				->addGridId($this->gridId)
				->buildJsCallback()
		;

		$onchange = new Onchange();
		$onchange->addAction([
			'ACTION' => Actions::CREATE,
			'DATA' => [
				ApplyButton::forGrid($this->gridId, [
					'ONCHANGE' => [
						[
							'ACTION' => Actions::CALLBACK,
							'DATA' => [
								[
									'JS' => $callback,
								],
							],
						],
					],
				]),
			],
		]);

		return $onchange;
	}
}
