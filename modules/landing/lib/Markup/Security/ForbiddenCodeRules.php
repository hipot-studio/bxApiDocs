<?php

namespace Bitrix\Landing\Markup\Security;

use Bitrix\Landing\Markup\Form\FormMarkupScanner;

/**
 * Static code rules of a markup package and the outcome class of each rule (ADR, section 7).
 *
 * The package runs in a sandboxed iframe with an opaque origin, so most of what looks
 * hostile is already dead there: such rules are `allow` and only reach the generation log.
 * `block` is reserved for the safety of the portal and its user - the editor protocol and
 * requests to portal paths. `repair` is code that breaks the package itself on a published
 * page and the repairer can fix it.
 *
 * Textual analysis: the rules are a net for honest mistakes and straightforward attempts,
 * not a defense against obfuscation - the sandbox is.
 */
class ForbiddenCodeRules
{
	public const OUTCOME_BLOCK = 'block';
	public const OUTCOME_REPAIR = 'repair';
	public const OUTCOME_ALLOW = 'allow';

	public const TARGET_HTML = 'html';
	public const TARGET_JS = 'js';
	public const TARGET_CSS = 'css';
	/** A text asset of the package: js or css, anything but the entry. */
	public const TARGET_ASSET = 'asset';
	public const TARGET_ANY = 'any';

	public const RULE_EDITOR_POST_MESSAGE = 'editor_protocol_post_message';
	public const RULE_PORTAL_REQUEST = 'portal_request';
	public const RULE_SCRIPT_MODULE = 'script_module';
	public const RULE_JS_IMPORT = 'js_import';
	public const RULE_UNGUARDED_STORAGE = 'unguarded_storage';
	public const RULE_HISTORY_PUSH_STATE = 'history_push_state';
	public const RULE_EXTERNAL_SVG_SPRITE = 'external_svg_sprite';
	public const RULE_FOREIGN_FORM = 'foreign_form';
	public const RULE_FORM_MARKER_OUTSIDE_ENTRY = 'form_marker_outside_entry';
	public const RULE_SANDBOX_TOP_NAVIGATION = 'sandbox_top_navigation';
	public const RULE_SANDBOX_PARENT_DOM = 'sandbox_parent_dom';
	public const RULE_SANDBOX_WINDOW_OPEN = 'sandbox_window_open';
	public const RULE_SANDBOX_FORM_SUBMIT = 'sandbox_form_submit';
	public const RULE_SANDBOX_SERVICE_WORKER = 'sandbox_service_worker';
	public const RULE_SANDBOX_DYNAMIC_EVAL = 'sandbox_dynamic_eval';

	/**
	 * A longer address is not decoded: a portal segment anywhere in it makes the content unreadable.
	 */
	private const PORTAL_ADDRESS_LIMIT = 8192;
	/**
	 * Candidate addresses examined before the content counts as unreadable.
	 */
	private const PORTAL_CANDIDATE_LIMIT = 10000;
	private const WEB_SCHEMES = ['http', 'https', 'ws', 'wss'];
	private const QUOTES = ['\'', '"', '`'];
	private const ADDRESS_CHAR = '[^\'"`<>()\x00-\x08\x0b\x0c\x0e-\x20]';
	private const ADDRESS_TERMINATORS = '\'"`<>()'
		. "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0b\x0c\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17"
		. "\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x20"
	;
	private const JS_SINGLE_ESCAPES = [
		'n' => "\n",
		't' => "\t",
		'r' => "\r",
		'b' => "\x08",
		'f' => "\f",
		'v' => "\v",
		'0' => "\0",
		"\n" => '',
		"\r" => '',
		"\r\n" => '',
	];

	/**
	 * @return array<int, array{id: string, pattern: string, target: string, outcome: string}>
	 */
	public static function rules(): array
	{
		return [
			[
				// the only channel the package code can reach the portal page with
				'id' => self::RULE_EDITOR_POST_MESSAGE,
				'pattern' => '#\b(?:parent|top|opener)\s*\.\s*postMessage\s*\([^;]{0,500}?landing\.#s',
				'target' => self::TARGET_ANY,
				'outcome' => self::OUTCOME_BLOCK,
			],
			[
				'id' => self::RULE_PORTAL_REQUEST,
				// candidates only: findFirstPortalRequest() decodes each one and resolves the address
				'pattern' => self::buildPortalCandidatePattern(),
				'target' => self::TARGET_ANY,
				'outcome' => self::OUTCOME_BLOCK,
			],
			[
				// the attribute can only appear in the entry: scripts of the package are files
				'id' => self::RULE_SCRIPT_MODULE,
				'pattern' => '#<script\b[^>]*\btype\s*=\s*[\'"]module[\'"]#i',
				'target' => self::TARGET_HTML,
				'outcome' => self::OUTCOME_REPAIR,
			],
			[
				'id' => self::RULE_JS_IMPORT,
				'pattern' => '#(?<![@\w.$])import\s*\(|(?<![@\w.$])import\s+[\w{*$][^;\n]{0,200}?\bfrom\s*[\'"]'
					. '|(?<![@\w.$])import\s+[\'"]#',
				'target' => self::TARGET_ANY,
				'outcome' => self::OUTCOME_REPAIR,
			],
			[
				// the delivered sandbox shim answers instead of the browser, so an access
				// no longer throws in an opaque origin (ADR, section 7)
				'id' => self::RULE_UNGUARDED_STORAGE,
				'pattern' => '#\b(?:localStorage|sessionStorage|indexedDB|caches)\b|\bdocument\s*\.\s*cookie\b#',
				'target' => self::TARGET_ANY,
				'outcome' => self::OUTCOME_ALLOW,
			],
			[
				// a url change throws a SecurityError; replaceState or a hash works
				'id' => self::RULE_HISTORY_PUSH_STATE,
				'pattern' => '#\bhistory\s*\.\s*pushState\s*\([^;]*?,[^;]*?,#s',
				'target' => self::TARGET_ANY,
				'outcome' => self::OUTCOME_REPAIR,
			],
			[
				'id' => self::RULE_EXTERNAL_SVG_SPRITE,
				'pattern' => '#<use\b[^>]*\b(?:xlink:)?href\s*=\s*[\'"][^\'"\#]*\.svg#i',
				'target' => self::TARGET_ANY,
				'outcome' => self::OUTCOME_REPAIR,
			],
			[
				// a form posts nowhere from the sandbox; the hit is found by the form scanner,
				// the pattern only lets content without a form skip the scan
				'id' => self::RULE_FOREIGN_FORM,
				'pattern' => '#<form\b#i',
				'target' => self::TARGET_HTML,
				'outcome' => self::OUTCOME_REPAIR,
			],
			[
				// the loader is put in place of the markers of the entry only, so a script or a style must not
				// make one (setAttribute, an html string, a dataset assignment); reading a marker and a comment
				// naming it are fine
				'id' => self::RULE_FORM_MARKER_OUTSIDE_ENTRY,
				'pattern' => '#(?<!\[)\bdata-bx-crm-form(?:-popup)?\s*=(?!=)\s*[^\s>]'
					. '|\bsetAttribute\s*\(\s*[\'"`]data-bx-crm-form(?:-popup)?[\'"`]'
					. '|\bdataset\s*\.\s*bxCrmForm(?:Popup)?\s*=(?!=)#',
				'target' => self::TARGET_ASSET,
				'outcome' => self::OUTCOME_REPAIR,
			],
			[
				'id' => self::RULE_SANDBOX_TOP_NAVIGATION,
				'pattern' => '#\b(?:window\s*\.\s*)?(?:top|parent)\s*\.\s*location\b#',
				'target' => self::TARGET_ANY,
				'outcome' => self::OUTCOME_ALLOW,
			],
			[
				'id' => self::RULE_SANDBOX_PARENT_DOM,
				'pattern' => '#\b(?:window\s*\.\s*)?(?:top|parent)\s*\.\s*document\b|\bwindow\s*\.\s*frameElement\b#',
				'target' => self::TARGET_ANY,
				'outcome' => self::OUTCOME_ALLOW,
			],
			[
				'id' => self::RULE_SANDBOX_WINDOW_OPEN,
				'pattern' => '#\bwindow\s*\.\s*open\s*\(#',
				'target' => self::TARGET_ANY,
				'outcome' => self::OUTCOME_ALLOW,
			],
			[
				'id' => self::RULE_SANDBOX_FORM_SUBMIT,
				'pattern' => '#\.\s*submit\s*\(\s*\)#',
				'target' => self::TARGET_ANY,
				'outcome' => self::OUTCOME_ALLOW,
			],
			[
				'id' => self::RULE_SANDBOX_SERVICE_WORKER,
				'pattern' => '#\bnavigator\s*\.\s*serviceWorker\b#',
				'target' => self::TARGET_ANY,
				'outcome' => self::OUTCOME_ALLOW,
			],
			[
				'id' => self::RULE_SANDBOX_DYNAMIC_EVAL,
				'pattern' => '#\beval\s*\(|\bnew\s+Function\s*\(#',
				'target' => self::TARGET_ANY,
				'outcome' => self::OUTCOME_ALLOW,
			],
		];
	}


	/**
	 * One hit per rule that matched the content, in the order of the registry.
	 * A block rule whose pattern fails on PCRE limits counts as matched with no line:
	 * the content the rule could not read is not let through.
	 * Only the first match of a rule is looked for, so the memory does not grow with the
	 * number of matches in a hostile file.
	 * @param array<int, string> $portalHosts hosts the portal answers on
	 * @return array<int, array{id: string, outcome: string, line: int|null}>
	 */
	public static function matchAll(string $content, string $target, array $portalHosts = []): array
	{
		$hits = [];
		$hosts = self::normalizeHosts($portalHosts);
		foreach (self::rules() as $rule)
		{
			if (!self::appliesTo($rule['target'], $target))
			{
				continue;
			}

			$offset = match ($rule['id'])
			{
				self::RULE_UNGUARDED_STORAGE => self::findFirstUnguarded($content, $rule['pattern']),
				self::RULE_PORTAL_REQUEST => self::findFirstPortalRequest(
					$content,
					$rule['pattern'],
					$hosts,
					$target !== self::TARGET_JS
				),
				self::RULE_FOREIGN_FORM => self::findFirstForeignForm($content, $rule['pattern']),
				default => self::findFirst($content, $rule['pattern']),
			};
			if ($offset === false)
			{
				if ($rule['outcome'] === self::OUTCOME_BLOCK)
				{
					$hits[] = ['id' => $rule['id'], 'outcome' => $rule['outcome'], 'line' => null];
				}
				continue;
			}
			if ($offset === null)
			{
				continue;
			}

			$hits[] = [
				'id' => $rule['id'],
				'outcome' => $rule['outcome'],
				'line' => substr_count($content, "\n", 0, $offset) + 1,
			];
		}

		return $hits;
	}

	private static function appliesTo(string $ruleTarget, string $target): bool
	{
		return match ($ruleTarget)
		{
			self::TARGET_ANY => true,
			self::TARGET_ASSET => $target !== self::TARGET_HTML,
			default => $ruleTarget === $target,
		};
	}

	/**
	 * A quoted string or a css url with a `rest` or `bitrix` segment in its address or one longer than
	 * the limit; the quote and the head up to the first character that ends an unquoted address are captured.
	 * Inside quotes the url parser keeps brackets, spaces and controls, so the segment is looked for up to
	 * the same quote.
	 */
	private static function buildPortalCandidatePattern(): string
	{
		$openers = [];
		foreach (self::QUOTES as $quote)
		{
			$openers[] = '(' . $quote . ')(?=' . self::buildPortalLookahead('[^' . $quote . ']') . ')[\x00-\x20]*+';
		}
		$openers[] = '\burl\(()\s*+[\x00-\x20]*+(?=' . self::buildPortalLookahead(self::ADDRESS_CHAR) . ')';

		return '~(?|' . implode('|', $openers) . ')'
			. '(' . self::ADDRESS_CHAR . '{0,' . self::PORTAL_ADDRESS_LIMIT . '}+)~i'
		;
	}

	private static function buildPortalLookahead(string $char): string
	{
		[$boundary, $segment] = self::buildPortalSegmentParts();
		$limit = self::PORTAL_ADDRESS_LIMIT;

		return '(?:' . $char . '{0,' . $limit . '}?' . $boundary . ')?' . $segment . '|' . $char . '{' . $limit . '}';
	}

	/**
	 * A `rest` or `bitrix` segment anywhere in a whole address.
	 */
	private static function buildPortalSegmentPattern(): string
	{
		[$boundary, $segment] = self::buildPortalSegmentParts();

		return '~(?:^|' . $boundary . ')' . $segment . '~i';
	}

	/**
	 * The start of a segment and a `rest` or `bitrix` segment in any encoding the browser or the web
	 * server undoes: percent, js and html escapes, the characters the url parser drops.
	 * @return array{0: string, 1: string}
	 */
	private static function buildPortalSegmentParts(): array
	{
		$ignorable = '(?:[\t\n\r]|\\\\[tnr]|&(?:tab|newline);|&#(?:0*(?:9|1[03])(?!\d)|x0*[9ad](?![\da-f]));?)*+';
		// js hex and octal, css hex escapes
		$separator = '(?:[/\\\\]|\\\\(?:(?:x|u00|u\{0*)(?:2f|5c)\}?|0{0,4}(?:2f|5c)(?:\r\n|[ \t\n\r\f])?|0?57|134)'
			. '|%(?:2f|5c)|&b?sol;|&#(?:0*(?:47|92)|x0*(?:2f|5c));?)'
		;
		$keyword = '(?:' . self::buildEncodedWordPattern('rest', $ignorable)
			. '|' . self::buildEncodedWordPattern('bitrix', $ignorable) . ')'
		;

		return [
			'(?:' . $separator . '|:)',
			$ignorable . $keyword . $ignorable . $separator,
		];
	}

	private static function buildEncodedWordPattern(string $word, string $ignorable): string
	{
		$letters = [];
		foreach (str_split($word) as $letter)
		{
			$lower = ord($letter);
			$upper = ord(strtoupper($letter));
			$letters[] = sprintf(
				'(?:%1$s|%%%2$s|\\\\(?:%1$s|x%2$s|u00%2$s|u\{0*%2$s\}|0{0,4}%2$s(?:\r\n|[ \t\n\r\f])?|%5$s)'
					. '|&#(?:0*(?:%3$d|%4$d)|x0*%2$s);?)',
				$letter,
				sprintf('(?:%x|%x)', $lower, $upper),
				$lower,
				$upper,
				sprintf('(?:%o|%o)', $lower, $upper)
			);
		}

		return implode($ignorable, $letters);
	}

	/**
	 * Any address that reaches portal paths: a path from the root, a relative path whose first
	 * segment is a portal one (the package resolves it against the root of the site, which shares
	 * the portal host) and an absolute or protocol-relative address of a portal host with a portal
	 * segment anywhere after the host.
	 * A hostile file with more candidates than the limit counts as unreadable.
	 * @param array<string, true> $hosts normalized hosts of the portal
	 * @param bool $cssEscapes the content may hold css, whose escapes differ from the js ones
	 * @return int|false|null same as findFirst()
	 */
	private static function findFirstPortalRequest(
		string $content,
		string $pattern,
		array $hosts,
		bool $cssEscapes
	): int|false|null
	{
		$offset = 0;
		for ($candidate = 0; $candidate < self::PORTAL_CANDIDATE_LIMIT; $candidate++)
		{
			$found = preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE, $offset);
			if ($found === false || preg_last_error() !== PREG_NO_ERROR)
			{
				return false;
			}
			if ($found === 0)
			{
				return null;
			}

			$verdict = self::checkPortalCandidate($content, $match, $hosts, $cssEscapes);
			if ($verdict !== null)
			{
				return $verdict ? $match[0][1] : false;
			}
			$offset = $match[0][1] + 1;
		}

		return false;
	}

	/**
	 * @param array<int, array{0: string, 1: int}> $match a match of the candidate pattern
	 * @param array<string, true> $hosts
	 * @return bool|null true when the address reaches the portal, false when it is unreadable, null when not
	 */
	private static function checkPortalCandidate(string $content, array $match, array $hosts, bool $cssEscapes): ?bool
	{
		[$address, $start] = $match[2];
		if (strlen($address) < self::PORTAL_ADDRESS_LIMIT)
		{
			if (self::reachesPortal($address, $hosts, $cssEscapes))
			{
				return true;
			}
		}
		elseif (!self::hasForeignScheme($address))
		{
			// a long address is not decoded: a portal segment anywhere in it makes the content unreadable
			$length = strcspn($content, self::ADDRESS_TERMINATORS, $start);
			if (self::hasPortalSegment(substr($content, $start, $length)))
			{
				return false;
			}
		}

		[$quote, $quoteOffset] = $match[1];
		if ($quote === '')
		{
			return null;
		}

		$start = $quoteOffset + 1;
		$length = strcspn($content, $quote . "\r\n", $start);
		if ($length < self::PORTAL_ADDRESS_LIMIT)
		{
			$quoted = substr($content, $start, $length);

			return ltrim($quoted, "\x00..\x20") !== $address && self::reachesPortal($quoted, $hosts, $cssEscapes)
				? true
				: null
			;
		}

		// a long quoted string may be code between two strings: unreadable only as an address of a portal host
		return $hosts
			&& self::reachesPortal(substr($content, $start, self::PORTAL_ADDRESS_LIMIT), $hosts, $cssEscapes, true)
			&& self::hasPortalSegment(substr($content, $start, $length))
			? false
			: null
		;
	}

	private static function hasPortalSegment(string $address): bool
	{
		return preg_match(self::buildPortalSegmentPattern(), $address) !== 0;
	}

	/**
	 * The address is read in every form it may take: as written, with html references and js or css
	 * escapes undone, its parts percent-decoded after the structure is read and percent-decoded as a whole.
	 * @param array<string, true> $hosts
	 * @param bool $hostOnly any address of a portal host counts, whatever its path
	 */
	private static function reachesPortal(
		string $address,
		array $hosts,
		bool $cssEscapes,
		bool $hostOnly = false
	): bool
	{
		$variants = [$address];
		if (str_contains($address, '&'))
		{
			$decoded = self::decodeHtmlReferences($address);
			if ($decoded === null)
			{
				return true;
			}
			$variants[] = $decoded;
		}

		// a backslash is an escape in js and css, but a slash in an html attribute
		foreach ($variants as $variant)
		{
			if (str_contains($variant, '\\'))
			{
				$decoded = [self::decodeJsEscapes($variant)];
				if ($cssEscapes)
				{
					$decoded[] = self::decodeCssEscapes($variant);
				}
				if (in_array(null, $decoded, true))
				{
					return true;
				}
				array_push($variants, ...$decoded);
			}
		}

		foreach (array_unique($variants) as $variant)
		{
			$variant = self::normalizeAddress($variant);
			if (
				self::reachesPortalAsUrl($variant, $hosts, true, $hostOnly)
				|| (
					str_contains($variant, '%')
					&& self::reachesPortalAsUrl(self::decodePercent($variant), $hosts, false, $hostOnly)
				)
			)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * A data, blob or other address that never goes to the web server.
	 */
	private static function hasForeignScheme(string $address): bool
	{
		return preg_match('~^([a-z][a-z\d+.\-]*):~i', $address, $match) === 1
			&& !in_array(strtolower($match[1]), self::WEB_SCHEMES, true)
		;
	}

	/**
	 * @param array<string, true> $hosts
	 * @param bool $decodeParts the host and the path are percent-encoded yet
	 */
	private static function reachesPortalAsUrl(string $address, array $hosts, bool $decodeParts, bool $hostOnly): bool
	{
		if (preg_match('~^([a-z][a-z\d+.\-]*):(.*)$~s', $address, $match))
		{
			if (!in_array($match[1], self::WEB_SCHEMES, true))
			{
				return false;
			}

			// against a page of the same scheme the browser reads the rest as a path of the page host
			return (!$hostOnly && !str_starts_with($match[2], '//') && self::isPortalPath($match[2], $decodeParts))
				|| self::isPortalHostAddress(ltrim($match[2], '/'), $hosts, $decodeParts, $hostOnly)
			;
		}

		if (str_starts_with($address, '//'))
		{
			return self::isPortalHostAddress(ltrim($address, '/'), $hosts, $decodeParts, $hostOnly);
		}

		return !$hostOnly && self::isPortalPath($address, $decodeParts);
	}

	/**
	 * The path resolved against the root of the site starts with a portal segment followed by more.
	 */
	private static function isPortalPath(string $path, bool $decode): bool
	{
		$path = substr($path, 0, self::findDelimiter($path, '?#'));
		if ($decode && str_contains($path, '%'))
		{
			$path = self::decodePercent($path);
		}
		if (!str_contains($path, 'rest') && !str_contains($path, 'bitrix'))
		{
			return false;
		}

		$segments = explode('/', $path);
		$last = count($segments) - 1;
		$pendingParents = 0;
		$first = null;
		for ($index = $last; $index >= 0; $index--)
		{
			$segment = $segments[$index];
			if ($segment === '' || $segment === '.')
			{
				continue;
			}

			if ($segment === '..')
			{
				$pendingParents++;
			}
			elseif ($pendingParents > 0)
			{
				$pendingParents--;
			}
			else
			{
				$first = $index;
			}
		}

		return $first !== null
			&& $first < $last
			&& in_array($segments[$first], ['rest', 'bitrix'], true)
		;
	}

	/**
	 * @param string $address the address after the scheme and the slashes
	 * @param array<string, true> $hosts
	 */
	private static function isPortalHostAddress(string $address, array $hosts, bool $decode, bool $hostOnly): bool
	{
		if (!$hosts)
		{
			return false;
		}

		$authorityLength = self::findDelimiter($address, '/?#');
		$authority = substr($address, 0, $authorityLength);
		$userInfoEnd = strrpos($authority, '@');
		$host = $userInfoEnd === false ? $authority : substr($authority, $userInfoEnd + 1);
		if ($decode && str_contains($host, '%'))
		{
			$host = rawurldecode($host);
		}
		if (!isset($hosts[self::normalizeHost($host)]))
		{
			return false;
		}
		if ($hostOnly)
		{
			return true;
		}

		$path = substr($address, $authorityLength);
		if ($decode && str_contains($path, '%'))
		{
			$path = self::decodePercent($path);
		}

		return str_contains($path, '/rest/') || str_contains($path, '/bitrix/');
	}

	/**
	 * Offset of the first delimiter. The `#` of a character reference does not count: the address may be
	 * read once more with the references undone.
	 */
	private static function findDelimiter(string $address, string $delimiters): int
	{
		$length = strlen($address);
		$offset = strcspn($address, $delimiters);
		while ($offset < $length && $offset > 0 && $address[$offset] === '#' && $address[$offset - 1] === '&')
		{
			$offset += 1 + strcspn($address, $delimiters, $offset + 1);
		}

		return $offset;
	}

	/**
	 * Lowercased, with the characters the url parser drops removed, backslashes as slashes.
	 */
	private static function normalizeAddress(string $address): string
	{
		$address = str_replace(["\t", "\n", "\r"], '', trim($address, "\x00..\x20"));

		return strtolower(str_replace('\\', '/', $address));
	}

	/**
	 * Percent-decoded the way the web server reads the path.
	 */
	private static function decodePercent(string $address): string
	{
		return strtolower(str_replace('\\', '/', rawurldecode($address)));
	}

	private static function decodeHtmlReferences(string $address): ?string
	{
		// a numeric reference works without the semicolon too
		$address = preg_replace_callback(
			'~&#(?:[xX]0*([\da-fA-F]{1,6})|0*(\d{1,7}));?~',
			static fn(array $match): string => self::codePointToChar(
				$match[1] !== null ? (int)hexdec($match[1]) : (int)$match[2]
			),
			$address,
			flags: PREG_UNMATCHED_AS_NULL
		);

		return $address === null ? null : html_entity_decode($address, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	/**
	 * Hex, unicode, legacy octal and single character escapes of a js string.
	 */
	private static function decodeJsEscapes(string $address): ?string
	{
		return preg_replace_callback(
			'~\\\\(?:x([\da-fA-F]{2})|u\{0*([\da-fA-F]{1,6})\}|u([\da-fA-F]{4})'
				. '|([1-3][0-7]{0,2}|0[0-7]{1,2}|[4-7][0-7]?)|(\r\n|[\s\S]))~',
			static fn(array $match): string => match (true)
			{
				$match[4] !== null => self::codePointToChar((int)octdec($match[4])),
				$match[5] !== null => self::JS_SINGLE_ESCAPES[$match[5]] ?? $match[5],
				default => self::codePointToChar((int)hexdec($match[1] ?? $match[2] ?? $match[3])),
			},
			$address,
			flags: PREG_UNMATCHED_AS_NULL
		);
	}

	/**
	 * Hex escapes with the optional whitespace after them, escaped line breaks and characters of css.
	 */
	private static function decodeCssEscapes(string $address): ?string
	{
		return preg_replace_callback(
			'~\\\\(?:([\da-fA-F]{1,6})(?:\r\n|[ \t\n\r\f])?|(\r\n|[\n\r\f])|([\s\S]))~',
			static fn(array $match): string => match (true)
			{
				$match[1] !== null => self::codePointToChar((int)hexdec($match[1])),
				$match[2] !== null => '',
				default => $match[3],
			},
			$address,
			flags: PREG_UNMATCHED_AS_NULL
		);
	}

	private static function codePointToChar(int $codePoint): string
	{
		return $codePoint > 0 && $codePoint <= 0x10FFFF ? (string)mb_chr($codePoint, 'UTF-8') : '';
	}

	/**
	 * @param array<int, string> $hosts
	 * @return array<string, true>
	 */
	private static function normalizeHosts(array $hosts): array
	{
		$normalized = [];
		foreach ($hosts as $host)
		{
			$host = self::normalizeHost((string)$host);
			if ($host !== '')
			{
				$normalized[$host] = true;
			}
		}

		return $normalized;
	}

	/**
	 * Lowercased host without the port and the trailing dot, an international name in punycode.
	 */
	private static function normalizeHost(string $host): string
	{
		$host = mb_strtolower(trim($host));
		if (str_starts_with($host, '['))
		{
			$end = strpos($host, ']');

			return $end === false ? '' : substr($host, 0, $end + 1);
		}

		$host = trim(explode(':', $host)[0], '.');
		if (preg_match('~[^\x00-\x7f]~', $host))
		{
			$host = self::convertHostToAscii($host);
		}

		return $host;
	}

	/**
	 * The name mapped the way the browser does (UTS #46): fullwidth forms, ideographic full stops and
	 * ignorable characters included.
	 */
	private static function convertHostToAscii(string $host): string
	{
		$ascii = function_exists('idn_to_ascii')
			? idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46)
			: false
		;
		if (!is_string($ascii))
		{
			$mapped = preg_replace_callback(
				'~[\x{ff01}-\x{ff5e}]~u',
				static fn(array $match): string => chr(mb_ord($match[0], 'UTF-8') - 0xfee0),
				str_replace(["\u{3002}", "\u{ff0e}", "\u{ff61}"], '.', $host)
			);
			$host = is_string($mapped) ? mb_strtolower($mapped) : $host;
			$ascii = \CBXPunycode::ToASCII($host, $errors);
		}

		return is_string($ascii) ? trim($ascii, '.') : $host;
	}

	/**
	 * @return int|false|null offset of the first match, null when there is none, false on a PCRE failure
	 */
	private static function findFirst(string $content, string $pattern, int $from = 0): int|false|null
	{
		$found = preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE, $from);
		if ($found === false || preg_last_error() !== PREG_NO_ERROR)
		{
			return false;
		}

		return $found === 1 ? $match[0][1] : null;
	}

	/**
	 * The first `<form>` of the markup, outside of scripts, styles and comments.
	 * @return int|false|null same as findFirst()
	 */
	private static function findFirstForeignForm(string $content, string $pattern): int|false|null
	{
		$offset = self::findFirst($content, $pattern);
		if (!is_int($offset))
		{
			return $offset;
		}

		return (new FormMarkupScanner())->findForeignForms($content)[0]['start'] ?? null;
	}

	/**
	 * The first match outside of a `try` block body, one pass over the content: the matches and
	 * the braces are walked side by side. An unclosed block runs to the end of the content,
	 * nested blocks are covered by the outermost one.
	 * @return int|false|null same as findFirst()
	 */
	private static function findFirstUnguarded(string $content, string $pattern): int|false|null
	{
		$offset = self::findFirst($content, $pattern);
		$depth = 0;
		$tryDepth = null;
		$brace = strcspn($content, '{}');
		while (is_int($offset))
		{
			while ($brace < $offset)
			{
				if ($content[$brace] === '{')
				{
					if ($tryDepth === null && self::opensTryBlock($content, $brace))
					{
						$tryDepth = $depth;
					}
					$depth++;
				}
				elseif ($depth > 0)
				{
					$depth--;
					if ($depth === $tryDepth)
					{
						$tryDepth = null;
					}
				}
				$brace += 1 + strcspn($content, '{}', $brace + 1);
			}

			if ($tryDepth === null)
			{
				return $offset;
			}
			$offset = self::findFirst($content, $pattern, $offset + 1);
		}

		return $offset;
	}

	/**
	 * Same as `\btry\s*\{` ending at the brace.
	 */
	private static function opensTryBlock(string $content, int $brace): bool
	{
		$end = $brace;
		while ($end > 0 && str_contains(" \t\n\r\v\f", $content[$end - 1]))
		{
			$end--;
		}

		return $end >= 3
			&& substr_compare($content, 'try', $end - 3, 3) === 0
			&& ($end === 3 || !preg_match('#\w#', $content[$end - 4]))
		;
	}
}
