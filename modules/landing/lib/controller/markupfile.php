<?php

namespace Bitrix\Landing\Controller;

use Bitrix\Landing\Markup\Delivery\FileAccess;
use Bitrix\Landing\Markup\Delivery\FileBody;
use Bitrix\Landing\Markup\Delivery\LinkRewriter;
use Bitrix\Landing\Markup\Delivery\RefusalJournalLimit;
use Bitrix\Landing\Markup\MarkupLifecycleLog;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Web\Http\Etag;
use Bitrix\Main\Web\Http\Range;
use Bitrix\Main\Web\Uri;

/**
 * Serves the files of a markup package by signed links (API-12):
 * GET landing.markupfile.get?b={blockId}&f={fileId}&e={expiresAt}&s={signature}.
 *
 * The frame of a custom block has an opaque origin and sends no portal cookies: there is no
 * authorization and no csrf check, the signature of the link is the access. Serving does not
 * depend on the markup option: turning it off must not break published sites.
 *
 * The answer is built here, not by CFile::viewByUser(), which makes every answer private:
 * the content type comes from the manifest, binary files of published links are immutable and
 * cached for a year. Css and js are served with the links substituted: an unchanged text keeps
 * its file id and link across versions while the links inside change, so a text is cached
 * briefly and revalidated by its entity tag.
 */
class MarkupFile extends Controller
{
	private const CACHE_IMMUTABLE = 'public, max-age=31536000, immutable';
	private const CACHE_TEXT_PUBLIC = 'public, max-age=300, must-revalidate';
	private const CACHE_TEXT_DRAFT = 'private, no-cache';

	protected function getDefaultPreFilters(): array
	{
		return [
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_GET]),
		];
	}

	/**
	 * @param int $b Block id.
	 * @param int $f File id.
	 * @param int $e Expiry of a draft link, 0 for a published one.
	 * @param string $s Signature.
	 */
	public function getAction(int $b, int $f, int $e = 0, string $s = ''): HttpResponse
	{
		$expiresAt = $e > 0 ? $e : null;
		$access = $this->createFileAccess()->resolve($b, $f, $expiresAt, $s);
		if (!$access->isSuccess())
		{
			$code = (string)$access->getErrors()[0]->getCode();
			$this->writeRefusal($code, $b, $f);

			return $this->createResponse($code === FileAccess::ERROR_BAD_SIGNATURE ? 403 : 404);
		}

		$data = $access->getData();

		if ($data['isText'])
		{
			$text = $this->createLinkRewriter()->getFileText($data['manifest'], $b, $f, $data['isPublic'], $expiresAt);
			if ($text === null)
			{
				return $this->createResponse(404);
			}

			return $this->createTextResponse(
				$text,
				$data['type'],
				$data['isPublic'] ? self::CACHE_TEXT_PUBLIC : self::CACHE_TEXT_DRAFT
			);
		}

		$cacheControl = $data['isPublic']
			? self::CACHE_IMMUTABLE
			: 'private, max-age=' . max(0, $expiresAt - time());

		return $this->createBinaryResponse($f, $data['type'], (string)$data['file']['hash'], $cacheControl);
	}

	/**
	 * Whether the web server takes the file body from the answer header (nginx X-Accel-Redirect).
	 */
	protected function isFastDownload(): bool
	{
		return Option::get('main', 'bx_fast_download', 'N') === 'Y';
	}

	protected function createFileAccess(): FileAccess
	{
		return new FileAccess();
	}

	protected function createLinkRewriter(): LinkRewriter
	{
		return new LinkRewriter();
	}

	protected function createLifecycleLog(): MarkupLifecycleLog
	{
		return new MarkupLifecycleLog();
	}

	protected function createRefusalJournalLimit(): RefusalJournalLimit
	{
		return new RefusalJournalLimit();
	}

	private function writeRefusal(string $reason, int $blockId, int $fileId): void
	{
		$extraFields = $this->createRefusalJournalLimit()->take($reason);
		if ($extraFields === null)
		{
			return;
		}

		$this->createLifecycleLog()->write(MarkupLifecycleLog::EVENT_CONTROLLER_REFUSAL, [
			'reason' => $reason,
			'blockId' => $blockId,
			'fileId' => $fileId,
		] + $extraFields);
	}

	private function createTextResponse(string $text, string $type, string $cacheControl): HttpResponse
	{
		$etag = Etag::quote(md5($text));
		if ($this->isNotModified($etag))
		{
			return $this->createResponse(304)
				->addHeader('ETag', $etag)
				->addHeader('Cache-Control', $cacheControl)
			;
		}

		return $this->createResponse(200)
			->addHeader('Content-Type', $type . '; charset=utf-8')
			->addHeader('Cache-Control', $cacheControl)
			->addHeader('ETag', $etag)
			->setContent($text)
		;
	}

	private function createBinaryResponse(int $fileId, string $type, string $hash, string $cacheControl): HttpResponse
	{
		$etag = $hash !== '' ? Etag::quote($hash) : '';
		if ($etag !== '' && $this->isNotModified($etag))
		{
			return $this->createResponse(304)
				->addHeader('ETag', $etag)
				->addHeader('Cache-Control', $cacheControl)
			;
		}

		if ($this->isFastDownload())
		{
			$redirect = $this->getAccelRedirect($fileId);
			if ($redirect !== null)
			{
				$response = $this->createResponse(200)
					->addHeader('Content-Type', $type)
					->addHeader('Cache-Control', $cacheControl)
					->addHeader('X-Accel-Redirect', $redirect)
				;

				return $etag !== '' ? $response->addHeader('ETag', $etag) : $response;
			}
		}

		$body = FileBody::open($fileId);
		if ($body === null)
		{
			return $this->createResponse(404);
		}

		$size = $body->getSize();
		$rangeHeader = $this->getServerValue('HTTP_RANGE');
		$range = null;
		if ($rangeHeader !== '')
		{
			$range = $this->parseRange($rangeHeader, $size);
			if ($range === null)
			{
				return $this->createResponse(416)->addHeader('Content-Range', 'bytes */' . $size);
			}
		}

		$start = $range ? $range->getStart() : 0;
		$length = $range ? $range->getEnd() - $range->getStart() + 1 : $size;
		$content = $body->read($start, $length);
		if ($content === null)
		{
			return $this->createResponse(404);
		}

		$response = $this->createResponse(200)
			->addHeader('Content-Type', $type)
			->addHeader('Cache-Control', $cacheControl)
			->addHeader('Accept-Ranges', 'bytes')
		;
		if ($etag !== '')
		{
			$response->addHeader('ETag', $etag);
		}
		if ($range)
		{
			$response
				->setStatus('206 Partial Content')
				->addHeader('Content-Range', 'bytes ' . $range->getStart() . '-' . $range->getEnd() . '/' . $size)
			;
		}

		return $response->setContent($content);
	}

	/**
	 * The single range of the request; null when it cannot be satisfied.
	 * A suffix longer than the file selects the whole file (RFC 9110, 14.1.2): the core range
	 * does not clamp it, so it is passed as the range from the file start.
	 */
	private function parseRange(string $header, int $size): ?Range
	{
		if ($size <= 0)
		{
			return null;
		}

		if (preg_match('/^\s*bytes\s*=\s*-\s*(\d+)\s*$/i', $header, $matches) && (int)$matches[1] > $size)
		{
			$header = 'bytes=0-';
		}

		$ranges = Range::createFromString($header, $size);

		return $ranges !== null && count($ranges) === 1 ? $ranges[0] : null;
	}

	private function isNotModified(string $etag): bool
	{
		$ifNoneMatch = $this->getServerValue('HTTP_IF_NONE_MATCH');

		return $ifNoneMatch !== '' && Etag::matches($ifNoneMatch, $etag);
	}

	/**
	 * Internal location of the file for nginx, the same as CFile::viewByUser() builds it.
	 */
	private function getAccelRedirect(int $fileId): ?string
	{
		$file = \CFile::getFileArray($fileId);
		if (!is_array($file) || empty($file['SRC']))
		{
			return null;
		}

		$src = Uri::urnEncode((string)$file['SRC']);
		if ((int)($file['HANDLER_ID'] ?? 0) > 0)
		{
			$src = preg_replace('~^(https?)(://)~i', '\\1.', $src);
			$cloudUploadPath = Option::get('main', 'bx_cloud_upload', '/upload/bx_cloud_upload/');

			return rawurlencode($cloudUploadPath . $src);
		}

		return $src;
	}

	private function createResponse(int $status): HttpResponse
	{
		$response = new HttpResponse();
		$response
			->setStatus($status)
			->setContent('')
			->addHeader('X-Content-Type-Options', 'nosniff')
			->addHeader('Access-Control-Allow-Origin', '*')
		;
		if ($status >= 400)
		{
			$response->addHeader('Cache-Control', 'no-store');
		}

		return $response;
	}

	private function getServerValue(string $name): string
	{
		return trim((string)$this->getRequest()->getServer()->get($name));
	}
}
