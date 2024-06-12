<?php

namespace Bitrix\Intranet\User\Grid\Row\Action;

use Bitrix\Intranet\User\Grid\Settings\UserSettings;
use Bitrix\Main\Web\Json;

abstract class JsGridAction extends \Bitrix\Main\Grid\Row\Action\BaseAction
{
	private string $extensionMethod;
	private string $extensionName;
	private string $gridId;

	public function __construct(UserSettings $settings)
	{
		$this->extensionMethod = $this->getExtensionMethod();
		$this->extensionName = $settings->getExtensionName();
		$this->gridId = $settings->getID();
	}

	abstract public function getExtensionMethod(): string;
	abstract protected function getActionParams(array $rawFields): array;

	public function isAvailable(array $rawFields): bool
	{
		return true;
	}

	public function getControl(array $rawFields): ?array
	{
		if ($this->isAvailable($rawFields))
		{
			$extension = $this->extensionName;
			$method = $this->extensionMethod;
			$gridId = $this->gridId;
			$params = Json::encode($this->getActionParams($rawFields));
			$this->onclick = "BX.$extension.GridManager.getInstance('$gridId').$method($params)";

			return parent::getControl($rawFields);
		}

		return null;
	}
}