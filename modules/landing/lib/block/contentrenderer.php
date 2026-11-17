<?php
namespace Bitrix\Landing\Block;

use Bitrix\Landing\Debug;
use Bitrix\Landing\LandingBlock;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Node;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache as DataCache;
use Bitrix\Main\Diag\ExceptionHandlerLog;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Streaming renderer of the physical content of a block.
 *
 * The content is split into text and component segments and checked against the closed grammar of
 * the documented block contract: a prolog guard and $APPLICATION->IncludeComponent() calls with
 * literal parameters, where a parameter may also be ={$classBlock->get("KEY")}, and where the name
 * of the component belongs to AllowedComponents. Everything else is out of the grammar and is
 * never executed: the caller decides what to print instead.
 *
 * A component node of the manifest binds every call of its component to public methods of the block
 * class through the extra keys showIf, editStub and returnTo: the php that a block used to run
 * around a call now belongs to its class. The markup around a call is decided the same way by the
 * manifest key envelopes: it names the method of every range of the content between the markers
 * <!--#env:NAME--> and <!--/#env:NAME-->, and a range the class hides is dropped whole, the calls
 * inside it included. An envelope belongs to a pair of markers and to nothing else, so moving a
 * range of the markup moves its condition with it.
 *
 * The reading of a content is the same work for the same bytes and runs on every view of a block,
 * so it is split in two. The parse - the chunks of the tokenizer, the segments and the verdict -
 * depends on the content alone and is remembered by the hash of it, within the hit and in the cache
 * of the core. Everything bound to the block itself - the options of the nodes of its manifest, the
 * methods of its class, the mode of the render and the values of ={$classBlock->get()} - is bound to
 * the segments again on every call and never reaches the cache.
 */
class ContentRenderer
{
	public const VERDICT_NO_PHP = 'NO_PHP';
	public const VERDICT_GRAMMAR_OK = 'GRAMMAR_OK';
	public const VERDICT_OUT_OF_GRAMMAR = 'OUT_OF_GRAMMAR';

	private const SEGMENT_TEXT = 'text';
	private const SEGMENT_COMPONENT = 'component';
	private const SEGMENT_MARKER = 'marker';

	/**
	 * Key the range of a bound envelope is kept under, on the segment of its opening marker.
	 */
	private const SEGMENT_ENVELOPE = 'envelope';

	private const MESSAGE_COMPONENT = 'bitrix:landing.blocks.message';

	/**
	 * Audit type of the record a broken component of a block leaves.
	 */
	private const LOG_TYPE_RENDER_FAILED = 'LANDING_BLOCK_RENDER_FAILED';

	/**
	 * Version of the parse the stored result is keyed by. Raise it whenever a content starts being
	 * read differently or the segments change their shape - the grammar itself, splitChunks(),
	 * makeSegments(), makeTextSegments(), makeComponentSegment() - so that a result stored by an
	 * older version of the code is never read back. The white list of the components signs the key on
	 * its own through AllowedComponents::fingerprint(), so a name added to it needs no raise here.
	 */
	private const PARSE_VERSION = 2;

	/**
	 * A parse is derived data of the content, safe to lose and to rebuild: the key of a content
	 * changes with the content itself, so the result needs no tag of the block and no invalidation
	 * point - only a day of life.
	 */
	private const PARSE_CACHE_TTL = 86400;
	private const PARSE_CACHE_DIR = '/landing/block_parse';

	/**
	 * A content bigger than this is parsed on the hit and stays with the memo of the hit: an item of
	 * the cache of the core is small by default, and such a block is a rarity anyway.
	 */
	private const PARSE_CACHE_MAX_LENGTH = 262144;

	/**
	 * How many parses one hit keeps: a page holds tens of blocks, while a stepper walks thousands of
	 * contents with one renderer and may not grow the memory of the step with every row.
	 */
	private const PARSE_MEMO_LIMIT = 64;

	private const PROLOG_GUARD_PATTERN = '/if\s*\(\s*!\s*defined\s*\(\s*([\'"])B_PROLOG_INCLUDED\1\s*\)'
		. '\s*\|\|\s*B_PROLOG_INCLUDED\s*!==?\s*true\s*\)\s*'
		. '(?:\{\s*die\s*(?:\(\s*\))?\s*;?\s*\}|die\s*(?:\(\s*\))?\s*;?)/i';

	private const DYNAMIC_VALUE_PATTERN = '/^=\{.*\}$/s';

	/**
	 * The one spelling of a dynamic param the grammar accepts; the key of the class is $matches[2].
	 * Public: the editor reads a value it writes into a content by the same pattern.
	 */
	public const CLASS_BLOCK_VALUE_PATTERN = '/^=\{\s*\$classBlock\s*->\s*get\s*\(\s*(["\'])([^"\']*)\1\s*\)\s*\}$/s';

	private const XML_DECLARATION_PATTERN
		= '/^<\?xml(?:\s+[A-Za-z_][A-Za-z0-9_.:-]*\s*=\s*("[^"]*"|\'[^\']*\'))*\s*\?>$/iD';

	private const LITERAL_FALSE_VALUES = ['', 'false', 'null'];

	private const OPTION_SHOW_IF = 'showIf';
	private const OPTION_EDIT_STUB = 'editStub';
	private const OPTION_RETURN_TO = 'returnTo';

	private const OPTION_KEYS = [
		self::OPTION_SHOW_IF,
		self::OPTION_EDIT_STUB,
		self::OPTION_RETURN_TO,
	];

	private const METHOD_NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';
	private const ENVELOPE_MARKER_PREFIX = '#env:';
	private const ENVELOPE_MARKER_PATTERN = '/(<!--\/?#env:[A-Za-z0-9_-]+-->)/';
	private const ENVELOPE_MARKER_PARTS_PATTERN = '/^<!--(\/?)#env:([A-Za-z0-9_-]+)-->$/';

	private ?LandingBlock $classBlock;
	private bool $edit;
	private array $componentOptions;
	private array $manifestEnvelopes;
	private array $sysPageReplacements;
	private array $segments = [];
	private string $verdict = self::VERDICT_OUT_OF_GRAMMAR;
	private bool $contentAsksClass = false;
	private bool $hasUnresolvedParams = false;
	private bool $hasIgnoredOptions = false;
	private bool $messagePrinted = false;
	private bool $renderFailed = false;

	/**
	 * Parses of the hit, by the key of the content.
	 */
	private static array $parsedContents = [];

	/**
	 * @param LandingBlock|null $classBlock Class instance of the block, source of ={$classBlock->get()} values.
	 * @param bool $edit Edit mode of the block.
	 * @param array $manifestNodes Nodes of the manifest of the block, source of the options of a component.
	 * @param array $sysPageReplacements System page markers (like Block::view() builds for the raw content)
	 *    applied to a value ={$classBlock->get()} resolves: a class of a block may hand out such a marker
	 *    at render time, where the replace of the raw content already ran and can no longer see it.
	 * @param array $manifestEnvelopes Envelopes of the manifest of the block: name of a marker of the
	 *    content => name of the public method of the block class which decides whether that range is printed.
	 */
	public function __construct(
		?LandingBlock $classBlock = null,
		bool $edit = false,
		array $manifestNodes = [],
		array $sysPageReplacements = [],
		array $manifestEnvelopes = [],
	)
	{
		$this->classBlock = $classBlock;
		$this->edit = $edit;
		$this->componentOptions = $this->collectComponentOptions($manifestNodes);
		$this->manifestEnvelopes = $manifestEnvelopes;
		$this->sysPageReplacements = $sysPageReplacements;
	}

	/**
	 * Splits the content into segments and checks them against the grammar. Prints nothing.
	 * @param string $content Content of the block.
	 * @return string One of the VERDICT_* constants.
	 */
	public function analyze(string $content): string
	{
		$this->segments = [];
		$this->hasIgnoredOptions = false;

		$parsed = $this->getParsedContent($content);
		$this->verdict = $parsed['verdict'];
		// whether a value of the class of the block is missing is a question of the block, not of the content
		$this->hasUnresolvedParams = $parsed['dynamic'] && $this->classBlock === null;

		if ($this->verdict !== self::VERDICT_OUT_OF_GRAMMAR)
		{
			$this->segments = $this->bindEnvelopes($this->bindSegmentOptions($parsed['segments']));
		}

		return $this->verdict;
	}

	/**
	 * Returns the parse of a content: the memo of the hit, then the cache of the core, then the
	 * tokenizer of php. A content with no opening of php at all is answered before all of them - the
	 * check costs one pass over the string, while the parser reads a block of megabytes character by
	 * character.
	 * @param string $content Content of the block.
	 * @return array
	 */
	private function getParsedContent(string $content): array
	{
		if (!str_contains($content, '<?'))
		{
			return $this->makeParsedContent(self::VERDICT_NO_PHP, $this->makeTextSegments($content));
		}

		$key = self::getParseKey($content);
		if (isset(self::$parsedContents[$key]))
		{
			return self::$parsedContents[$key];
		}

		$parsed = $this->readParsedContent($key, $content);

		if (count(self::$parsedContents) >= self::PARSE_MEMO_LIMIT)
		{
			array_shift(self::$parsedContents);
		}
		self::$parsedContents[$key] = $parsed;

		return $parsed;
	}

	/**
	 * Returns the parse stored by the cache of the core, or parses the content and stores it there.
	 * @param string $key Key of the parse of the content.
	 * @param string $content Content of the block.
	 * @return array
	 */
	private function readParsedContent(string $key, string $content): array
	{
		if (strlen($content) > self::PARSE_CACHE_MAX_LENGTH)
		{
			return $this->parseContent($content);
		}

		$cache = DataCache::createInstance();
		$cache->noOutput();

		if ($cache->initCache(self::PARSE_CACHE_TTL, $key, self::PARSE_CACHE_DIR))
		{
			$stored = $cache->getVars();
			if ($this->isParsedContent($stored))
			{
				return $stored;
			}
		}

		$parsed = $this->parseContent($content);
		if ($cache->startDataCache())
		{
			$cache->endDataCache($parsed);
		}

		return $parsed;
	}

	/**
	 * Splits the content into segments and checks them against the grammar. The whole cost of the
	 * tokenizer of php lives here, behind the memo of the hit and the cache of the core.
	 * @param string $content Content of the block.
	 * @return array Verdict, segments of the content, and whether the content asks the class of the
	 *    block for a value of a param.
	 */
	protected function parseContent(string $content): array
	{
		$this->contentAsksClass = false;

		$chunks = $this->splitChunks($content);
		if (!$this->hasPhpChunk($chunks))
		{
			return $this->makeParsedContent(self::VERDICT_NO_PHP, $this->makeTextSegments($content));
		}

		$segments = $this->makeSegments($content, $chunks, \PHPParser::parseScript($content));
		if ($segments === null)
		{
			return $this->makeParsedContent(self::VERDICT_OUT_OF_GRAMMAR, []);
		}

		return $this->makeParsedContent(self::VERDICT_GRAMMAR_OK, $segments, $this->contentAsksClass);
	}

	/**
	 * @param string $verdict One of the VERDICT_* constants.
	 * @param array $segments Segments of the content, without anything bound to the block itself.
	 * @param bool $dynamic Whether the content holds a value of the class of the block.
	 * @return array
	 */
	private function makeParsedContent(string $verdict, array $segments, bool $dynamic = false): array
	{
		return ['verdict' => $verdict, 'segments' => $segments, 'dynamic' => $dynamic];
	}

	/**
	 * @param mixed $stored What the cache of the core gave back.
	 * @return bool
	 */
	private function isParsedContent($stored): bool
	{
		return is_array($stored)
			&& is_string($stored['verdict'] ?? null)
			&& is_array($stored['segments'] ?? null)
			&& is_bool($stored['dynamic'] ?? null);
	}

	/**
	 * Key of a parse: the content itself, the version of the reading it went through and the white
	 * list of the components it was judged by - a name added to the list has to change the verdict
	 * of a content at once. Nothing of the block, of its manifest or of the mode of the render
	 * belongs here: that part is bound to the segments on every call.
	 * @param string $content Content of the block.
	 * @return string
	 */
	private static function getParseKey(string $content): string
	{
		return 'landing.block.parse.v' . self::PARSE_VERSION
			. '.' . AllowedComponents::fingerprint()
			. '.' . md5($content);
	}

	/**
	 * Binds the options of the nodes of the manifest, resolved against the class of the block, to the
	 * calls of a parsed content.
	 * @param array $segments Segments of the content.
	 * @return array
	 */
	private function bindSegmentOptions(array $segments): array
	{
		foreach ($segments as $index => $segment)
		{
			if ($segment['type'] === self::SEGMENT_COMPONENT)
			{
				$segments[$index]['options'] = $this->makeSegmentOptions($segment['name']);
			}
		}

		return $segments;
	}

	/**
	 * Tells whether the analyzed content can be printed at all: it holds nothing beyond the grammar
	 * and asks the class of the block for no value the renderer has no class to resolve.
	 * @return bool
	 */
	public function canRender(): bool
	{
		return $this->verdict !== self::VERDICT_OUT_OF_GRAMMAR && !$this->hasUnresolvedParams;
	}

	/**
	 * Prints the analyzed segments into the current output buffer.
	 * @return void
	 */
	public function render(): void
	{
		$this->messagePrinted = false;
		$this->renderFailed = false;

		if ($this->verdict === self::VERDICT_OUT_OF_GRAMMAR)
		{
			return;
		}

		if ($this->hasUnresolvedParams)
		{
			// a param no class of the block can fill is not a param of a call: the call is not made
			$this->printErrorMessage();

			return;
		}

		if ($this->hasIgnoredOptions)
		{
			// the message belongs to the block, so it goes before the content and not after its wrapper
			$this->printErrorMessage();
		}

		$count = count($this->segments);
		for ($index = 0; $index < $count; $index++)
		{
			$segment = $this->segments[$index];

			if ($this->renderFailed && $segment['type'] === self::SEGMENT_COMPONENT)
			{
				continue;
			}

			$bufferLevel = ob_get_level();

			try
			{
				if ($segment['type'] !== self::SEGMENT_COMPONENT)
				{
					$skipped = $this->getHiddenEnvelopeEnd($segment);
					if ($skipped !== null)
					{
						$index = $skipped;

						continue;
					}

					echo $segment['text'];

					continue;
				}

				$this->renderComponentSegment($segment);
			}
			catch (\Throwable $exception)
			{
				$this->handleSegmentFailure($segment, $exception, $bufferLevel);
			}
		}
	}

	/**
	 * A broken component takes only itself: the buffers it left open are dropped, the block keeps
	 * printing its own markup - the wrapper of a block that broke in the middle would otherwise stay
	 * open and swallow the blocks after it - and no other component of the block is started in the
	 * state that call left behind.
	 * @param array $segment Segment the output broke on.
	 * @param \Throwable $exception What the segment threw.
	 * @param int $bufferLevel Level of the output buffers before the segment.
	 * @return void
	 */
	private function handleSegmentFailure(array $segment, \Throwable $exception, int $bufferLevel): void
	{
		while (ob_get_level() > $bufferLevel && ob_end_clean())
		{
		}

		if (!$this->renderFailed)
		{
			$this->logRenderFailure($segment, $exception);
		}

		$this->renderFailed = true;
		$this->printErrorMessage();
	}

	/**
	 * One record per render of a block. The exception itself goes to the error flow of the product in
	 * both modes: that is the handler the exception reached before the renderer caught it, and it
	 * writes a file record without growing the audit table on an anonymous public hit. The record of
	 * the audit table stays in edit mode only, where it has a reader.
	 * @param array $segment Segment the output broke on.
	 * @param \Throwable $exception What the segment threw.
	 * @return void
	 */
	private function logRenderFailure(array $segment, \Throwable $exception): void
	{
		try
		{
			Application::getInstance()
				->getExceptionHandler()
				->writeToLog($exception, ExceptionHandlerLog::LOW_PRIORITY_ERROR)
			;
		}
		catch (\Throwable $logFailure)
		{
			// the journal may not turn a survivable break of a block into a break of the page
		}

		if (!$this->edit)
		{
			return;
		}

		try
		{
			Debug::log(
				0,
				'block content render failed'
				. '; component: ' . ($segment['name'] ?? '')
				. '; ' . get_class($exception) . ': ' . $exception->getMessage(),
				self::LOG_TYPE_RENDER_FAILED
			);
		}
		catch (\Throwable $logFailure)
		{
			// the journal may not turn a survivable break of a block into a break of the page
		}
	}

	/**
	 * Returns the index of the closing marker of an envelope the class of the block hides, or null
	 * when the segments of the envelope are printed as usual.
	 * @param array $segment Text or marker segment.
	 * @return int|null
	 */
	private function getHiddenEnvelopeEnd(array $segment): ?int
	{
		$envelope = $segment[self::SEGMENT_ENVELOPE] ?? null;
		if ($envelope === null || $this->callBlockMethod($envelope[self::OPTION_SHOW_IF]))
		{
			return null;
		}

		return $envelope['end'];
	}

	/**
	 * Prints one component segment by the options of its node of the manifest.
	 * @param array $segment Component segment.
	 * @return void
	 */
	private function renderComponentSegment(array $segment): void
	{
		$options = $segment['options'];

		if (isset($options[self::OPTION_SHOW_IF]) && !$this->callBlockMethod($options[self::OPTION_SHOW_IF]))
		{
			return;
		}

		if ($this->edit && isset($options[self::OPTION_EDIT_STUB]))
		{
			echo (string)$this->callBlockMethod($options[self::OPTION_EDIT_STUB]);

			return;
		}

		$result = $this->includeComponent(
			$segment['name'],
			$segment['template'],
			$this->resolveValues($segment['params']),
			$this->resolveValues($segment['functionParams']),
		);

		if (isset($options[self::OPTION_RETURN_TO]))
		{
			$this->callBlockMethod($options[self::OPTION_RETURN_TO], $result);
		}
	}

	/**
	 * Calls the component of a segment directly into the current buffer.
	 * @param string $name Component name.
	 * @param string $template Template name.
	 * @param array $params Component params.
	 * @param array $functionParams Params of the call itself.
	 * @return mixed What the component returned.
	 */
	protected function includeComponent(string $name, string $template, array $params, array $functionParams)
	{
		return Manager::getApplication()->includeComponent(
			$name,
			$template,
			$params,
			false,
			$functionParams,
		);
	}

	/**
	 * Calls a public method of the class of the block.
	 * @param string $method Name of the method, already resolved by the manifest.
	 * @param mixed ...$arguments Arguments of the call.
	 * @return mixed
	 */
	private function callBlockMethod(string $method, ...$arguments)
	{
		return $this->classBlock->{$method}(...$arguments);
	}

	/**
	 * Splits the content into text chunks and php chunks the way php itself reads it: the blocks come
	 * from the tokenizer, so a '?>' inside a string or a comment closes no chunk and the analyzer sees
	 * the same code as the parser of the component calls. A well formed xml declaration stays a text.
	 * @param string $content Content of the block.
	 * @return array
	 */
	private function splitChunks(string $content): array
	{
		$chunks = [];
		$length = mb_strlen($content);
		$textStart = 0;

		foreach (\PHPParser::parseFile($content) as $block)
		{
			$start = (int)$block[0];
			$blockEnd = (int)$block[1];
			$closed = $blockEnd <= $length && mb_substr($content, $blockEnd - 2, 2) === '?>';
			$end = $closed ? $blockEnd : min($blockEnd, $length);

			if ($this->isXmlDeclaration(mb_substr($content, $start, $end - $start)))
			{
				continue;
			}

			if ($start > $textStart)
			{
				$chunks[] = ['php' => false, 'start' => $textStart, 'end' => $start, 'closed' => false];
			}

			$chunks[] = ['php' => true, 'start' => $start, 'end' => $end, 'closed' => $closed];
			$textStart = $end;
		}

		if ($textStart < $length)
		{
			$chunks[] = ['php' => false, 'start' => $textStart, 'end' => $length, 'closed' => false];
		}

		return $chunks;
	}

	/**
	 * Returns the content with every php chunk replaced by the given text, the chunks being read the
	 * way the grammar reads them. A chunk holding a component call is kept: the call is the node of
	 * the component, with the params the owner of the page may have edited. The migration of an
	 * instance takes the markup of the old content through this: a value of a node the other php
	 * took part in is the php of the old template, not a value of the owner.
	 * @param string $content Content of the block.
	 * @param string $replacement Text to put in the place of every php chunk without a component call.
	 * @return string
	 */
	public static function withoutPhp(string $content, string $replacement): string
	{
		$renderer = new self();
		$chunks = $renderer->splitChunks($content);
		if (!$renderer->hasPhpChunk($chunks))
		{
			return $content;
		}

		$callStarts = array_map(
			static fn(array $component): int => (int)$component['START'],
			\PHPParser::parseScript($content),
		);
		$result = '';
		foreach ($chunks as $chunk)
		{
			$source = mb_substr($content, $chunk['start'], $chunk['end'] - $chunk['start']);
			if (!$chunk['php'])
			{
				$result .= $source;

				continue;
			}

			$holdsCall = false;
			foreach ($callStarts as $start)
			{
				if ($start >= $chunk['start'] && $start < $chunk['end'])
				{
					$holdsCall = true;
					break;
				}
			}
			$result .= $holdsCall ? $source : $replacement;
		}

		return $result;
	}

	/**
	 * An xml declaration opens with '<?' as php does, and with short_open_tag the tokenizer reads it
	 * as code. Only a declaration of attributes stays a text of the content; anything else opened
	 * that way is php and is judged by the grammar.
	 * @param string $chunk Source of a php chunk, opening and closing tags included.
	 * @return bool
	 */
	private function isXmlDeclaration(string $chunk): bool
	{
		return (bool)preg_match(self::XML_DECLARATION_PATTERN, $chunk);
	}

	private function hasPhpChunk(array $chunks): bool
	{
		foreach ($chunks as $chunk)
		{
			if ($chunk['php'])
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Builds the segments of the content, or null when something is out of the grammar.
	 * @param string $content Content of the block.
	 * @param array $chunks Chunks of the content.
	 * @param array $components Components found by \PHPParser.
	 * @return array|null
	 */
	private function makeSegments(string $content, array $chunks, array $components): ?array
	{
		$segments = [];
		$swallowNewLine = false;
		$inChunks = 0;

		foreach ($chunks as $chunk)
		{
			if (!$chunk['php'])
			{
				$text = mb_substr($content, $chunk['start'], $chunk['end'] - $chunk['start']);
				if ($swallowNewLine)
				{
					$text = preg_replace('/^\r?\n/', '', $text);
					$swallowNewLine = false;
				}
				array_push($segments, ...$this->makeTextSegments((string)$text));

				continue;
			}

			$swallowNewLine = $chunk['closed'];
			$codeStart = $this->getCodeStart($content, $chunk);
			$codeEnd = $chunk['closed'] ? $chunk['end'] - 2 : $chunk['end'];
			$inside = $this->getComponentsOfChunk($components, $codeStart, $codeEnd);
			if ($inside === null)
			{
				return null;
			}

			if (!$this->isRestEmpty($content, $codeStart, $codeEnd, $inside))
			{
				return null;
			}

			$inChunks += count($inside);
			foreach ($inside as $component)
			{
				$segment = $this->makeComponentSegment($component['DATA'] ?? []);
				if ($segment === null)
				{
					return null;
				}

				$segments[] = $segment;
			}
		}

		// a call the parser reads outside every php chunk is read differently by the two of them
		if ($inChunks !== count($components))
		{
			return null;
		}

		return $segments;
	}

	/**
	 * Splits a text of the content into text segments and marker segments: an envelope marker is a
	 * html comment of the content and becomes a segment of its own, so that the range of an envelope
	 * can be bound to it later. The text itself is untouched - a marker the manifest names no
	 * envelope for is printed as the comment it is.
	 * @param string $text Text of the content.
	 * @return array
	 */
	private function makeTextSegments(string $text): array
	{
		if ($text === '')
		{
			return [];
		}

		if (!str_contains($text, self::ENVELOPE_MARKER_PREFIX))
		{
			return [$this->makeTextSegment($text)];
		}

		$parts = preg_split(
			self::ENVELOPE_MARKER_PATTERN,
			$text,
			-1,
			PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
		);
		if ($parts === false)
		{
			return [$this->makeTextSegment($text)];
		}

		$segments = [];
		foreach ($parts as $part)
		{
			$segments[] = preg_match(self::ENVELOPE_MARKER_PARTS_PATTERN, $part, $matches)
				? $this->makeMarkerSegment($part, $matches[2], $matches[1] === '')
				: $this->makeTextSegment($part);
		}

		return $segments;
	}

	private function makeTextSegment(string $text): array
	{
		return ['type' => self::SEGMENT_TEXT, 'text' => $text];
	}

	private function makeMarkerSegment(string $text, string $name, bool $open): array
	{
		return ['type' => self::SEGMENT_MARKER, 'text' => $text, 'name' => $name, 'open' => $open];
	}

	/**
	 * Binds every envelope of the manifest to the pair of markers of that name: the opening marker
	 * keeps the method which decides whether the whole range up to the closing marker is printed.
	 * @param array $segments Segments of the content.
	 * @return array
	 */
	private function bindEnvelopes(array $segments): array
	{
		$methods = $this->resolveEnvelopeMethods();
		if (empty($methods))
		{
			return $segments;
		}

		foreach ($this->findEnvelopeRanges($segments, $methods) as $name => $range)
		{
			$segments[$range['open']][self::SEGMENT_ENVELOPE] = [
				'end' => $range['close'],
				self::OPTION_SHOW_IF => $methods[$name],
			];
		}

		return $segments;
	}

	/**
	 * Returns the method of the class of the block every envelope of the manifest stands for, by the
	 * name of its markers. An envelope pointing at no public method is ignored, as an option of a
	 * component node is.
	 * @return array
	 */
	private function resolveEnvelopeMethods(): array
	{
		$methods = [];
		foreach ($this->manifestEnvelopes as $name => $declared)
		{
			$method = $this->resolveBlockMethod($declared);
			if ($method === null || !is_string($name) || $name === '')
			{
				$this->hasIgnoredOptions = true;

				continue;
			}

			$methods[$name] = $method;
		}

		return $methods;
	}

	/**
	 * Returns the range of the segments of every envelope of the manifest, by its name. The markers
	 * of the content are read in one pass, and an envelope whose markers make no correct pair - none
	 * at all, one without the other, the same name twice or a range crossing another envelope - is
	 * left out: the markup of such a range is printed as it is stored and the editor is told about it.
	 * The content is data of the portal, so a wrong marker may hide nothing.
	 * @param array $segments Segments of the content.
	 * @param array $methods Envelopes of the manifest, by the name of their markers.
	 * @return array
	 */
	private function findEnvelopeRanges(array $segments, array $methods): array
	{
		$ranges = [];
		$broken = [];
		$openName = null;
		$openIndex = 0;

		foreach ($segments as $index => $segment)
		{
			if ($segment['type'] !== self::SEGMENT_MARKER || !isset($methods[$segment['name']]))
			{
				continue;
			}

			$name = $segment['name'];

			if (!$segment['open'])
			{
				if ($openName === $name)
				{
					$ranges[$name] = ['open' => $openIndex, 'close' => $index];
					$openName = null;
				}
				else
				{
					$broken[$name] = true;
				}

				continue;
			}

			if ($openName !== null)
			{
				$broken[$openName] = true;
				$broken[$name] = true;

				continue;
			}

			if (isset($ranges[$name]))
			{
				$broken[$name] = true;

				continue;
			}

			$openName = $name;
			$openIndex = $index;
		}

		if ($openName !== null)
		{
			$broken[$openName] = true;
		}

		$ranges = array_diff_key($ranges, $broken);
		if (count($ranges) !== count($methods))
		{
			$this->hasIgnoredOptions = true;
		}

		return $ranges;
	}

	/**
	 * Returns the position of the code right after the opening tag of a php chunk.
	 * @param string $content Content of the block.
	 * @param array $chunk Php chunk.
	 * @return int
	 */
	private function getCodeStart(string $content, array $chunk): int
	{
		$opening = mb_strtolower(mb_substr($content, $chunk['start'], 5));
		if ($opening === '<?php')
		{
			return $chunk['start'] + 5;
		}

		if (mb_substr($content, $chunk['start'], 3) === '<?=')
		{
			return $chunk['start'] + 3;
		}

		return $chunk['start'] + 2;
	}

	/**
	 * Returns the components of one php chunk, or null when a component call is broken.
	 * @param array $components Components found by \PHPParser.
	 * @param int $codeStart Start of the code of the chunk.
	 * @param int $codeEnd End of the code of the chunk.
	 * @return array|null
	 */
	private function getComponentsOfChunk(array $components, int $codeStart, int $codeEnd): ?array
	{
		$inside = [];
		foreach ($components as $component)
		{
			$start = (int)$component['START'];
			if ($start < $codeStart || $start >= $codeEnd)
			{
				continue;
			}

			if (!$component['END'] || $component['END'] > $codeEnd)
			{
				return null;
			}

			$inside[] = $component;
		}

		return $inside;
	}

	/**
	 * Checks that the chunk holds nothing but the prolog guard, comments and the component calls.
	 * @param string $content Content of the block.
	 * @param int $codeStart Start of the code of the chunk.
	 * @param int $codeEnd End of the code of the chunk.
	 * @param array $inside Components of the chunk.
	 * @return bool
	 */
	private function isRestEmpty(string $content, int $codeStart, int $codeEnd, array $inside): bool
	{
		$rest = '';
		$position = $codeStart;
		foreach ($inside as $component)
		{
			if ($component['START'] > $position)
			{
				$rest .= mb_substr($content, $position, $component['START'] - $position);
			}
			$position = max($position, (int)$component['END']);
		}
		if ($codeEnd > $position)
		{
			$rest .= mb_substr($content, $position, $codeEnd - $position);
		}

		$withoutGuard = preg_replace(self::PROLOG_GUARD_PATTERN, '', $rest, 1);
		if ($withoutGuard === null)
		{
			// a failure of pcre tells nothing about the chunk, so the chunk is not taken for a clean one
			return false;
		}

		return $this->isCodeEmpty($withoutGuard);
	}

	/**
	 * Checks that the code holds no statements - only comments and spaces.
	 * @param string $code Php code without tags.
	 * @return bool
	 */
	private function isCodeEmpty(string $code): bool
	{
		if (trim($code) === '')
		{
			return true;
		}

		$tokens = @token_get_all('<?php ' . $code);
		foreach ($tokens as $token)
		{
			if (!is_array($token))
			{
				return false;
			}

			if (!in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Builds a component segment, or null when the call is out of the grammar.
	 * @param array $data Component data of \PHPParser.
	 * @return array|null
	 */
	private function makeComponentSegment(array $data): ?array
	{
		$name = $data['COMPONENT_NAME'] ?? '';
		$template = $data['TEMPLATE_NAME'] ?? '';
		// the content names the call, it does not choose what to call
		if (!is_string($name) || !AllowedComponents::isAllowed($name))
		{
			return null;
		}
		if (!is_string($template) || $this->isDynamicValue($template))
		{
			return null;
		}

		// the return of the call belongs to a block class, not to the content
		if (($data['VARIABLE'] ?? '') !== '')
		{
			return null;
		}

		foreach (['PARENT_COMP', 'RETURN_RESULT'] as $key)
		{
			$value = mb_strtolower(trim((string)($data[$key] ?? '')));
			if (!in_array($value, self::LITERAL_FALSE_VALUES, true))
			{
				return null;
			}
		}

		$params = $data['PARAMS'] ?? [];
		$functionParams = $data['FUNCTION_PARAMS'] ?? [];
		if (!is_array($params) || !is_array($functionParams))
		{
			return null;
		}

		$params = $this->restoreEmptyArrays($params);
		$functionParams = $this->restoreEmptyArrays($functionParams);

		if (!$this->areValuesAllowed($params) || !$this->areValuesAllowed($functionParams))
		{
			return null;
		}

		return [
			'type' => self::SEGMENT_COMPONENT,
			'name' => $name,
			'template' => $template,
			'params' => $params,
			'functionParams' => $functionParams,
		];
	}

	/**
	 * \PHPParser::GetParamsRec leaves the slot of an 'array()' / '[]' param as a native null (a literal
	 * null of the content is read as an out-of-grammar dynamic value instead), so such null is that array.
	 * @param array $values Params of a component call, straight from \PHPParser.
	 * @return array
	 */
	private function restoreEmptyArrays(array $values): array
	{
		foreach ($values as $key => $value)
		{
			if ($value === null)
			{
				$values[$key] = [];
			}
			elseif (is_array($value))
			{
				$values[$key] = $this->restoreEmptyArrays($value);
			}
		}

		return $values;
	}

	/**
	 * Collects the options of the component nodes of the manifest, by the name of the component.
	 * @param array $manifestNodes Nodes of the manifest of the block.
	 * @return array
	 */
	private function collectComponentOptions(array $manifestNodes): array
	{
		$options = [];
		foreach ($manifestNodes as $name => $node)
		{
			if (
				!is_string($name)
				|| !is_array($node)
				|| ($node['type'] ?? '') !== Node\Type::COMPONENT
			)
			{
				continue;
			}

			$extra = is_array($node['extra'] ?? null) ? $node['extra'] : [];
			$options[$name] = array_intersect_key($extra, array_flip(self::OPTION_KEYS));
		}

		return $options;
	}

	/**
	 * Resolves the options of the node of a component against the class of the block. A node decides
	 * for every call of its component: an option belongs to the component of the block, not to the
	 * place a call of it stands in the content.
	 * @param string $name Component name.
	 * @return array
	 */
	private function makeSegmentOptions(string $name): array
	{
		$options = [];
		foreach ($this->componentOptions[$name] ?? [] as $option => $declared)
		{
			$method = $this->resolveBlockMethod($declared);
			if ($method !== null)
			{
				$options[$option] = $method;
			}
		}

		return $options;
	}

	/**
	 * Returns the name of a public method of the class of the block, or null when the option of the
	 * manifest points at no such method: an option like that is ignored, not called by any other way.
	 * @param mixed $declared Value of an option of a component node.
	 * @return string|null
	 */
	private function resolveBlockMethod($declared): ?string
	{
		if (
			$this->classBlock === null
			|| !is_string($declared)
			|| !preg_match(self::METHOD_NAME_PATTERN, $declared)
			|| !method_exists($this->classBlock, $declared)
			|| !(new \ReflectionMethod($this->classBlock, $declared))->isPublic()
		)
		{
			$this->hasIgnoredOptions = true;

			return null;
		}

		return $declared;
	}

	/**
	 * Checks that keys and values hold literals and ={$classBlock->get("KEY")} only.
	 * @param array $values Params of a component call.
	 * @return bool
	 */
	private function areValuesAllowed(array $values): bool
	{
		foreach ($values as $key => $value)
		{
			if (is_string($key) && !$this->isValueAllowed($key))
			{
				return false;
			}

			if (is_array($value))
			{
				if (!$this->areValuesAllowed($value))
				{
					return false;
				}

				continue;
			}

			if (is_string($value) && !$this->isValueAllowed($value))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Checks one key or value of a call. A value of the class of the block is written down with the
	 * parse: a block which has no class to fill such a param makes no call of it at all, the way the
	 * old eval never made it.
	 * @param string $value Key or value of a param.
	 * @return bool
	 */
	private function isValueAllowed(string $value): bool
	{
		if (!$this->isDynamicValue($value))
		{
			return true;
		}

		if (!$this->isClassBlockValue($value))
		{
			return false;
		}

		$this->contentAsksClass = true;

		return true;
	}

	private function isDynamicValue(string $value): bool
	{
		return (bool)preg_match(self::DYNAMIC_VALUE_PATTERN, $value);
	}

	private function isClassBlockValue(string $value): bool
	{
		return (bool)preg_match(self::CLASS_BLOCK_VALUE_PATTERN, $value);
	}

	/**
	 * Replaces ={$classBlock->get("KEY")} values by the data of the block class.
	 * @param array $values Params of a component call.
	 * @return array
	 */
	private function resolveValues(array $values): array
	{
		$resolved = [];
		foreach ($values as $key => $value)
		{
			if (is_string($key) && $this->isClassBlockValue($key))
			{
				$key = (string)$this->resolveValue($key);
			}

			$resolved[$key] = is_array($value) ? $this->resolveValues($value) : $this->resolveValue($value);
		}

		return $resolved;
	}

	/**
	 * @param mixed $value Value of a param.
	 * @return mixed
	 */
	private function resolveValue($value)
	{
		if (
			$this->classBlock === null
			|| !is_string($value)
			|| !preg_match(self::CLASS_BLOCK_VALUE_PATTERN, $value, $matches)
		)
		{
			return $value;
		}

		return $this->replaceSysPages($this->classBlock->get($matches[2]));
	}

	/**
	 * Applies the same system page replace Block::view() runs on the raw content: a value resolved
	 * from the class of the block at render time never went through that replace, because it did not
	 * exist yet in the content when the replace ran.
	 * @param mixed $value Value ={$classBlock->get()} resolved to.
	 * @return mixed
	 */
	private function replaceSysPages($value)
	{
		if (!is_string($value) || $value === '' || empty($this->sysPageReplacements))
		{
			return $value;
		}

		return preg_replace(
			array_keys($this->sysPageReplacements),
			array_values($this->sysPageReplacements),
			$value,
		);
	}

	private function printErrorMessage(): void
	{
		if (!$this->edit || $this->messagePrinted)
		{
			return;
		}

		$this->messagePrinted = true;

		Manager::getApplication()->includeComponent(
			self::MESSAGE_COMPONENT,
			'',
			[
				'MESSAGE' => Loc::getMessage('LANDING_BLOCK_MESSAGE_ERROR_RENDER'),
				'MESSAGE_TYPE' => 'alert',
			],
			false,
		);
	}
}
