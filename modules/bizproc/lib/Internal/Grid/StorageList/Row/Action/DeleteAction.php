<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\StorageList\Row\Action;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Grid\Row\Action\BaseAction;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class DeleteAction extends BaseAction
{
	public static function getId(): string
	{
		return 'delete';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	public function getControl(array $rawFields): ?array
	{
		$storageId = is_numeric($rawFields['ID'] ?? null) ? (int)$rawFields['ID'] : 0;

		if ($storageId <= 0)
		{
			return null;
		}

		$this->onclick = sprintf(
			'BX.Bizproc.Component.StorageList.Instance?.removeStorage(%d);',
			$storageId,
		);

		return parent::getControl($rawFields);
	}

	protected function getText(): string
	{
		return Loc::getMessage('BIZPROC_STORAGE_LIST_GRID_ACTION_DELETE') ?? '';
	}
}
