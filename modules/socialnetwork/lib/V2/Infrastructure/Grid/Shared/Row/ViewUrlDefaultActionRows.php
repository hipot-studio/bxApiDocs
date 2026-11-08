<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row;

use Bitrix\Main\Grid\Row\Action\DataProvider;
use Bitrix\Main\Grid\Row\RowAssembler;
use Bitrix\Main\Grid\Row\Rows;
use Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Js\ProjectListControllerActionBuilder;

class ViewUrlDefaultActionRows extends Rows
{
	public function __construct(
		RowAssembler $rowAssembler,
		private readonly string $defaultActionTitle = '',
		DataProvider ...$actionsProviders,
	)
	{
		parent::__construct($rowAssembler, ...$actionsProviders);
	}

	protected function prepareRow(array $rawValue): array
	{
		$row = parent::prepareRow($rawValue);

		$viewUrl = (string)($rawValue['VIEW_URL'] ?? '');
		if ($viewUrl === '')
		{
			return $row;
		}

		$entityId = (int)($rawValue['ID'] ?? 0);
		$entityType = is_string($rawValue['TYPE'] ?? null) ? $rawValue['TYPE'] : null;

		$row['default_action'] = [
			'onclick' => ProjectListControllerActionBuilder::buildDefaultRowAction(
				viewUrl: $viewUrl,
				entityId: $entityId > 0 ? $entityId : null,
				entityType: $entityType,
			),
			'title' => $this->defaultActionTitle,
		];

		return $row;
	}
}
