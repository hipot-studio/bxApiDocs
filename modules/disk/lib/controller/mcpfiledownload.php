<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller;

use Bitrix\Disk\Integration\AiAssistant\Service\FileDownloadService;
use Bitrix\Disk\Internals\Engine\Controller;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Response;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Request;
use Bitrix\Main\Web\HttpHeaders;

final class McpFileDownload extends Controller
{
	private const CACHE_CONTROL = 'private, no-store, no-cache, must-revalidate, max-age=0';
	private readonly FileDownloadService $fileDownloadService;

	public function __construct(?Request $request = null, ?FileDownloadService $fileDownloadService = null)
	{
		parent::__construct($request);
		$this->fileDownloadService = $fileDownloadService ?? new FileDownloadService();
	}

	public function configureActions(): array
	{
		return [
			'download' => [
				'prefilters' => [
					new CloseSession(),
					new HttpMethod([HttpMethod::METHOD_GET]),
				],
			],
		];
	}

	public function downloadAction(): HttpResponse
	{
		$token = $this->getRequest()->getQuery('token');
		if (!is_string($token))
		{
			return $this->createNotFoundResponse();
		}

		$result = $this->fileDownloadService->open($token);
		if (!$result->isSuccess())
		{
			return $this->createNotFoundResponse();
		}

		$data = $result->getData();
		$fileData = $data['fileData'];
		$fileData['CONTENT_TYPE'] = $data['contentType'];

		$response = Response\BFile::createByFileData($fileData, $data['name']);
		$response
			->setContentType($data['contentType'])
			->showInline(false)
			->setCacheTime(0)
		;

		$headers = new class(self::CACHE_CONTROL) extends HttpHeaders {
			public function __construct(private readonly string $cacheControl)
			{
				parent::__construct();
			}

			public function add($name, $value): void
			{
				if (strcasecmp((string)$name, 'Cache-Control') === 0)
				{
					$this->delete($name);
					parent::add($name, $this->cacheControl);

					return;
				}

				parent::add($name, $value);
			}
		};
		$response->setHeaders($headers);
		$response->addHeader('Cache-Control', self::CACHE_CONTROL);

		return $response;
	}

	protected function processAfterAction(Action $action, mixed $result): mixed
	{
		if ($this->errorCollection->getErrorByCode(HttpMethod::ERROR_INVALID_HTTP_METHOD))
		{
			$response = new HttpResponse();
			$response->setStatus('405 Method Not Allowed');
			$response->addHeader('Allow', HttpMethod::METHOD_GET);

			return $response;
		}

		return parent::processAfterAction($action, $result);
	}

	private function createNotFoundResponse(): HttpResponse
	{
		$response = new HttpResponse();
		$response->setStatus('404 Not Found');

		return $response;
	}
}
