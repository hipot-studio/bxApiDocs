<?php

namespace Bitrix\Landing;

use Bitrix\Main\Loader;
use Bitrix\Security\Filter;
use Bitrix\Main\Web\IpAddress;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;
use Bitrix\Main\ArgumentException;

class Sanitizer
{
	public const AVAILABLE_TEXT_FILTERS = [
		'sanitize' => 'sanitize',
		'reverseSanitize' => 'reverseSanitize',
		'noEmptyText' => 'noEmptyText',
	];
	private const DEFAULT_TEXT_FILTERS = [
		'sanitize' => 'sanitize',
		'reverseSanitize' => 'reverseSanitize',
	];
	/**
	 * Url schemes the link control (install/js/.../ui/field/linkurl) can emit and
	 * that our url sinks are therefore allowed to store: browser-safe schemes plus
	 * Landing internal pseudo-schemes (page:/block:/form:/product:/file:/user:/help:,
	 * see linkurl.js typeHrefs + matchers). Keep in sync with that control - a
	 * scheme it can produce but that is missing here gets silently blanked on save.
	 * Script-executing schemes (javascript/vbscript/data/blob) are intentionally
	 * absent: everything not listed here is denied (default-deny).
	 */
	private const ALLOWED_URL_SCHEMES = [
		// browser-safe
		'http',
		'https',
		'mailto',
		'tel',
		'sms',
		'skype',
		// Landing internal pseudo-schemes
		'page',
		'block',
		'form',
		'product',
		'file',
		'user',
		'help',
	];

	/**
	 * Allowed schemes that are valid ONLY in the internal marker form `scheme:#marker`,
	 * never as a raw url. `file` collides with the browser `file://` scheme, so it is
	 * accepted only as the diskFile marker (`file:#diskFile...`) - a raw `file:///...` is
	 * not a real target and stays denied. `help` is likewise only ever `help:#helpdesk=...`
	 * / `help:#slider=...` (the render resolver, landing.php parseLocalUrl, expands those
	 * into `javascript:BX.Helper...`); a non-marker `help:` must not pass.
	 */
	private const MARKER_ONLY_URL_SCHEMES = [
		'file',
		'help',
	];

	private array $sanitizers = [];
	private array $filters = self::DEFAULT_TEXT_FILTERS;

	/**
	 * Disable some operations for text sanitize.
	 * See AVAILABLE_TEXT_FILTERS
	 * @param string $filter
	 * @return void
	 */
	public function disableTextFilter(string $filter): void
	{
		if (in_array($filter, self::AVAILABLE_TEXT_FILTERS, true))
		{
			unset($this->filters[$filter]);
		}
	}

	public function enableTextFilter(string $filter): void
	{
		if (in_array($filter, self::AVAILABLE_TEXT_FILTERS, true))
		{
			$this->filters[$filter] = self::AVAILABLE_TEXT_FILTERS[$filter];
		}
	}

	/**
	 * Check is filter enable
	 * @param string $filter
	 * @return bool
	 */
	private function checkFilter(string $filter): bool
	{
		return in_array($filter, $this->filters, true);
	}

	/**
	 * Sanitize bad value.
	 * @param string|array $text Bad value.
	 * @param bool &$bad Return true, if value is bad.
	 * @param string $splitter Splitter for bad content.
	 * @return string|array Good value.
	 */
	public function sanitizeText(string|array $text, bool &$bad = false, string $splitter = ' '): string|array
	{
		if (is_array($text))
		{
			return array_map(function ($val) use (&$bad, $splitter)
			{
				return $this->sanitizeText($val, $bad, $splitter);
			}, $text);
		}

		if (self::containsUnneutralizedPhpOpenTag($text))
		{
			$bad = true;
		}

		$needReverse = $this->checkFilter(self::AVAILABLE_TEXT_FILTERS['reverseSanitize']);
		if ($needReverse)
		{
			// Restoring tags to prevent them from appearing after finish reverse
			$text = $this->reverseSanitizeText($text);
		}

		$needSanitize = $this->checkFilter(self::AVAILABLE_TEXT_FILTERS['sanitize']);
		$sanitizer = $this->getSanitizer($splitter);
		if ($needSanitize && $sanitizer !== null)
		{
			if ($sanitizer->process($text))
			{
				$bad = true;
				$text = $sanitizer->getFilteredValue();
			}
		}

		if ($needReverse)
		{
			$text = $this->reverseSanitizeText($text);
		}

		if (
			$text === ''
			&& $this->checkFilter(self::AVAILABLE_TEXT_FILTERS['noEmptyText'])
		)
		{
			$text = ' ';
		}

		return $text;
	}

	/**
	 * Raw PHP open tag (<? …), not landing-neutralized form (< ? …).
	 */
	public static function containsUnneutralizedPhpOpenTag(string $content): bool
	{
		return str_contains($content, '<?');
	}

	/**
	 * Validates a physical block CODE (format "namespace:code" or just "code") before it is
	 * used to build an include path. Not for theme TPL_CODE — those go through the closed
	 * LocalTemplates whitelist instead.
	 */
	public static function isValidBlockCode(string $code): bool
	{
		return (bool)preg_match('/^[A-Za-z0-9_.:-]+$/', $code);
	}

	/**
	 * Replaces some specific for landing substitutions back after sanitize
	 */
	private function reverseSanitizeText(string $text): string
	{
		$text = str_replace(
			[' bxstyle="', '<sv g', '<sv g ', '<?', '?>', '<fo rm', '<fo rm '],
			[' style="', '<svg', '<svg ', '< ?', '? >', '<form', '<form '],
			$text
		);

		return self::restoreSplitStyleTag($text);
	}

	/**
	 * Puts the opening <style> of a block back together.
	 *
	 * The auditor splits that tag the same way it splits the ones above, while the closing one is
	 * left alone, so without this the css of a block stops being a style and is printed on the page
	 * as visible text. What the filter did inside the tag is not undone: `expression(`, a
	 * `javascript:` url and `-moz-binding` stay split, and the block keeps raising the flag.
	 *
	 * Unlike the plain replacements above this one reads the tag whatever its case - the auditor
	 * splits `<STYLE>` too - and only where the auditor cuts at all: a single space where the tag
	 * was cut, and `<styles` is none of its business, `(?!\w)` of its own pattern.
	 */
	private static function restoreSplitStyleTag(string $text): string
	{
		// a failure of the engine itself returns null, and the text is then left as it is
		return preg_replace('/(<st) (yle)(?!\w)/i', '$1$2', $text) ?? $text;
	}

	private function getSanitizer(string $splitter): ?Filter\Auditor\Xss
	{
		$sanitizer = $this->sanitizers[$splitter] ?? null;
		if (
			$sanitizer === null
			&& Loader::includeModule('security')
		)
		{
			$sanitizer = new Filter\Auditor\Xss($splitter);
			$this->sanitizers[$splitter] = $sanitizer;
		}

		return $sanitizer;
	}

	public function sanitizeHrefTarget(string $target): string
	{
		$allowable = [
			'_self',
			'_blank',
			'_popup',
		];
		$default = '_self';
		$target = mb_strtolower(trim($target));

		return in_array($target, $allowable, true) ? $target : $default;
	}

	/**
	 * Normalizes a pseudo-url `enabled` flag to a real boolean. The client stores it
	 * as a json boolean; legacy data may carry the string "false". Mirrors the JS
	 * Text.toBoolean semantics used by the wiki consumer.
	 * @param mixed $value Raw flag value.
	 * @return bool
	 */
	private function normalizeBoolFlag(mixed $value): bool
	{
		if (is_bool($value))
		{
			return $value;
		}
		if (is_string($value))
		{
			return !in_array(mb_strtolower(trim($value)), ['', '0', 'n', 'no', 'false', 'off'], true);
		}

		return (bool)$value;
	}

	/**
	 * Sanitizes a single real url written to an href/action attribute (Node\Link and
	 * other real-href sinks). The scheme allow-list is the primary guard here: the text
	 * auditor is a denylist and misses e.g. a leading `data:`. Returns an empty string
	 * when the scheme is not allow-listed, otherwise the text-sanitized url.
	 * @param string $href Url value.
	 * @return string
	 */
	public function sanitizeHref(string $href): string
	{
		if (self::hasDisallowedScheme($href))
		{
			return '';
		}

		$clean = $this->sanitizeText($href);

		return is_array($clean) ? '' : $clean;
	}

	/**
	 * Sanitizes a pseudo-url value (json string or already decoded array) used
	 * in data-pseudo-url attributes. Keeps only the allowed keys, drops href
	 * values whose scheme is not allow-listed. Returns a safe json string or an empty
	 * string if there is nothing to save.
	 * @param string|array $url Pseudo-url value: json string or array.
	 * @return string
	 */
	public function sanitizePseudoUrl(string|array $url): string
	{
		if (is_string($url))
		{
			try
			{
				// Json::decode materializes \uXXXX escapes, so hidden schemes become detectable
				$url = Json::decode($url);
			}
			catch (ArgumentException)
			{
				return '';
			}
			if (!is_array($url))
			{
				return '';
			}
		}

		$result = [];

		if (isset($url['text']))
		{
			$text = is_scalar($url['text']) ? trim((string)$url['text']) : '';
			$result['text'] = $this->sanitizeText($text);
		}

		if (isset($url['href']))
		{
			// sanitizeHref checks the scheme allow-list before the text auditor: the
			// auditor can split a dangerous scheme (javascript: -> jav * ascript:) and
			// hide it from the prefix check, so the scheme guard must run first.
			$href = is_scalar($url['href']) ? trim((string)$url['href']) : '';
			$result['href'] = $this->sanitizeHref($href);
		}

		if (isset($url['query']))
		{
			$query = is_scalar($url['query']) ? trim((string)$url['query']) : '';
			$result['query'] = $this->sanitizeText($query);
		}

		if (isset($url['target']))
		{
			$target = is_scalar($url['target']) ? (string)$url['target'] : '';
			$result['target'] = $this->sanitizeHrefTarget($target);
		}

		if (isset($url['enabled']))
		{
			// enabled is a json boolean on the client (link control -> public.js
			// `&& linkOptions.enabled`, node/img `url.enabled !== false`, wiki
			// Text.toBoolean). Keep it a boolean: coercing to 'Y'/'N' breaks both
			// readings - the string 'N' is truthy (a disabled link turns on) yet
			// Text.toBoolean('N') is false (an enabled link turns off).
			$result['enabled'] = $this->normalizeBoolFlag($url['enabled']);
		}

		if (!$result)
		{
			return '';
		}

		return Json::encode($result);
	}

	/**
	 * Checks whether the url carries a scheme that is NOT in the allow-list of safe schemes.
	 * Scheme-less urls (relative paths, #anchors, query strings) are allowed. Default-deny:
	 * anything with an unknown scheme is treated as unsafe.
	 * Static and kernel-free on purpose: it is the url policy of the module, shared with sinks
	 * that must not pull the text auditor (AI\SiteBuilder\Html\AiBlockHtmlSanitizer).
	 * @param string $url Url value.
	 * @return bool
	 */
	public static function hasDisallowedScheme(string $url): bool
	{
		// browsers ignore whitespace and control characters within the scheme part
		$normalized = preg_replace('/[\s\x00-\x1F\x7F]+/u', '', $url);
		if ($normalized === null)
		{
			// not a valid utf-8 string: fall back to the ascii-only pattern
			$normalized = (string)preg_replace('/[\s\x00-\x1F\x7F]+/', '', $url);
		}

		// a scheme is [a-z][a-z0-9+.-]* followed by ':' at the very start of the url
		if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $normalized, $matches))
		{
			$scheme = mb_strtolower($matches[1]);
			if (!in_array($scheme, self::ALLOWED_URL_SCHEMES, true))
			{
				return true;
			}

			// marker-only schemes are valid solely as scheme:#marker (see const doc)
			if (
				in_array($scheme, self::MARKER_ONLY_URL_SCHEMES, true)
				&& !str_starts_with(substr($normalized, strlen($matches[0])), '#')
			)
			{
				return true;
			}

			return false;
		}

		// no scheme (relative path, #anchor, ?query) - allowed
		return false;
	}

	/**
	 * Allow-list of URL schemes for user-supplied href output.
	 * Relative refs (no scheme, "/", "#", "?") pass through; http/https are the only
	 * allowed absolute schemes; javascript:, data:, vbscript:, file: and any other
	 * scheme are blocked (returns '').
	 *
	 * Protocol-relative refs ("//host" and their backslash forms "\\host", "/\host")
	 * are rejected by syntax: they carry no scheme, so the allow-list below would
	 * treat them as relative refs, which they are not. This is not a guard against
	 * navigating to a foreign host - "https://evil.com" is allowed here on purpose.
	 * It only keeps the allow-list deciding over refs that are either truly relative
	 * or carry an explicit scheme.
	 *
	 * Both checks run on a normalized value: it is trimmed, and every C0 control character
	 * and space is stripped anywhere inside it, because browsers drop those characters when
	 * resolving a URL - without stripping, "java\tscript:alert(1)" or "/\x00/evil.com" would
	 * slip past the checks below. So the return value is not necessarily the input string:
	 * an allowed href holding an unencoded space comes back without it instead of being
	 * rejected. A caller that must react to a modified value has to compare it itself.
	 *
	 * The server uses an allow-list (http/https plus relative refs) and is the
	 * stricter guard. The client-side JS guards in
	 * install/js/landing/node/link/src/link.js and ui compact_editor_panel.js
	 * use a deny-list of dangerous schemes (javascript/data/vbscript/file), so the
	 * two sets are intentionally not identical. When a new dangerous scheme is added
	 * to the client deny-list, verify the server allow-list here still does NOT let it through.
	 */
	public static function sanitizeHrefScheme(string $href): string
	{
		$value = trim($href);
		$value = preg_replace('/[\x00-\x20]/', '', $value);
		if ($value === '')
		{
			return '';
		}

		// browsers normalize "\" to "/", so "/\host" navigates like "//host"
		if (str_starts_with(str_replace('\\', '/', $value), '//'))
		{
			return '';
		}

		if (!preg_match('/^([a-z][a-z0-9+.-]*):/i', $value, $matches))
		{
			return $value;
		}

		$scheme = strtolower($matches[1]);

		return in_array($scheme, ['http', 'https'], true) ? $value : '';
	}

	/**
	 * Validates the `handler` url of a REST widget (subtype_params.handler) before the portal
	 * requests it or stores it. Returns the url unchanged, or an empty string when the value
	 * must not be used. Nothing is repaired here: a value that would pass only after
	 * normalization is rejected instead, so an accepted url is always byte for byte the one the
	 * caller already holds and no caller can end up requesting or storing something other than
	 * what was checked. A handler carrying a space or a control character is broken anyway.
	 *
	 * An absolute http(s) url with a non-empty host is required. sanitizeHrefScheme() alone is
	 * not enough: it treats a scheme-less value ("evil.host/path", "/path") as a relative ref
	 * and returns it untouched, which is fine for an href but is never a valid request target.
	 *
	 * An address literal in the host is rejected when it points at a loopback, private,
	 * link-local or otherwise reserved address, and so is a host that is an address literal in
	 * a form filter_var refuses to read - see isUnsafeAddressHost(). A host NAME resolving to
	 * such an address is deliberately NOT caught here: dns is checked at request time by
	 * HttpClient::setPrivateIp(false), which re-checks every redirect hop and pins the resolved
	 * ip. Resolving dns here would only add a check that the record can win by changing right
	 * after it passed.
	 * @param string $url Handler url.
	 * @param bool $requireHttps Reject plain http (widget registration does this on handler change).
	 * @return string The url as given, or an empty string when it must not be used.
	 */
	public static function sanitizeWidgetHandlerUrl(string $url, bool $requireHttps = false): string
	{
		$value = self::sanitizeHrefScheme($url);
		if ($value === '' || $value !== $url)
		{
			return '';
		}

		$uri = new Uri($value);

		$allowedSchemes = $requireHttps ? ['https'] : ['http', 'https'];
		if (!in_array($uri->getScheme(), $allowedSchemes, true))
		{
			return '';
		}

		// a space or an invisible character inside the host hides the real address from every
		// reader of the raw value; no host name needs one, an internationalized one included.
		// preg_match returns false on a broken utf-8 sequence, which is refused as well
		$host = $uri->getHost();
		if ($host === '' || preg_match('/[\p{Z}\p{C}]/u', $host) !== 0)
		{
			return '';
		}

		if (self::isUnsafeAddressHost($host))
		{
			return '';
		}

		return $value;
	}

	/**
	 * Tells whether the host is an address literal that outbound requests must not target.
	 * Two cases are refused: an address in a private, loopback, link-local or otherwise
	 * reserved range, and an address written in a form filter_var does not read - the short,
	 * decimal, octal and hex notations of inet_aton ("127.1", "2130706433", "0177.0.0.1",
	 * "0x7f.0.0.1"), which resolvers do read. A host NAME is not resolved here - see
	 * sanitizeWidgetHandlerUrl().
	 */
	private static function isUnsafeAddressHost(string $host): bool
	{
		// Uri keeps an ipv6 literal in its bracket form, and a trailing dot is the dns root
		$address = rtrim(trim($host, '[]'), '.');
		if (filter_var($address, FILTER_VALIDATE_IP) !== false)
		{
			return (new IpAddress($address))->isPrivate();
		}

		// no registrable tld is made of digits only, so a numeric rightmost label means the
		// host is one of the address notations above rather than a name
		$labels = explode('.', $address);
		$rightmostLabel = (string)end($labels);

		// ctype is not built into the php of the cloud, pcre always is
		return preg_match('/^[0-9]+$/D', $rightmostLabel) === 1;
	}

	/**
	 * Prepares a url for the INSIDE of the css url() function - the `X` of
	 * `style="background-image: url(X)"` or of `element.style.backgroundImage = 'url(' + X + ')'`.
	 * It is not a sanitizer for a whole style attribute and not a replacement for html escaping:
	 *
	 * - the caller still has to run htmlspecialcharsbx over the result, because the value goes
	 *   into an attribute;
	 * - html escaping alone does NOT close this sink, because the browser decodes entities
	 *   before the css parser reads the value. `&quot;` is a quote again, `&#41;` is a bracket
	 *   again by the time css is parsed, so a value like `/a.jpg);position:fixed;top:0` ends
	 *   url() and gives the element own declarations while never leaving the attribute.
	 *
	 * Two steps: the scheme policy of hasDisallowedScheme (a url is a url, same allow-list as
	 * the href sinks; a denied scheme returns an empty string, and an empty url() is both safe
	 * and visible while debugging), then percent-encoding of everything that ends the url token -
	 * brackets, quotes, backslash, comma, semicolon, braces, whitespace and control characters.
	 * Encoding rather than stripping: a space or a bracket is legal in a file name, and the
	 * percent form addresses the same file. Bytes above ascii are left alone, so a cyrillic
	 * path stays readable.
	 *
	 * Protocol-relative refs ("//host/a.jpg") PASS, unlike in sanitizeHrefScheme, and this is
	 * the one place where the two policies differ on purpose. Manager::getUrlFromFile() turns
	 * every local path into exactly that form, and it is what Landing::getPreview() and
	 * Site::getPreview() return for the default picture, so cutting the form here would blank
	 * the cover of every site and page without a preview. It costs nothing in return: an
	 * absolute "https://any.host/x.jpg" is allowed by the same allow-list anyway, so blocking
	 * one spelling of a foreign host guards nothing. Keeping a css url() on the own host is a
	 * separate, stricter policy - it is not what this method does.
	 * @param string $url Url value.
	 * @return string
	 */
	public static function sanitizeCssUrl(string $url): string
	{
		if (self::hasDisallowedScheme($url))
		{
			return '';
		}

		$encoded = preg_replace_callback(
			'/[\x00-\x20\x7F\\\\"\'(),;{}]/',
			static function (array $match): string
			{
				return sprintf('%%%02X', ord($match[0]));
			},
			$url
		);

		return $encoded ?? '';
	}

	public function sanitizeNodeName(string $nodeName): string
	{
		$allowable = [
			'h1',
			'h2',
			'h3',
			'h4',
			'h5',
			'h6',
			'div',
			'p',
			'a',
			'span',
		];
		$default = 'div';
		$nodeName = mb_strtolower(trim($nodeName));

		return in_array($nodeName, $allowable, true) ? $nodeName : $default;
	}
}
