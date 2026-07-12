<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Panel;

use Bitrix\Main\Grid\Panel\Action\Action;
use Bitrix\Main\Grid\Panel\Action\DataProvider;

class SharedPanelDataProvider extends DataProvider
{
	/** @var Action[] */
	private array $panelActions;

	public function __construct(Action ...$actions)
	{
		parent::__construct();
		$this->panelActions = $actions;
	}

	public function prepareActions(): array
	{
		return $this->panelActions;
	}
}
