<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Category\Deal\Grid\Row\Action;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Grid\Row\Action\BaseAction;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

final class EditAction extends BaseAction
{
	private const EVENT_NAME = 'Crm.DealCategoryList:onAction';

	public static function getId(): ?string
	{
		return 'edit';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		Container::getInstance()->getLocalization()->loadMessages();

		return Loc::getMessage('CRM_COMMON_ACTION_EDIT');
	}

	public function getControl(array $rawFields): ?array
	{
		$id = (int)($rawFields['ID'] ?? -1);
		if ($id < 0)
		{
			return null;
		}

		$this->onclick = $this->buildOnclick($id);

		return parent::getControl($rawFields);
	}

	private function buildOnclick(int $id): string
	{
		$payload = Json::encode([
			'actionId' => self::getId(),
			'id' => $id,
		]);

		return "BX.Event.EventEmitter.emit('" . self::EVENT_NAME . "', {$payload});";
	}
}
