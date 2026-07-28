<?php
/**
 * Guest page view. Rendered by {@see \Bitrix\Im\V2\Guest\Controller\Page::viewAction()}.
 * Replicates a minimal AIR layout instead of loading bitrix24/header.php — we don't
 * want the full intranet chrome (search/license/helpdesk/settings) on a guest page.
 */

use Bitrix\Im\V2\Service\Locator;
use Bitrix\Intranet\Integration\Templates\Air\AirTemplate;
use Bitrix\Intranet\Integration\Templates\Air\ChatMenu;
use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

/** @var array $arParams */
$dialogId = (string)($arParams['dialogId'] ?? '');
$isGuestWelcome = (bool)($arParams['isGuestWelcome'] ?? false);

// MUST be set before Extension::load — im.v2.lib.layout/config.php publishes
// 'isQuickAccessHidden' from this constant; if it lands false, MessengerSlider
// opens the chat in a side-panel slider instead of treating it as embedded.
if (!defined('BX_IM_FULLSCREEN'))
{
	define('BX_IM_FULLSCREEN', true);
}

\Bitrix\Main\UI\Extension::load([
	'ui.design-tokens',
	'ui.counter',
	'ui.buttons',
	'ui.icon-set.solid',
	'ui.icon-set.outline',
	'intranet.sidepanel.air',
	// SiteTemplate (bitrix24.bundle.js) instantiates SkipToContent first thing in
	// its constructor — without this extension the constructor throws and the
	// rest of the init chain (Header/LeftMenu/RightSidebar/RightPanel/ChatMenu)
	// silently never runs, leaving the chat sidebar stuck loading.
	'intranet.skip-to-content',
	'socialnetwork.slider',
	'ls',
	'helper',
	'im.v2.application.messenger',
	'im.v2.application.launch',
]);

Loader::includeModule('ui');

// Minimal frame URL — intentionally omit user_id (no portal user) and the current
// page URL (it carries the /guest/{code} join token). is_cloud lets the widget
// pick the right article corpus.
$helpdeskWidgetUrl = (new Uri(\Bitrix\UI\Util::getHelpdeskUrl(true) . '/widget2/'))
	->addParams([
		'is_cloud' => IsModuleInstalled('bitrix24') ? '1' : '0',
		'action' => 'open',
	])
	->getUri()
;

$asset = Asset::getInstance();
$bitrix24TemplatePath = '/bitrix/templates/bitrix24';

$bodyClasses = AirTemplate::getBodyClasses() . ' im-chat-embedded';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="<?= SITE_CHARSET ?>"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no"/>
<?php
$asset->addCss($bitrix24TemplatePath . '/dist/bitrix24.bundle.css', true);
$asset->addCss($bitrix24TemplatePath . '/components/bitrix/menu/left_vertical/style.css', true);
$asset->addCss('/bitrix/components/bitrix/main.interface.buttons/templates/.default/style.css', true);
$asset->addJs($bitrix24TemplatePath . '/dist/bitrix24.bundle.js', true);

$APPLICATION->showHead(false);
AirTemplate::showHeadAssets();
?>
<style>
	/* Embedded messenger border-radius — shared messenger.css zeroes it inside
	   #messenger-embedded-application and expects the host template to set it. */
	#messenger-embedded-application {
		position: relative;
		height: 100%;
		border-radius: 12px 12px 0 0;
		overflow: hidden;
	}
	#messenger-embedded-application .bx-im-messenger__container {
		position: absolute;
		width: 100%;
		height: 100%;
		border-radius: 12px 12px 0 0;
	}
	/* Hide the "upgrade tariff" banner for guests — left_vertical/template.php
	   renders .menu-license-all-wrapper from SHOW_LICENSE_BUTTON, set by intranet
	   result_modifier which has no awareness of the guest context. */
	.menu-license-all-wrapper {
		display: none !important;
	}
</style>
<title><?php $APPLICATION->showTitle(); ?></title>
</head>
<body class="<?= $bodyClasses ?>" <?= AirTemplate::getBodyAttributes() ?>><?php
AirTemplate::restoreRightPanelBodyState();
AirTemplate::showBodyAssets();
?>
<div class="root menu-collapsed-mode js-app">
	<div class="app__left-menu js-app__left-menu --air-context-blurred-bg">
		<?php
		$APPLICATION->includeComponent(
			'bitrix:menu',
			'left_vertical',
			[
				'ROOT_MENU_TYPE' => 'left',
				'MENU_CACHE_TYPE' => 'N',
				'MAX_LEVEL' => '1',
				'USE_EXT' => 'N',
				'DELAY' => 'N',
				'ALLOW_MULTI_SELECT' => 'N',
				'SHOW_SETTINGS_BUTTON' => 'N',
			],
			false
		);
		// .menu-collapsed-mode on .root drives CSS, but BX.Intranet.LeftMenu caches
		// isCollapsedMode from Menu::isCollapsed() which returns false for guests.
		// Without this sync, switchToSlidingMode won't open the sliding panel.
		?>
		<script>
			if (BX.Intranet && BX.Intranet.LeftMenu)
			{
				BX.Intranet.LeftMenu.isCollapsedMode = true;
			}
		</script>
	</div>
	<header class="app__header" id="app-header">
		<div class="air-header --air-context-blurred-bg" id="header">
			<button class="air-header__burger --ui-hoverable" id="air-header-burger" type="button">
				<span class="air-header__burger-icon"></span>
			</button>
			<div class="air-header__menu" id="air-header-menu">
				<?php
				$APPLICATION->includeComponent(
					'bitrix:main.interface.buttons',
					'',
					[
						'ID' => 'chat-menu',
						'ITEMS' => ChatMenu::getMenuItems(),
						'THEME' => 'air',
					]
				);
				?>
			</div>
		</div>
	</header>
	<div class="app__page" id="page-area">
		<div class="page no-all-paddings no-background no-page-header no-footer-endless">
			<div class="page__workarea">
				<main class="page__workarea-content">
					<?php
					$messengerConfig = Locator::getMessenger()->getApplication()->getConfig();
					$configJson = Json::encode($messengerConfig);
					?>
					<div id="messenger-embedded-application"></div>
					<script>
					BX.ready(function() {
						var config = <?= $configJson ?>;
						config.dialogId = '<?= CUtil::JSEscape($dialogId) ?>';
						config.isGuestWelcome = <?= $isGuestWelcome ? 'true' : 'false' ?>;
						BX.Messenger.v2.Application.Launch('messenger', config)
							.then(function(application) {
								application.initComponent('#messenger-embedded-application');
							})
						;

						BX.Helper.init({
							frameOpenUrl: '<?= CUtil::JSEscape($helpdeskWidgetUrl) ?>',
							langId: '<?= LANGUAGE_ID ?>'
						});

						// Stock BX.Helper.show appends window.location.href to the iframe URL,
						// which would leak the /guest/{code} chat join token to helpdesk.
						// Reimplement the open path without that injection; the other helpdesk
						// integrations (notification counter, hero, sessid POSTs) stay dormant
						// because we don't pass currentStepId/needCheckNotify/notifyData to init.
						BX.Helper.show = function(additionalParam, sliderOptions) {
							if (!BX.Type.isPlainObject(sliderOptions))
							{
								sliderOptions = {};
							}

							const frameUrl = this.frameOpenUrl
								+ (this.frameOpenUrl.indexOf('?') < 0 ? '?' : '&')
								+ (BX.type.isNotEmptyString(additionalParam) ? additionalParam : '');
							let url = new URL(frameUrl);
							url.searchParams.delete('url');
							url = url.toString();

							if (this.getFrame().src !== url)
							{
								this.getFrame().src = url;
							}

							BX.SidePanel.Instance.open(this.getSliderId(), {
								contentCallback: function() {
									var promise = new BX.Promise();
									promise.fulfill(this.getContent());
									return promise;
								}.bind(this),
								width: this.isNewHelpdesk ? null : 860,
								cacheable: false,
								zIndex: sliderOptions.zIndex || null,
								events: {
									onCloseComplete: function() { BX.Helper.close(); },
									onLoad: function() { BX.Helper.showFrame(); },
									onClose: function() {
										BX.Helper.frameNode.contentWindow.postMessage(
											{ action: 'onCloseWidget' },
											'*'
										);
									}
								}
							});
						};
					});
					</script>
				</main>
			</div>
		</div>
	</div>
</div>
<?php
// Manual buffer skips epilog_after.php, so main:OnEpilog never fires and
// CPullOptions::OnEpilog never queues the BX.PULL.start() script. Invoke it
// directly to bring pull into the connection loop on this page.
if (Loader::includeModule('pull'))
{
	\CPullOptions::OnEpilog();
}

$APPLICATION->showBodyScripts();
?>
</body>
</html>
