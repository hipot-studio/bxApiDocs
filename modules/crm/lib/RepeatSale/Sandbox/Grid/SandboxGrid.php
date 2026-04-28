<?php

namespace Bitrix\Crm\RepeatSale\Sandbox\Grid;

use Bitrix\Crm\RepeatSale\Sandbox\Grid\Column\Provider\SandboxDataProvider;
use Bitrix\Crm\RepeatSale\Sandbox\Grid\Row\Assembler\SandboxRowAssembler;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Row\Rows;
use Bitrix\Main\Grid\Settings;

final class SandboxGrid extends Grid
{
	public function __construct(
		Settings $settings,
		private readonly UserPermissions $userPermissions,
	)
	{
		parent::__construct($settings);
	}

	protected function createColumns(): Columns
	{
		return new Columns(new SandboxDataProvider());
	}

	protected function createRows(): Rows
	{
		return new Rows(
			new SandboxRowAssembler($this->getVisibleColumnsIds()),
			new Row\Action\SandboxDataProvider($this->getSettings(), $this->userPermissions),
		);
	}
}
