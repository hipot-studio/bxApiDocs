<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use \Bitrix\Landing\Manager;
use \Bitrix\Landing\Landing;

class LandingBlocksHtmlComponent extends \CBitrixComponent
{
	/**
	 * Semantic tags the author of the block may use. A tag is on the list only when it carries its
	 * meaning by itself, with no javascript and no form element behind it - the same measure that
	 * keeps the interactive roles out of ROLES below. So `button` and `label` are not here: the
	 * white list has no form to submit and no script to run, and a focusable stop announced as a
	 * button that answers nothing costs a keyboard reader more than the tag gives.
	 *
	 * None of them exists at SECURE_LEVEL_LOW, so nothing is taken away: AddTags() replaces the
	 * attribute list of a tag already on the list.
	 */
	private const SEMANTIC_TAGS = [
		'nav', 'main', 'section', 'article', 'aside', 'header', 'footer',
		'figure', 'figcaption',
	];

	/**
	 * The attributes SECURE_LEVEL_LOW gives to a tag carrying nothing of its own. `id` is among them
	 * because aria-labelledby and aria-describedby have nothing to point at without it.
	 */
	private const SEMANTIC_TAG_ATTRIBUTES = ['style', 'id', 'class'];

	/**
	 * Landmarks, document structure and live regions - what a page describes about itself.
	 *
	 * Roles of interactive widgets (button, tab, menuitem and the like) are left out on purpose:
	 * every one of them promises keyboard behaviour that only javascript gives, and javascript never
	 * survives this sanitizer. A native tag from SEMANTIC_TAGS carries the behaviour with it.
	 */
	private const ROLES = [
		'banner', 'complementary', 'contentinfo', 'form', 'main', 'navigation', 'region', 'search',
		'article', 'definition', 'figure', 'group', 'heading', 'img', 'list', 'listitem', 'note',
		'none', 'presentation', 'separator', 'term',
		'table', 'rowgroup', 'row', 'cell', 'columnheader', 'rowheader',
		'alert', 'status',
	];

	private const SCOPES = ['row', 'col', 'rowgroup', 'colgroup'];
	private const BOOLEAN_VALUES = ['true', 'false'];
	private const CURRENT_VALUES = ['page', 'step', 'location', 'date', 'time', 'true', 'false'];

	/**
	 * Sanitizes bad code.
	 * @param string $str Very bad html with <script>, etc.
	 * @return string
	 */
	public function sanitize($str)
	{
		static $sanitizer = null;

		if ($sanitizer === null)
		{
			$sanitizer = new \CBXSanitizer;
			$sanitizer->SetLevel($sanitizer::SECURE_LEVEL_LOW);
			// SetLevel() refills the white list from scratch, so the accessible markup is added after
			// it. AddTags() relabels the level as SECURE_LEVEL_CUSTOM on the way: SanitizeHtml() reads
			// the white list and never the label, so the relabelling changes nothing.
			$sanitizer->AddTags(array_fill_keys(self::SEMANTIC_TAGS, self::SEMANTIC_TAG_ATTRIBUTES));
			$sanitizer->allowAttributes(self::accessibilityAttributes());
		}

		return $sanitizer->sanitizeHtml($str);
	}

	/**
	 * Attributes of accessibility with a check of the value for every one of them. A mask is not an
	 * option: the sanitizer looks an attribute up by its exact name, so each is named here.
	 *
	 * Event handlers, the tag of a script and the executable scheme of a link are untouched by all of
	 * this and stay forbidden - on a site without a domain of its own the page shares the origin with
	 * the portal, and code stored in a block would run under the address the visitor trusts.
	 * @return array
	 */
	private static function accessibilityAttributes(): array
	{
		$anyAllowedTag = static fn (string $tag): bool => true;
		$oneOf = static fn (array $values): callable
			=> static fn (string $value): bool => in_array(mb_strtolower(trim($value)), $values, true);
		$idList = static fn (string $value): bool
			=> (bool)preg_match('/^[\w\-.:]+(\s+[\w\-.:]+)*$/u', trim($value));

		return [
			'role' => ['tag' => $anyAllowedTag, 'content' => $oneOf(self::ROLES)],
			'aria-level' => [
				'tag' => $anyAllowedTag,
				// role="heading" without a level of its own is read as a level 2 heading, so the
				// outline the author wrote is not the outline the reader hears.
				//
				// The pair with the role is left to the author: IsValidAttr() hands a check the value
				// of its own attribute and nothing else of the tag, so a level standing without a role
				// cannot be refused here. Such a tag is invalid ARIA and an audit says so; the same
				// already holds for aria-expanded and the rest of the states below.
				'content' => static fn (string $value): bool
					=> (bool)preg_match('/^[1-6]$/D', trim($value)),
			],
			'lang' => [
				'tag' => $anyAllowedTag,
				'content' => static fn (string $value): bool
					=> (bool)preg_match('/^[a-z]{2,3}(-[a-z\d]{2,8})*$/i', trim($value)),
			],
			'scope' => [
				'tag' => static fn (string $tag): bool => $tag === 'th',
				'content' => $oneOf(self::SCOPES),
			],
			'aria-label' => [
				'tag' => $anyAllowedTag,
				// A name is read aloud and never parsed, so an angle bracket in it can only be an
				// attempt to leave the attribute. A quote is left to the author: the sanitizer
				// re-encodes the value it prints, and a name like `Company's contacts` would
				// otherwise be dropped whole.
				'content' => static fn (string $value): bool => !preg_match('/[<>]/u', $value),
			],
			'aria-labelledby' => ['tag' => $anyAllowedTag, 'content' => $idList],
			'aria-describedby' => ['tag' => $anyAllowedTag, 'content' => $idList],
			'aria-hidden' => ['tag' => $anyAllowedTag, 'content' => $oneOf(self::BOOLEAN_VALUES)],
			'aria-expanded' => ['tag' => $anyAllowedTag, 'content' => $oneOf(self::BOOLEAN_VALUES)],
			'aria-current' => ['tag' => $anyAllowedTag, 'content' => $oneOf(self::CURRENT_VALUES)],
		];
	}

	/**
	 * Is the html of the block passed through the sanitizer before the output?
	 *
	 * Raw html is allowed on a site with a domain of its own only. The sandboxed preview
	 * (signed link or device frame) is served on the portal host whatever the domain is: the
	 * opaque origin hides the portal session from the code of the author, but a form or a
	 * script drawn under the trusted portal address would still be believed by the visitor.
	 * So there the html is sanitized the same way as without a domain — same as the head-block
	 * hook, which is off in that mode (Hook\Page\HeadBlock::enabled()).
	 * @param int $domainId Domain id of the site, 0 when the site has no domain of its own.
	 * @return bool
	 */
	public function mustSanitize(int $domainId): bool
	{
		return !$domainId || Landing::getDevicePreviewMode();
	}

	/**
	 * Local htmlspecialcharsback function.
	 * @param string $code Code for decoding.
	 * @return string
	 */
	public function htmlspecialcharsback($code)
	{
		$code = \htmlspecialcharsback($code);
		$code = str_replace('&#39;', "'", $code);
		return $code;
	}

	/**
	 * Base executable method.
	 * @return void
	 */
	public function executeComponent()
	{
		if (!\Bitrix\Main\Loader::includeModule('landing'))
		{
			return;
		}

		$this->arParams['ENABLED'] = 'Y';
		$this->arParams['EDIT_MODE'] = Landing::getEditMode() ? 'Y' : 'N';
		$this->arParams['PREVIEW_MODE'] = Landing::getPreviewMode() ? 'Y' : 'N';

		// prepare params
		if (
			!isset($this->arParams['SKIP_MOVING_FALSE']) ||
			$this->arParams['SKIP_MOVING_FALSE'] != 'Y'
		)
		{
			$this->arParams['SKIP_MOVING_FALSE'] = 'N';
		}
		if (!isset($this->arParams['~HTML_CODE']))
		{
			$this->arParams['~HTML_CODE'] = '';
		}

		// skip moving js to bottom of the page
		if ($this->arParams['SKIP_MOVING_FALSE'] != 'Y')
		{
			$this->arParams['~HTML_CODE'] = str_replace(
				'&lt;script',
				'&lt;script data-skip-moving="true"',
				$this->arParams['~HTML_CODE']
			);
		}

		// tariff feature
		if (
			isset($this->arParams['ONLY_PAYED']) &&
			$this->arParams['ONLY_PAYED'] == 'Y'
		)
		{
			$this->arParams['ONLY_PAYED'] = 'Y';
		}
		else
		{
			$this->arParams['ONLY_PAYED'] = 'N';
		}

		// if enabled this feature
		if ($this->arParams['ONLY_PAYED'] == 'Y')
		{
			$this->arParams['ENABLED'] = Manager::checkFeature(
				Manager::FEATURE_ENABLE_ALL_HOOKS,
				['hook' => 'headblock']
			) ? 'Y' : 'N';
		}

		$this->IncludeComponentTemplate();
	}
}