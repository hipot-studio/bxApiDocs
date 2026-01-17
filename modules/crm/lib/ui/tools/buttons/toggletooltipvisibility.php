<?php

namespace Bitrix\Crm\UI\Tools\Buttons;

use Bitrix\Main\Localization\Loc;
use Bitrix\UI\Buttons\JsCode;
use Bitrix\UI\Buttons\JsEvent;
use Bitrix\UI\Buttons\JsHandler;
use CUserOptions;

final class ToggleTooltipVisibility
{
	private array $params;
	private const CSS_CLASS_BASE = 'menu-popup-toggle-tooltips';
	private const CSS_CLASS_ACTIVE = 'menu-popup-item-accept';
	private const CSS_CLASS_INACTIVE = 'menu-popup-item-none';

	public function __construct(
		?string $text = null,
		?string $className = null,
		JsCode|JsEvent|JsHandler|null $onclick = null,
	)
	{
		$defaultParams = $this->getDefaultParams();

		$this->params = [
			'text' => $text ?? $defaultParams['text'],
			'className' => $className ?? $defaultParams['className'],
			'onclick' => $onclick ?? $defaultParams['onclick'],
		];
	}

	public function getArrayParams(): array
	{
		return $this->params;
	}

	private function getDefaultParams(): array
	{
		$className =
			CUserOptions::GetOption('crm', 'should_show_tooltips_kanban', true)
				? self::CSS_CLASS_ACTIVE
				: self::CSS_CLASS_INACTIVE;

		$className .= ' ' . self::CSS_CLASS_BASE;

		return [
			'text' => Loc::getMessage('CRM_TOOLBAR_BUTTONS_TOGGLE_TOOLTIP_VISIBILITY_TEXT'),
			'className' => $className,
			'onclick' => $this->getJsCode(),
		];
	}

	private function getJsCode(): JsCode
	{
		$baseCssClass = self::CSS_CLASS_BASE;
		$activeCssClass = self::CSS_CLASS_ACTIVE;
		$inactiveCssClass = self::CSS_CLASS_INACTIVE;

		//language=JS
		$code = "
			const thisButton = document.getElementsByClassName('{$baseCssClass}')[0];
			const currentClasses = thisButton.classList;

			BX.Dom.toggleClass(thisButton, ['{$activeCssClass}', '{$inactiveCssClass}']);

			BX.Event.EventEmitter.emit('BX.Crm.Kanban:toggleTooltipsVisibility');
		";

		return new JsCode($code);
	}
}
