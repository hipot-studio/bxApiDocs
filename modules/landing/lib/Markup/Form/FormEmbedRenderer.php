<?php

namespace Bitrix\Landing\Markup\Form;

use Bitrix\Landing\Markup\Manifest;
use Bitrix\Landing\Subtype;
use Bitrix\Main\Web\Json;

/**
 * Puts the CRM form loaders into the entry document of a markup package at serve time (ADR D5).
 *
 * An inline marker gets the inline loader right after its start tag, a popup marker gets the click loader
 * right before its start tag: the loader opens the form by the next element. The first loader of a form
 * and design is preceded by a listener of `b24:form:init:before` that passes the design of the binding
 * to the form. Two tokens may share a form with different designs, so a loader with a design names its
 * instance by data-b24-id, and the listener passes the design to its own instances only.
 * A marker without a binding or without a form of the CRM is hidden by a service attribute and one rule
 * in the head.
 *
 * The CRM opens a form by click but does not cancel the click: a link trigger would take the visitor
 * away from the page. The first link trigger is preceded by one guard of the page that cancels
 * the navigation of the link triggers, other triggers keep their behavior. The helper of the frame
 * listens to the clicks of the document before the source and sends a link it gets uncancelled to the
 * page around the frame, so the guard cancels in the capture phase: before the helper, whatever
 * the order of the listeners, and with the click still reaching the CRM on the trigger.
 *
 * A page with a bound form gets in the head one rule that keeps the button and label styles of the site
 * off the forms and one submit bridge (ADR D9, B4): the frame has no allow-forms, so the browser blocks
 * the submission of the form of the CRM before its submit event. Both act on `.b24-form`: a popup form
 * is mounted into the body, away from its marker.
 *
 * The form id comes from the manifest, the security code and the loader address - from the CRM,
 * the same way the classic blocks get them (Subtype\Form::getFormById).
 */
class FormEmbedRenderer
{
	public const ATTRIBUTE_OFF = 'data-bx-crm-form-off';

	private const OFF_STYLE = '<style>[' . self::ATTRIBUTE_OFF . ']{display:none!important}</style>';
	private const LINK_TAGS = ['a', 'area'];
	private const LINK_TRIGGER_GUARD = '<script>document.addEventListener(\'click\',function(event){'
		. 'var target=event.target;'
		. 'if(target&&target.closest&&target.closest(\'a[' . FormMarkupScanner::ATTRIBUTE_POPUP . '],'
		. 'area[' . FormMarkupScanner::ATTRIBUTE_POPUP . ']\')){event.preventDefault();}'
		. '},true);</script>'
	;
	private const RESET_STYLE = '<style>.b24-form button,.b24-form label{'
		. 'text-transform:none;letter-spacing:normal;word-spacing:normal;text-shadow:none;'
		. '}</style>'
	;

	/**
	 * The click on the submit button of a form of the CRM is cancelled, so the native submission never
	 * starts, with or without allow-forms, and the submit event goes from the script alone: the form gets
	 * it once. Enter in a field presses the submit button, as the browser does; a textarea keeps Enter.
	 * A click or a key cancelled by the CRM itself is left alone.
	 */
	private const SUBMIT_BRIDGE = '<script>(function(){'
		. 'var skipTypes={button:1,submit:1,reset:1,image:1,file:1};'
		. 'function crmForm(element){'
		. 'var form=element&&element.form;'
		. 'return form&&form.closest&&form.closest(\'.b24-form\')?form:null;'
		. '}'
		. 'document.addEventListener(\'click\',function(event){'
		. 'var target=event.target;'
		. 'var button=!event.defaultPrevented&&target&&target.closest?target.closest(\'button,input\'):null;'
		. 'var form=button&&button.type===\'submit\'?crmForm(button):null;'
		. 'if(!form){return;}'
		. 'event.preventDefault();'
		. 'form.dispatchEvent(new Event(\'submit\',{bubbles:true,cancelable:true}));'
		. '});'
		. 'document.addEventListener(\'keydown\',function(event){'
		. 'var field=event.target;'
		. 'if(event.defaultPrevented||event.isComposing||event.key!==\'Enter\'||!field'
		. '||field.tagName!==\'INPUT\'||skipTypes[field.type]){return;}'
		. 'var form=crmForm(field);'
		. 'var button=form?form.querySelector(\'button[type=submit],input[type=submit]\'):null;'
		. 'if(!button||button.disabled){return;}'
		. 'event.preventDefault();'
		. 'button.click();'
		. '});'
		. '})();</script>'
	;
	private const LOADER_MODE_INLINE = 'inline';
	private const LOADER_MODE_CLICK = 'click';
	private const INSTANCE_ID_PREFIX = 'bx-markup-form-';

	// the loader address is refreshed this often, in ms: in a draft at once, as in the classic editor
	private const CACHE_WINDOW_DRAFT = 100;
	private const CACHE_WINDOW_PUBLIC = 180000;

	private FormMarkupScanner $scanner;

	/** @var \Closure(int): ?array */
	private \Closure $readForm;

	/**
	 * @param callable(int): ?array|null $readForm Reader of a CRM form by id: ID, SECURITY_CODE, URL.
	 */
	public function __construct(?FormMarkupScanner $scanner = null, ?callable $readForm = null)
	{
		$this->scanner = $scanner ?? new FormMarkupScanner();
		$this->readForm = $readForm
			? \Closure::fromCallable($readForm)
			: static fn(int $formId): array => Subtype\Form::getFormById($formId, true);
	}

	/**
	 * Returns the entry document with the loaders of the bound forms and the unbound markers hidden.
	 */
	public function render(string $entryHtml, Manifest $manifest, bool $isPublic): string
	{
		$markers = $this->scanner->findMarkers($entryHtml);
		if (!$markers)
		{
			return $entryHtml;
		}

		$insertions = [];
		$hiddenTags = [];
		$forms = [];
		$instances = [];
		$instanceIdsByListener = [];
		$hasLinkGuard = false;
		$firstBoundTagStart = null;
		foreach ($markers as $marker)
		{
			$binding = $manifest->getFormBinding($marker['token']);
			$formId = (int)($binding['formId'] ?? 0);
			if ($formId > 0 && !array_key_exists($formId, $forms))
			{
				$forms[$formId] = $this->readLoadableForm($formId);
			}
			$form = $forms[$formId] ?? null;
			if ($form === null)
			{
				$hiddenTags[$marker['tagStart']] = true;

				continue;
			}
			$firstBoundTagStart ??= $marker['tagStart'];

			$design = (array)$binding['design'];
			$listenerKey = null;
			$instanceId = null;
			if ($design)
			{
				$listenerKey = $formId . ':' . md5(Json::encode($design));
				$instanceId = self::INSTANCE_ID_PREFIX . count($instances);
				$instanceIdsByListener[$listenerKey][] = $instanceId;
			}
			$instances[] = [
				'marker' => $marker,
				'formId' => $formId,
				'form' => $form,
				'design' => $design,
				'listenerKey' => $listenerKey,
				'instanceId' => $instanceId,
			];
		}

		$builtListeners = [];
		foreach ($instances as $instance)
		{
			$marker = $instance['marker'];
			$listenerKey = $instance['listenerKey'];
			$listener = '';
			if ($listenerKey !== null && !isset($builtListeners[$listenerKey]))
			{
				$builtListeners[$listenerKey] = true;
				$listener = $this->buildDesignListener(
					$instance['formId'],
					$instanceIdsByListener[$listenerKey],
					$instance['design'],
				);
			}

			if ($marker['mode'] === FormMarkupScanner::MODE_INLINE)
			{
				$insertions[$marker['tagEnd']][]
					= $listener
					. $this->buildLoader(self::LOADER_MODE_INLINE, $instance, $isPublic);
			}
			else
			{
				$guard = '';
				if (!$hasLinkGuard && $this->isLinkTag($entryHtml, $marker['tagStart']))
				{
					$hasLinkGuard = true;
					$guard = self::LINK_TRIGGER_GUARD;
				}
				$insertions[$marker['tagStart']][]
					= $guard
					. $listener
					. $this->buildLoader(self::LOADER_MODE_CLICK, $instance, $isPublic);
			}
		}

		$headEnd = $this->scanner->findEndTag($entryHtml, 'head');
		if ($firstBoundTagStart !== null)
		{
			$pageOffset = $headEnd ?? $firstBoundTagStart;
			$insertions[$pageOffset] = array_merge(
				[self::RESET_STYLE, self::SUBMIT_BRIDGE],
				$insertions[$pageOffset] ?? [],
			);
		}

		if ($hiddenTags)
		{
			foreach (array_keys($hiddenTags) as $tagStart)
			{
				$insertions[$this->findTagNameEnd($entryHtml, $tagStart)][] = ' ' . self::ATTRIBUTE_OFF;
			}
			$styleOffset = $headEnd ?? min(array_keys($hiddenTags));
			$insertions[$styleOffset] = array_merge([self::OFF_STYLE], $insertions[$styleOffset] ?? []);
		}

		return $this->applyInsertions($entryHtml, $insertions);
	}

	/**
	 * The form and its loader address, null when there is no form or no loader.
	 * @return array{sec: string, url: string}|null
	 */
	private function readLoadableForm(int $formId): ?array
	{
		$form = ($this->readForm)($formId);
		$url = $this->normalizeLoaderUrl((string)($form['URL'] ?? ''));
		if (!$form || $url === '')
		{
			return null;
		}

		return [
			'sec' => (string)($form['SECURITY_CODE'] ?? ''),
			'url' => $url,
		];
	}

	/**
	 * A loader address built out of a request over https (an agent, the console) comes with http:
	 * in the frame of an https page it would be blocked as mixed content. Without the scheme the address
	 * follows the page, the same as the links to the local files of the classic blocks.
	 */
	private function normalizeLoaderUrl(string $url): string
	{
		return stripos($url, 'http://') === 0 ? substr($url, 5) : $url;
	}

	/**
	 * The design is merged into the options of the form before its start, in any mode. The event carries
	 * the same form id for every instance of the form, so the listener takes the instances of its design
	 * by data.id: the CRM puts there data-b24-id of the loader. The id of the form lies in data.identification.
	 * @param list<string> $instanceIds
	 */
	private function buildDesignListener(int $formId, array $instanceIds, array $design): string
	{
		return '<script>(function(){'
			. 'var formId=' . $formId . ',ids=' . Json::encode($instanceIds) . ',design=' . Json::encode($design) . ';'
			. 'window.addEventListener(\'b24:form:init:before\',function(event){'
			. 'var data=event.detail&&event.detail.data;'
			. 'if(!data||ids.indexOf(data.id)===-1||!data.identification'
			. '||Number(data.identification.id)!==formId){return;}'
			. 'data.design=Object.assign({},data.design||{},design);'
			. '});'
			. '})();</script>';
	}

	/**
	 * The loader tag of Crm\UI\Webpack\Form, as the classic blocks put it.
	 * @param array{formId: int, form: array{sec: string, url: string}, instanceId: ?string} $instance
	 */
	private function buildLoader(string $mode, array $instance, bool $isPublic): string
	{
		$cacheWindow = $isPublic ? self::CACHE_WINDOW_PUBLIC : self::CACHE_WINDOW_DRAFT;
		$form = $instance['form'];
		$instanceAttribute = $instance['instanceId'] !== null
			? ' data-b24-id="' . htmlspecialcharsbx($instance['instanceId']) . '"'
			: ''
		;

		return '<script data-b24-form="'
			. htmlspecialcharsbx($mode . '/' . $instance['formId'] . '/' . $form['sec']) . '"'
			. ' data-skip-moving="true"' . $instanceAttribute . '>'
			. '(function(w,d,u){'
			. 'var s=d.createElement(\'script\');s.async=true;s.src=u+\'?\'+(Date.now()/' . $cacheWindow . '|0);'
			. 'var h=d.getElementsByTagName(\'script\')[0];h.parentNode.insertBefore(s,h);'
			. '})(window,document,' . Json::encode($form['url'], Json::DEFAULT_OPTIONS | JSON_UNESCAPED_SLASHES) . ');'
			. '</script>';
	}

	private function isLinkTag(string $html, int $tagStart): bool
	{
		preg_match('#\G<([a-zA-Z][^\s/>]*)#', $html, $match, 0, $tagStart);

		return in_array(strtolower($match[1] ?? ''), self::LINK_TAGS, true);
	}

	private function findTagNameEnd(string $html, int $tagStart): int
	{
		preg_match('#\G<[a-zA-Z][^\s/>]*#', $html, $match, 0, $tagStart);

		return $tagStart + strlen($match[0] ?? '<');
	}

	/**
	 * @param array<int, list<string>> $insertions Fragments by offset, in the order they go in.
	 */
	private function applyInsertions(string $html, array $insertions): string
	{
		krsort($insertions);
		foreach ($insertions as $offset => $fragments)
		{
			$html = substr_replace($html, implode('', $fragments), $offset, 0);
		}

		return $html;
	}
}
