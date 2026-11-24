<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\File;

use Bitrix\Main\Engine\Response;

/**
 * Sends a file response with the body written by PHP itself, never handed over to the web server.
 *
 * A web server that takes the body over keeps only a narrow set of the headers of the backend, and
 * Access-Control-Allow-Origin is not among them. The wrapped response keeps preparing the file and the
 * options of the transfer, so the name, the type, the cache time and the sizes of a resized image stay
 * where its builder put them. The only thing overridden is the fast download.
 */
final class PhpStreamedFileResponse extends Response\File
{
	public function __construct(private readonly Response\File $response)
	{
		// the name goes as a string: on null the parent takes it from the path, and there is no path here
		parent::__construct(null, (string)$response->getName(), $response->getContentType());
	}

	public function getResponse(): Response\File
	{
		return $this->response;
	}

	public function getName()
	{
		return $this->response->getName();
	}

	public function getContentType()
	{
		return $this->response->getContentType();
	}

	public function getCacheTime()
	{
		return $this->response->getCacheTime();
	}

	public function isShowInline()
	{
		return $this->response->isShowInline();
	}

	protected function prepareFile()
	{
		return $this->response->prepareFile();
	}

	/**
	 * Merged, not added: the wrapped response may carry fast_download of its own, and it has to lose.
	 */
	protected function prepareOptions(): array
	{
		return array_merge($this->response->prepareOptions(), ['fast_download' => false]);
	}
}
