<?php
namespace Bitrix\Landing\Hook\Page;

use \Bitrix\Landing\Field;
use Bitrix\Landing\AI\SiteBuilder\Tailwind\TailwindRuntimeEligibilityService;
use \Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class CssBlock extends \Bitrix\Landing\Hook\Page
{
	private const AI_TAILWIND_RUNTIME_CSS_MARKER = 'ai-tailwind-runtime=1';

	/**
	 * End tag open sequence which closes a raw text element: '</' + tag name + (whitespace | '/' | '>').
	 * End of input is covered as a defensive case: it closes nothing by itself, but anything appended after would.
	 */
	private const STYLE_END_TAG_PATTERN = '#</style(?=[\s/>]|$)#i';

	/**
	 * '\3c ' is the CSS escape for '<', so the sequence keeps its CSS meaning
	 * but is no longer an end tag for the HTML parser.
	 */
	private const ESCAPED_STYLE_END_TAG = '\3c /style';

	private int $landingId = 0;

	public function setLandingId(int $landingId): void
	{
		$this->landingId = max(0, $landingId);
	}

	/**
	 * Map of the field.
	 * @return array
	 */
	protected function getMap()
	{
		return array(
			'USE' => new Field\Checkbox('USE', array(
				'title' => Loc::getMessage('LANDING_HOOK_CSSLOCK_USE')
			)),
			'CODE' => new Field\Textarea('CODE', array(
				'title' => Loc::getMessage('LANDING_HOOK_HEADBLOCK_CSS_CODE'),
				'help' => Loc::getMessage('LANDING_HOOK_HEADBLOCK_CSS_CODE_HELP2'),
				'placeholder' => '* {display: none;}'
			)),
			'FILE' => new Field\Textarea('FILE', array(
				'title' => Loc::getMessage('LANDING_HOOK_HEADBLOCK_CSS_FILE')
			))
		);
	}

	/**
	 * Title of Hook, if you want.
	 * @return string
	 */
	public function getTitle()
	{
		return Loc::getMessage('LANDING_HOOK_CSSBLOCK_NAME');
	}

	/**
	 * Get sort of block (execute order).
	 * @return int
	 */
	public function getSort()
	{
		return 500;
	}

	/**
	 * Enable or not the hook.
	 * @return boolean
	 */
	public function enabled()
	{
		if ($this->issetCustomExec())
		{
			return true;
		}

		return $this->fields['USE']->getValue() == 'Y';
	}

	/**
	 * Exec or not hook in edit mode.
	 * @return boolean
	 */
	public function enabledInEditMode()
	{
		$cssFile = trim((string)$this->fields['FILE']->getValue());

		return $this->isTailwindRuntimeCssFile($cssFile) && $this->isTailwindRuntimeAllowed();
	}

	/**
	 * Exec hook.
	 * @return void
	 */
	public function exec()
	{
		if ($this->execCustom())
		{
			return;
		}

		$cssCode = trim($this->fields['CODE']);
		$cssFile = trim($this->fields['FILE']);

		if ($cssCode != '')
		{
			// user code, never print it without escapeCssForStyleTag()
			echo '<style type="text/css">' . self::escapeCssForStyleTag($cssCode) . '</style>';
		}
		if ($cssFile != '')
		{
			if ($this->isTailwindRuntimeCssFile($cssFile) && !$this->isTailwindRuntimeAllowed())
			{
				return;
			}

			echo '<link href="' . \htmlspecialcharsbx($cssFile) . '" type="text/css"  rel="stylesheet" />';
		}
	}

	/**
	 * Prevents the CSS from breaking out of the style tag; it does not make arbitrary CSS itself safe.
	 * Valid CSS is kept untouched, except for the known limitation below.
	 * Every place which prints the user CSS code into HTML must call this method.
	 * Known limitation: a backslash right before the end tag sequence, as in 'content: "\</style>"',
	 * merges with the inserted escape, so such CSS is altered.
	 * @param string $css Raw CSS code.
	 * @return string
	 */
	public static function escapeCssForStyleTag(string $css): string
	{
		$escaped = preg_replace_callback(
			self::STYLE_END_TAG_PATTERN,
			static fn(): string => self::ESCAPED_STYLE_END_TAG,
			$css
		);

		return $escaped ?? '';
	}

	private function isTailwindRuntimeCssFile(string $cssFile): bool
	{
		return $cssFile !== '' && mb_stripos($cssFile, self::AI_TAILWIND_RUNTIME_CSS_MARKER) !== false;
	}

	private function isTailwindRuntimeAllowed(): bool
	{
		return $this->landingId > 0 && (new TailwindRuntimeEligibilityService())->isLandingSupported($this->landingId);
	}
}
