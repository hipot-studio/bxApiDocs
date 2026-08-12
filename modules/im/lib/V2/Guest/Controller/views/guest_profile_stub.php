<?php
/**
 * "No access" stub shown to an im guest who opens an employee profile (/company/personal/user/).
 * Rendered from {@see \Bitrix\Im\V2\Guest\Auth\GuestApplication::onApplicationScopeError()} — the
 * guest can't pass the profile page's file-level access, so the intranet profile component (and
 * its socialnetwork.entity.error stub) is never reached. We reproduce that same stub verbatim:
 * identical markup, the component's own style.css (self-contained SVG illustration) and phrases.
 *
 * Kept self-contained (plain <link>, no Extension/ShowHead) because this runs from the early
 * application-scope error handler, before the page-asset pipeline is set up.
 */

use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . '/bitrix/components/bitrix/socialnetwork.entity.error/class.php');

$title = (string)Loc::getMessage('SOCIALNETWORK_ENTITY_ERROR_COMPONENT_TITLE_USER');
$description = (string)Loc::getMessage('SOCIALNETWORK_ENTITY_ERROR_COMPONENT_DESCRIPTION_USER');
$charset = defined('SITE_CHARSET') ? SITE_CHARSET : 'UTF-8';
// Design tokens + Open Sans web-font supply the --ui-font-family-* / --ui-font-weight-light
// CSS variables the stub's style.css relies on; linked directly (no asset pipeline here) so the
// stub matches the socialnetwork.entity.error component pixel-for-pixel, not a serif fallback.
$cssUrls = [
	'/bitrix/js/ui/design-tokens/dist/ui.design-tokens.css',
	'/bitrix/js/ui/fonts/opensans/ui.font.opensans.css',
	'/bitrix/components/bitrix/socialnetwork.entity.error/templates/.default/style.css',
];
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="<?= $charset ?>"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no"/>
<?php foreach ($cssUrls as $cssUrl): ?>
<link rel="stylesheet" href="<?= $cssUrl ?>"/>
<?php endforeach; ?>
<style>
	html, body { margin: 0; height: 100%; }
	.sonet-entity-error { min-height: 100%; box-sizing: border-box; }
</style>
<title><?= htmlspecialcharsbx($title) ?></title>
</head>
<body>
<div class="sonet-entity-error">
	<div class="sonet-entity-error-inner">
		<div class="sonet-entity-error-title"><?= htmlspecialcharsbx($title) ?></div>
		<div class="sonet-entity-error-subtitle"><?= htmlspecialcharsbx($description) ?></div>
		<div class="sonet-entity-error-img">
			<div class="sonet-entity-error-img-inner"></div>
		</div>
	</div>
</div>
</body>
</html>
