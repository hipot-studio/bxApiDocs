<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\MarkdownEditor;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Internal\Service\MarkdownRenderService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Renders the unsaved text of a Markdown file being edited for the preview column, by the very render of the view
 * ({@see MarkdownRenderService::render()}), so the preview and the view after the save are the same document.
 *
 * Whoever asks has already been let edit by {@see AccessResolver}. Every call is first booked against the limit of
 * {@see PreviewRateLimiter} - an employee spends a budget of their own, a guest of a public link spends the budget
 * of the link and the address they come from and the budget of the link as a whole - then the size and the
 * encoding are checked as the write checks them, and only then the text is rendered. A text over the size threshold
 * of the preview ({@see Configuration::getMaxSizeForMarkdownPreview()}) is not rendered: the call is still charged.
 *
 * The bytes are rendered as they came: a BOM and line ends are not touched. Nothing is written: the file, its
 * revisions, the sessions of editing and the cache of the view stay as they are.
 */
final class PreviewService
{
	public const ERROR_RATE_LIMITED = 'preview_rate_limited';
	public const ERROR_TOO_LARGE = 'preview_too_large';

	private const RATE_LIMITER_SERVICE = 'disk.markdownEditor.previewRateLimiter';

	public function __construct(
		private readonly ?PreviewRateLimiter $limiter = null,
		private readonly ?MarkdownRenderService $renderer = null,
	)
	{
	}

	/**
	 * @param string $contentBase64 The full bytes of the text being edited, base64.
	 * @param string $clientIp The address the guest of a public link comes from; not stored as is.
	 * @return Result data: 'html' - the document of the view, 'diagrams' - the sources of its mermaid diagrams.
	 */
	public function render(EditorIdentity $who, string $contentBase64, string $clientIp): Result
	{
		$budgets = $this->buildBudgets($who, $clientIp);
		if ($budgets === null)
		{
			return $this->refuse(AccessResolver::ERROR_ACCESS_DENIED, 'Access denied.');
		}

		$chargedBytes = intdiv(strlen($contentBase64) * 3, 4);
		$window = Configuration::getMarkdownPreviewRateWindowSeconds();
		if (!$this->getLimiter()->tryConsume($budgets, $chargedBytes, $window))
		{
			return $this->refuse(self::ERROR_RATE_LIMITED, 'Too many previews of the Markdown text.');
		}

		$maxSize = Configuration::getMaxSizeForMarkdownRender();
		if (strlen($contentBase64) > 4 * (int)ceil($maxSize / 3))
		{
			return $this->refuseTooLarge($maxSize);
		}
		$bytes = base64_decode($contentBase64, true);
		if ($bytes === false)
		{
			return $this->refuse(ReadinessService::ERROR_ENCODING_UNSUPPORTED, 'The text is not valid base64.');
		}
		if (strlen($bytes) > $maxSize)
		{
			return $this->refuseTooLarge($maxSize);
		}
		$previewMaxSize = Configuration::getMaxSizeForMarkdownPreview();
		if (strlen($bytes) > $previewMaxSize)
		{
			return $this->refuse(
				self::ERROR_TOO_LARGE,
				"Markdown text is too large to preview while editing (threshold {$previewMaxSize} bytes).",
			);
		}
		if (!mb_check_encoding($bytes, 'UTF-8'))
		{
			return $this->refuse(ReadinessService::ERROR_ENCODING_UNSUPPORTED, 'The text is not valid UTF-8.');
		}

		$rendered = $this->getRenderer()->render($bytes);
		if ($rendered === null)
		{
			return $this->refuse(
				MarkdownRenderService::ERROR_RENDERER_UNAVAILABLE,
				'Markdown renderer is unavailable.',
			);
		}

		return (new Result())->setData([
			'html' => (string)$rendered['html'],
			'diagrams' => array_values($rendered['diagrams']),
		]);
	}

	/**
	 * The budgets a call spends. The user of a guest logged in by a public link is the user shared by all guests,
	 * so a guest is told apart by the link and the address, never by the user or the session.
	 *
	 * @return list<PreviewBudget>|null null when there is nobody to charge
	 */
	private function buildBudgets(EditorIdentity $who, string $clientIp): ?array
	{
		$subjectLimits = [
			Configuration::getMarkdownPreviewRateLimitCalls(),
			Configuration::getMarkdownPreviewRateLimitBytes(),
		];

		if ($who->externalLink !== null)
		{
			$linkId = (int)$who->externalLink->getId();
			if ($linkId <= 0)
			{
				return null;
			}

			return [
				new PreviewBudget(PreviewRateLimiter::subjectForGuest($linkId, $clientIp), ...$subjectLimits),
				new PreviewBudget(
					PreviewRateLimiter::subjectForLink($linkId),
					Configuration::getMarkdownPreviewLinkRateLimitCalls(),
					Configuration::getMarkdownPreviewLinkRateLimitBytes(),
				),
			];
		}

		if ($who->userId <= 0)
		{
			return null;
		}

		return [new PreviewBudget(PreviewRateLimiter::subjectForUser($who->userId), ...$subjectLimits)];
	}

	private function refuseTooLarge(int $maxSize): Result
	{
		return $this->refuse(
			MarkdownRenderService::ERROR_SIZE_LIMIT,
			"Markdown text is too large to preview (limit {$maxSize} bytes).",
		);
	}

	private function refuse(string $code, string $message): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}

	private function getLimiter(): PreviewRateLimiter
	{
		return $this->limiter ?? ServiceLocator::getInstance()->get(self::RATE_LIMITER_SERVICE);
	}

	private function getRenderer(): MarkdownRenderService
	{
		return $this->renderer ?? new MarkdownRenderService();
	}
}
