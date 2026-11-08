<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message;

use Bitrix\Mail\Internal\Service\DarkTheme\BlockingSignalDetector;
use Bitrix\Mail\Internal\Service\DarkTheme\BodyColorRewriter;
use Bitrix\Mail\Internal\Service\DarkTheme\ColorMapper;
use Bitrix\Mail\Internal\Service\DarkTheme\DocumentLoader;
use Bitrix\Mail\Internal\Service\DarkTheme\IntegrityGuard;
use Bitrix\Mail\Internal\Service\DarkTheme\StyleSheetRewriter;
use Bitrix\Mail\Internal\Service\DarkTheme\ThemePalette;
use Bitrix\Mail\Public\Service\Message\Dto\BodyDarkThemeResult;
use Bitrix\Main\Diag\LoggerFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Recolors a message body for the dark theme of a consumer that renders the body itself.
 *
 * This is the only entrance to the recolor: the services behind it belong to the private zone of the module, and the
 * consumer never needs them. What the consumer sends is what the reader chose - the color scheme of the application
 * and the colors of its theme; what it gets back is the body to show and whether that body is the recolored copy.
 *
 * The recolor is never mandatory: the original body with a reason is a normal answer, and no failure of the pipeline
 * reaches the consumer as an exception. One instance serves any number of messages - the state of a single recolor
 * lives inside the call.
 */
final class BodyDarkThemeService
{
	public const COLOR_SCHEME_LIGHT = 'light';
	public const COLOR_SCHEME_DARK = 'dark';

	public const REASON_INVALID_PALETTE = 'INVALID_PALETTE';
	public const REASON_INTERNAL_ERROR = 'INTERNAL_ERROR';
	public const REASON_NO_COLOR_CHANGES = 'NO_COLOR_CHANGES';

	private const LOGGER_ID = 'mail.message.dark_theme';

	/** The same markup token the processing budget of the detector is counted by. */
	private const MARKUP_PATTERN = '/<[a-zA-Z!\/?]/';

	/**
	 * @param DocumentLoader $documentLoader All parsing of one message goes through one loader.
	 * @param LoggerInterface|null $logger Journal of refusals; the module journal is used when none is given.
	 */
	public function __construct(
		private readonly DocumentLoader $documentLoader = new DocumentLoader(),
		private ?LoggerInterface $logger = null,
	)
	{
	}

	/**
	 * Applies the requested color scheme to the body of a message.
	 *
	 * @param string $body Body as it is stored; it is never modified.
	 * @param string $colorScheme One of the COLOR_SCHEME_* values; anything else is read as the light one.
	 * @param array $themeColors Theme tokens of the consumer, name to `#rrggbb`; every token is required.
	 * @return BodyDarkThemeResult Recolored copy, or the original body with the reason it was refused:
	 *         REASON_INVALID_PALETTE, REASON_INTERNAL_ERROR, REASON_NO_COLOR_CHANGES, a blocking signal or an
	 *         exhausted budget of BlockingSignalDetector, or a refusal of the recolor itself - text over an
	 *         author picture nobody can measure, text over a surface a rule of a style block kept, unreachable
	 *         contrast, lost content, markup that became executable.
	 */
	public function applyColorScheme(string $body, string $colorScheme, array $themeColors): BodyDarkThemeResult
	{
		if (!BodyThemeFeature::isMobileAvailable())
		{
			return $this->original($body);
		}

		if (strtolower(trim($colorScheme)) !== self::COLOR_SCHEME_DARK)
		{
			return $this->original($body);
		}

		// A body without a markup token has no HTML representation to recolor and is not worth parsing.
		if (preg_match(self::MARKUP_PATTERN, $body) !== 1)
		{
			return $this->original($body);
		}

		$paletteResult = ThemePalette::createFromTokens($themeColors);
		if (!$paletteResult->isSuccess())
		{
			return $this->refuse($body, self::REASON_INVALID_PALETTE);
		}

		try
		{
			return $this->recolor($body, $paletteResult->getData()[ThemePalette::PALETTE_KEY]);
		}
		catch (\Throwable $exception)
		{
			return $this->refuse($body, self::REASON_INTERNAL_ERROR, $exception);
		}
	}

	/**
	 * Runs the recoloring pipeline over a body the detector has allowed.
	 */
	private function recolor(string $body, ThemePalette $palette): BodyDarkThemeResult
	{
		$detectResult = (new BlockingSignalDetector($this->documentLoader))->detect($body);
		if (!$detectResult->isSuccess())
		{
			// The reason of the detector travels as it is: a refusal by budget must stay told apart in production.
			return $this->refuse($body, (string)$detectResult->getError()?->getCode());
		}

		// One color mapper and one integrity guard serve one message.
		$colorMapper = new ColorMapper($palette);
		$integrityGuard = new IntegrityGuard($this->documentLoader);

		// Everything the pipeline knows about a body it learns from one parser, and nothing it does afterwards
		// can tell that parser apart from the view. A body the two read differently is asked about before the
		// recolor and after the budgets of the detector, which are what bounds the work a body may cost.
		if (!$integrityGuard->parseModelsAgree($body))
		{
			return $this->refuse($body, IntegrityGuard::REASON_PARSE_MODELS_DIVERGE);
		}

		$document = $detectResult->getData()[BlockingSignalDetector::DOCUMENT_KEY];
		$bodyColorRewriter = new BodyColorRewriter($colorMapper);
		$styleSheetRewriter = new StyleSheetRewriter($colorMapper);

		$colorChangeCount = $styleSheetRewriter->rewrite($document);

		// An important text color of the author that the recolor cannot move outranks every ordinary foreground
		// the walk writes, so the walk is told about it before it writes the first one. The style blocks are
		// where importance can be written, and they are read first.
		$colorChangeCount += $bodyColorRewriter->rewrite(
			$document,
			$styleSheetRewriter->hasUnmovableImportantTextColor()
		);

		// A body that declares no color of its own is shown in the light default colors of the view inside an
		// application that is dark, so the theme pair on its root is the whole recolor such a body needs.
		if ($colorChangeCount === 0)
		{
			$colorChangeCount = $bodyColorRewriter->themeRootSurface($document);
		}

		// A body that holds both a surface the recolor could not move and a text color the pipeline chose is shown
		// as its author drew it, wherever in the document each of the two was declared.
		//
		// Proving that this color lands on that surface would need the cascade - matching a selector to an
		// element - which the pipeline restores nowhere and, by the decision of the design, never will. Every
		// answer built instead out of the sources of a surface and the sources of a foreground has so far named
		// only some of them, and each time the gap read as a complete rule. So the two sides are counted apart
		// and over the whole document: the recolor of a message that mixes them is worth less to the reader than
		// the risk of light letters on a light ground.
		//
		// Everywhere the elements are known, the pair is held instead of the body refused: a surface the walk
		// keeps keeps its whole subtree with it. What is left here are the surfaces of a document whose elements
		// nobody can name - artwork the pipeline cannot measure, and a rule the rewriter had to keep whole.
		$recoloredTextColor =
			$bodyColorRewriter->hasRecoloredTextColor()
			|| $styleSheetRewriter->hasRecoloredTextColor()
		;

		if (
			$recoloredTextColor
			&& ($bodyColorRewriter->hasAuthorPictureSurface() || $styleSheetRewriter->hasAuthorPictureSurface())
		)
		{
			return $this->refuse($body, BodyColorRewriter::REASON_BACKGROUND_IMAGE_WITHOUT_FALLBACK);
		}

		// Paint of the author the recolor leaves on the screen - a bright accent it keeps by design, a plate the
		// walk keeps whole, a value it cannot move - answers for the letters he painted on it himself, and for
		// no others. The letters he painted nowhere read a color of an ancestor, which the walk knows and
		// reports, or a color of a rule, which no part of the pipeline can match to an element - and of those
		// only the rules that paint no surface of their own, because a rule that paints both halves of the pair
		// carries them to the same element wherever it matches. Light paint is where this costs the reader his
		// message: on paint the author made dark, the letters the pipeline chooses are the letters that read.
		if (
			$bodyColorRewriter->hasThemeTextOnAuthorSurface()
			|| (
				$styleSheetRewriter->hasLooseRecoloredTextColor()
				&& $bodyColorRewriter->hasUnpaintedTextOnAuthorSurface()
			)
			|| ($recoloredTextColor && $styleSheetRewriter->hasKeptAuthorSurface())
		)
		{
			return $this->refuse($body, StyleSheetRewriter::REASON_AUTHOR_SURFACE_KEPT);
		}

		if ($colorMapper->hasUnreachableContrast())
		{
			return $this->refuse($body, ColorMapper::REASON_CONTRAST_UNREACHABLE);
		}

		$recolored = $integrityGuard->serialize($document);
		if ($colorChangeCount === 0)
		{
			return $this->refuse($body, self::REASON_NO_COLOR_CHANGES);
		}

		if (!$integrityGuard->contentPreserved($body, $recolored))
		{
			return $this->refuse($body, IntegrityGuard::REASON_INTEGRITY_FAILED);
		}

		if ($integrityGuard->introducesExecutableContent($body, $recolored))
		{
			return $this->refuse($body, IntegrityGuard::REASON_SAFETY_FAILED);
		}

		return new BodyDarkThemeResult($recolored, true);
	}

	/**
	 * The dark theme was not asked for at all: no recolor was attempted and there is nothing to report.
	 */
	private function original(string $body): BodyDarkThemeResult
	{
		return new BodyDarkThemeResult($body, false);
	}

	/**
	 * The recolor was attempted and refused. The reason is journalled as well as returned: without it the share of
	 * bodies shown in the original colors cannot be measured in production.
	 */
	private function refuse(string $body, string $reason, ?\Throwable $exception = null): BodyDarkThemeResult
	{
		$this->getLogger()->debug('Message body is shown in its original colors: {reason}', [
			'reason' => $reason,
			'exception' => $exception,
		]);

		return new BodyDarkThemeResult($body, false, $reason);
	}

	private function getLogger(): LoggerInterface
	{
		$this->logger ??= (new LoggerFactory())->createById(self::LOGGER_ID, [], false) ?? new NullLogger();

		return $this->logger;
	}
}
