<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Configuration;
use Bitrix\Disk\File;
use Bitrix\Disk\Version;
use Bitrix\Main\Application;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;

/**
 * Serves an html file to the viewer as an isolated document.
 *
 * The content is streamed byte-for-byte, without sanitization: isolation is provided by the response
 * headers (a sandbox Content-Security-Policy) and the sandboxed iframe on the client. This is a
 * deliberate departure from the markdown path, which sanitizes via CBXSanitizer.
 *
 * What is served is kept in step with what the viewer opens: HtmlViewerPolicy decides both, so the
 * endpoint never hands out a non-html file as text/html.
 *
 * Every refusal the service decides itself (viewer disabled, file outside the policy, size limit,
 * unreadable content) is answered with a localized stub page carrying the same isolating headers, so the
 * iframe renders the reason directly. A caller that refuses before the service is reached (an action
 * filter, an unresolvable id, the external link component) answers on its own and is offered the same
 * stub by unavailableResponse().
 */
class HtmlViewerService
{
	// Google Fonts is the only external origin a document may reach, split across the two directives it
	// needs. Every other network channel is closed — no script host, no image host, connect-src 'none' —
	// so a script inside the document has nothing to carry content out with. Script hosts are absent by
	// design: an external script would restore arbitrary code execution and defeat the model (ADR § 6).
	private const STYLE_HOSTS = [
		'https://fonts.googleapis.com',
	];

	private const FONT_HOSTS = [
		'https://fonts.gstatic.com',
	];

	// The charset of the stub pages, which are built here, and the fallback for a document that declares
	// none. The lookup window matches the browser prescan: a document declaring its charset further down
	// is not read as such by the browser either.
	private const DEFAULT_CHARSET = 'utf-8';
	private const CHARSET_LOOKUP_LENGTH = 1024;

	private const ERROR_STYLE = <<<CSS
		body {
			margin: 0;
			padding: 24px;
			box-sizing: border-box;
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			font: 14px/1.6 -apple-system, BlinkMacSystemFont, "Helvetica Neue", Arial, sans-serif;
			color: #535c69;
		}
		.disk-html-viewer-error { max-width: 480px; text-align: center; }
		CSS;

	public function showByFile(File $file): HttpResponse
	{
		$refusal = $this->refuseUnlessViewable($file);

		return $refusal ?? $this->showByBlob((int)$file->getFileId(), (int)$file->getSize());
	}

	public function showByVersion(Version $version): HttpResponse
	{
		// A version carries the name it was saved under, so the policy is asked about that name: no
		// object is loaded, and a later rename of the file cannot change what the version serves.
		$refusal = $this->refuseUnlessViewableExtension($version->getExtension());

		return $refusal ?? $this->showByBlob((int)$version->getFileId(), (int)$version->getSize());
	}

	public function showByAttachedObject(AttachedObject $attached): HttpResponse
	{
		// A version-pinned attach serves that revision, otherwise the current file.
		if ($attached->isSpecificVersion())
		{
			$version = $attached->getVersion();
			if ($version === null)
			{
				return $this->errorResponse(404, 'DISK_HTML_VIEWER_ERROR_UNAVAILABLE');
			}

			return $this->showByVersion($version);
		}

		$file = $attached->getFile();
		if ($file === null)
		{
			return $this->errorResponse(404, 'DISK_HTML_VIEWER_ERROR_UNAVAILABLE');
		}

		return $this->showByFile($file);
	}

	/**
	 * Guards the file entry point before any content is read, so the endpoint answers only for files the
	 * viewer itself would open. The option is answered first with its own DISABLED code, and the rest of
	 * HtmlViewerPolicy::isViewable() — the name together with the stored type — answers for the file: a
	 * document renamed to .html is refused here instead of leaving the endpoint as text/html.
	 */
	private function refuseUnlessViewable(File $file): ?HttpResponse
	{
		$refusal = $this->refuseUnlessEnabled();
		if ($refusal !== null)
		{
			return $refusal;
		}

		if (!HtmlViewerPolicy::isViewableFile($file))
		{
			return $this->errorResponse(404, 'DISK_HTML_VIEWER_ERROR_UNAVAILABLE');
		}

		return null;
	}

	/**
	 * The same guard for a version: it carries the name it was saved under and no stored type of its own,
	 * so that name is the whole answer.
	 */
	private function refuseUnlessViewableExtension(?string $extension): ?HttpResponse
	{
		$refusal = $this->refuseUnlessEnabled();
		if ($refusal !== null)
		{
			return $refusal;
		}

		if (!HtmlViewerPolicy::isViewableExtension($extension))
		{
			return $this->errorResponse(404, 'DISK_HTML_VIEWER_ERROR_UNAVAILABLE');
		}

		return null;
	}

	private function refuseUnlessEnabled(): ?HttpResponse
	{
		return Configuration::isEnabledHtmlViewer() ? null : $this->errorResponse(404, 'DISK_HTML_VIEWER_ERROR_DISABLED');
	}

	private function showByBlob(int $fileId, int $size): HttpResponse
	{
		if ($size > Configuration::getMaxSizeForHtmlViewer())
		{
			return $this->errorResponse(413, 'DISK_HTML_VIEWER_ERROR_TOO_LARGE');
		}

		$content = $this->readContent($fileId);
		if ($content === null)
		{
			// A missing blob is a storage fault, not a refusal, yet it answers the same stub with the same
			// status as the configuration ones: the log is the only place the three can be told apart.
			Application::getInstance()->getExceptionHandler()->writeToLog(
				new SystemException("Html viewer source blob #{$fileId} could not be read from storage."),
			);

			return $this->errorResponse(404, 'DISK_HTML_VIEWER_ERROR_UNAVAILABLE');
		}

		return $this->htmlResponse($content);
	}

	/**
	 * Builds the success response: the raw html served as an isolated document (headers per API-01).
	 * The content is emitted unchanged and is served under the charset it declares about itself.
	 */
	public function htmlResponse(string $content): HttpResponse
	{
		return $this->buildResponse($content, '200 OK', $this->detectCharset($content));
	}

	/**
	 * The same stub for a caller that refuses before the service is reached (an action filter closing the
	 * endpoint): the viewer loads the content into a plain iframe, so the reason has to be a document.
	 * The status stays 404 as for any other refusal, so a refused version tells nothing about its target.
	 */
	public function unavailableResponse(): HttpResponse
	{
		return $this->errorResponse(404, 'DISK_HTML_VIEWER_ERROR_UNAVAILABLE');
	}

	private function errorResponse(int $status, string $phraseCode): HttpResponse
	{
		$page = $this->buildErrorPage($phraseCode);

		return $this->buildResponse($page, $this->statusLine($status), self::DEFAULT_CHARSET);
	}

	private function buildResponse(string $content, string $status, string $charset): HttpResponse
	{
		$response = new HttpResponse();
		$response->setStatus($status);
		$response->setContent($content);

		// The charset is always named: PHP appends default_charset to any text/* header carrying none,
		// and the header outranks the document's own meta, so silence would force utf-8 on the document.
		$response->addHeader('Content-Type', 'text/html; charset=' . $charset);
		$response->addHeader('Content-Security-Policy', $this->buildContentSecurityPolicy());
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->addHeader('Referrer-Policy', 'no-referrer');
		$response->addHeader('Content-Disposition', 'inline');
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}

	/**
	 * The charset the document declares in its head, in either meta form, falling back to utf-8 when it
	 * declares none. The pattern accepts label characters only, so nothing from the document can break
	 * out of the header value. A byte-order mark, if any, outranks the header in the browser anyway.
	 */
	private function detectCharset(string $content): string
	{
		$head = substr($content, 0, self::CHARSET_LOOKUP_LENGTH);
		if (preg_match('/<meta[^>]+charset\s*=\s*[\'"]?\s*([a-z0-9][a-z0-9_.:-]*)/i', $head, $match) === 1)
		{
			return $match[1];
		}

		return self::DEFAULT_CHARSET;
	}

	/**
	 * Builds the sandbox Content-Security-Policy served on every response (success and error stubs alike).
	 * Portal-configured hosts widen only the passive resource groups (style/font/img); script-src stays
	 * inline-only so no external code executes.
	 */
	private function buildContentSecurityPolicy(): string
	{
		$extraHosts = Configuration::getHtmlViewerAllowedHosts();

		return implode('; ', [
			'sandbox allow-scripts',
			"default-src 'none'",
			"script-src 'unsafe-inline'",
			$this->passiveDirective('style-src', ["'unsafe-inline'", ...self::STYLE_HOSTS], $extraHosts),
			$this->passiveDirective('font-src', ['data:', ...self::FONT_HOSTS], $extraHosts),
			$this->passiveDirective('img-src', ['data:', 'blob:'], $extraHosts),
			'media-src data: blob:',
			"connect-src 'none'",
			"worker-src 'none'",
			"frame-src 'none'",
			"object-src 'none'",
			"form-action 'none'",
			"base-uri 'none'",
			"frame-ancestors 'self'",
			"webrtc 'block'",
		]);
	}

	private function passiveDirective(string $name, array $sources, array $extraHosts): string
	{
		return implode(' ', [$name, ...$sources, ...$extraHosts]);
	}

	private function buildErrorPage(string $phraseCode): string
	{
		$lang = htmlspecialcharsbx(LANGUAGE_ID);
		$message = htmlspecialcharsbx((string)Loc::getMessage($phraseCode));

		return '<!DOCTYPE html>'
			. '<html lang="' . $lang . '"><head>'
			. '<meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<style>' . self::ERROR_STYLE . '</style>'
			. '</head><body>'
			. '<div class="disk-html-viewer-error">' . $message . '</div>'
			. '</body></html>'
		;
	}

	private function statusLine(int $status): string
	{
		$reason = match ($status) {
			404 => 'Not Found',
			413 => 'Payload Too Large',
			default => '',
		};

		return trim($status . ' ' . $reason);
	}

	private function readContent(int $fileId): ?string
	{
		if ($fileId <= 0)
		{
			return null;
		}

		$fileArray = \CFile::makeFileArray($fileId);
		if (empty($fileArray['tmp_name']) || !is_file($fileArray['tmp_name']))
		{
			return null;
		}

		$content = file_get_contents($fileArray['tmp_name']);

		return $content === false ? null : $content;
	}
}
