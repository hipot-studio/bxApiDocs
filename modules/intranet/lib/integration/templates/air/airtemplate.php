<?php

namespace Bitrix\Intranet\Integration\Templates\Air;

use Bitrix\Intranet\Integration\Templates\Bitrix24\ThemePicker;
use Bitrix\Intranet\Internal\Integration;
use Bitrix\Main\ModuleManager;
use Bitrix\UI\Buttons;

final class AirTemplate
{

	static private ?bool $enabled = null;
	static private bool $applied = false;

	public static function isEnabled(): bool
	{
		return true;
	}

	private static function shouldBeEnabled(): bool
	{
		return true;
	}

	public static function isApplied(): bool
	{
		return true;
		//return self::$applied;
	}

	public static function tryApply(): bool
	{
		return true;
	}

	public static function getWorkAreaContent(): string
	{
		$bodyClass = $GLOBALS['APPLICATION']->getPageProperty('BodyClass');
		if (str_contains($bodyClass, 'no-background'))
		{
			return '';
		}

		return ' --ui-context-content-light';
	}

	public static function tryApplyDefaultTopMenu(): void
	{
		$content = $GLOBALS['APPLICATION']->getViewContent('above_pagetitle');
		if (!empty($content) || defined('AIR_TOP_HORIZONTAL_MENU_EXISTS') || defined("BX_BUFFER_SHUTDOWN"))
		{
			return;
		}

		$pageTitle = $GLOBALS['APPLICATION']->getTitle();
		if (empty($pageTitle))
		{
			$pageTitle = \Bitrix\Intranet\Portal::getInstance()->getSettings()->getTitle();
		}

		ob_start();
		$GLOBALS['APPLICATION']->includeComponent(
			'bitrix:main.interface.buttons',
			'',
			[
				'ID' => \Bitrix\Main\UuidGenerator::generateV4(),
				'ITEMS' =>[
					[
						'TEXT' => $pageTitle,
						'IS_ACTIVE' => true,
					]
				],
				'THEME' => 'air',
				'DISABLE_SETTINGS' => true,
			]
		);

		$content = ob_get_contents();
		ob_end_clean();

		$GLOBALS['APPLICATION']->addViewContent('above_pagetitle', $content);
	}

	/**
	 * @example $APPLICATION->addBufferContent([AirTemplate::class, 'getDefaultTopMenu']);
	 */
	public static function getDefaultTopMenu(): string
	{
		$content = $GLOBALS['APPLICATION']->getViewContent('above_pagetitle');
		if (!empty($content) || defined('AIR_TOP_HORIZONTAL_MENU_EXISTS') || defined("BX_BUFFER_SHUTDOWN"))
		{
			return '';
		}

		$trace = \Bitrix\Main\Diag\Helper::getBackTrace(0, DEBUG_BACKTRACE_IGNORE_ARGS);
		foreach ($trace as $traceLine)
		{
			if (
				isset($traceLine['function']) &&
				in_array(
					$traceLine['function'],
					['ob_end_flush', 'ob_end_clean', 'LocalRedirect', 'fastcgi_finish_request']
				)
			)
			{
				return '';
			}
		}

		$pageTitle = $GLOBALS['APPLICATION']->getTitle();
		if (empty($pageTitle))
		{
			$pageTitle = \Bitrix\Intranet\Portal::getInstance()->getSettings()->getTitle();
		}

		ob_start();
		$GLOBALS['APPLICATION']->includeComponent(
			'bitrix:main.interface.buttons',
			'',
			[
				'ID' => \Bitrix\Main\UuidGenerator::generateV4(),
				'ITEMS' =>[
					[
						'TEXT' => $pageTitle,
						'IS_ACTIVE' => true,
					]
				],
				'THEME' => 'air',
				'DISABLE_SETTINGS' => true,
			]
		);

		$result = ob_get_contents();
		ob_end_clean();

		return $result;
	}

	public static function showJsTitle(): void
	{
		$GLOBALS['APPLICATION']->addBufferContent([AirTemplate::class, 'getJsTitle']);
	}

	public static function showHeadAssets(): void
	{
		ThemePicker::getInstance()?->showHeadAssets();

		$accountConnection = new Integration\Im\Desktop\AccountConnection();
		if ($accountConnection->isAvailable() && $accountConnection->isRequired())
		{
			$accountConnection->addHeadScript();
		}
	}

	public static function showBodyAssets(): void
	{
		ThemePicker::getInstance()?->showBodyAssets();
	}

	public static function restoreRightPanelBodyState(): void
	{
		?>
		<script data-skip-moving="true">
		try {
			const _ss = sessionStorage;
			const _expanded = _ss.getItem('b24_right_panel_expanded');
			if (_expanded === 'Y' && !BX.Dom.hasClass(document.body, '--right-panel-expanded'))
			{
				BX.Dom.addClass(document.body, ['--right-panel-expanded', '--right-panel-no-transition']);
			}
			if (_expanded === 'N' && document.body.classList.contains('--right-panel-expanded'))
			{
				BX.Dom.removeClass(document.body, ['--right-panel-expanded', '--right-panel-no-transition']);
			}
			const _width = _ss.getItem('b24_right_panel_width');
			if (_width && parseInt(_width, 10) > 0)
			{
				BX.Dom.style(document.body, '--air-right-panel-width', `${_width}px`);
			}
		} catch(e) {}
		</script>
		<?php
	}

	public static function getJsTitle(): string
	{
		$title = $GLOBALS['APPLICATION']->getTitle('title', true);
		$title = html_entity_decode($title, ENT_QUOTES, SITE_CHARSET);

		return \CUtil::jsEscape($title);
	}

	public static function shouldShowImBar(): bool
	{
		return self::isMessengerEnabled() && !self::isMessengerEmbedded();
	}

	public static function isMessengerEnabled(): bool
	{
		return ModuleManager::isModuleInstalled('im') && \CBXFeatures::isFeatureEnabled('WebMessenger');
	}

	public static function isMessengerEmbedded(): bool
	{
		return defined('BX_IM_FULLSCREEN') && BX_IM_FULLSCREEN;
	}

	public static function getBodyClasses(): string
	{
		$bodyClasses = 'template-bitrix24 template-air';
		$bodyClasses .= ' ' . ThemePicker::getInstance()->getBodyClasses();

		if (!self::shouldShowImBar())
		{
			$bodyClasses .= ' im-bar-mode-off';
		}

		if (self::isRightPanelExpanded())
		{
			$bodyClasses .= ' --right-panel-expanded --right-panel-no-transition';
		}

		return $bodyClasses;
	}

	public static function getBodyAttributes(): string
	{
		$attributes = [];

		$savedWidth = (int)\CUserOptions::GetOption('intranet', 'right_panel_width', '380');

		$attributes[] = 'style="--air-right-panel-width: ' . $savedWidth . 'px"';

		return implode(' ', $attributes);
	}

	public static function isRightPanelExpanded(): bool
	{
		return (
			\CUserOptions::GetOption('aiassistant', 'marta_is_open', 'N') === 'Y'
			&& \CUserOptions::GetOption('aiassistant', 'show_chat_in_right_panel', 'N') === 'Y'
		);
	}

	public static function getCompositeBodyClasses(): string
	{
		$bodyClasses = [];

		if (self::isMessengerEmbedded())
		{
			$bodyClasses[] = 'im-chat-embedded';
		}

		return empty($bodyClasses) ? '' : '"'. join("', '", $bodyClasses). '"';
	}

	public static function getGoTopButton(): Buttons\Button
	{
		$goTopButton = new Buttons\Button([
			'air' => true,
			'icon' => Buttons\Icon::ANGLE_UP,
			'size' => Buttons\Size::SMALL,
		]);

		$goTopButton->addAttribute('id', 'goTopButton');
		$goTopButton->setStyle(Buttons\AirButtonStyle::OUTLINE);
		$goTopButton->setCollapsed();
		if (method_exists($goTopButton, 'setUniqId'))
		{
			$goTopButton->setUniqId('goTopButton');
		}

		return $goTopButton;
	}

	public static function getLang(): string
	{
		$context = \Bitrix\Main\Context::getCurrent();
		$locale = $context->getLanguageObject()?->getCode();
		if (empty($locale))
		{
			$locale = defined('LANGUAGE_ID') ? LANGUAGE_ID : '';
		}

		return $locale;
	}
}
