<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Transfer\Requisite;
use Bitrix\Landing\Transfer\Script\Action\Closet\AppearanceMode;
use Bitrix\Landing\Transfer\Script\Action\Closet\IAction;

class ActionConfig
{
	private string $className;
	private AppearanceMode $appearanceMode = AppearanceMode::Always;

	public function __construct(string $actionClass)
	{
		$this->className = $actionClass;
	}

	/**
	 * How often in script action is running
	 * @param AppearanceMode $appearanceMode
	 * @return ActionConfig
	 */
	public function setAppearanceMode(AppearanceMode $appearanceMode): static
	{
		$this->appearanceMode = $appearanceMode;

		return $this;
	}

	public function getAppearanceMode(): AppearanceMode
	{
		return $this->appearanceMode;
	}

	public function createAction(Requisite\Context $context): IAction
	{
		return new $this->className($context);
	}
}
