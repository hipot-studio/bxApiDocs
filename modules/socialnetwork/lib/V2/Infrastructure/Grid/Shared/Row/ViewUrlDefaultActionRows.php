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

		$row['default_action'] = [
			'onclick' => ProjectListControllerActionBuilder::buildDefaultRowAction($viewUrl),
			'title' => $this->defaultActionTitle,
		];

		return $row;
	}
}
