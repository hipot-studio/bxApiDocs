<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Disk\Controller\McpFileDownload;
use Bitrix\Disk\Integration\AiAssistant\Exception\DownloadFileMcpException;
use Bitrix\Disk\Integration\AiAssistant\Service\FileDownloadService;

final class DownloadFileTool extends BaseDiskTool
{
	private readonly FileDownloadService $fileDownloadService;
	private readonly ?\Closure $downloadUrlBuilder;

	public function __construct(
		TracedLogger $tracedLogger,
		?FileDownloadService $fileDownloadService = null,
		?\Closure $downloadUrlBuilder = null,
	)
	{
		parent::__construct($tracedLogger);
		$this->fileDownloadService = $fileDownloadService ?? new FileDownloadService();
		$this->downloadUrlBuilder = $downloadUrlBuilder;
	}

	public function getName(): string
	{
		return 'download_file';
	}

	public function getDescription(): string
	{
		return 'Returns metadata and a short-lived URL for downloading the current content of one exact Disk file.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'fileId' => ['type' => 'integer', 'minimum' => 1],
			],
			'required' => ['fileId'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;
		$result = $this->fileDownloadService->issue(
			$userId,
			$this->intArg($args, 'fileId') ?? 0,
		);
		if (!$result->isSuccess())
		{
			$error = $result->getError();
			$code = (string)($error?->getCode() ?? 'DOWNLOAD_UNAVAILABLE');
			$message = (string)($error?->getMessage() ?? 'The file is unavailable for download.');
			if ($code === FileDownloadService::ERROR_DOWNLOAD_TOO_LARGE)
			{
				throw new DownloadFileMcpException($code);
			}

			throw new McpException($message);
		}

		$data = $result->getData();

		return [
			'id' => (int)$data['id'],
			'name' => (string)$data['name'],
			'size' => (int)$data['size'],
			'contentType' => (string)$data['contentType'],
			'downloadUrl' => $this->buildDownloadUrl((string)$data['token']),
			'expiresAt' => (int)$data['expiresAt'],
			'resultMeta' => [
				'status' => 'success',
				'hasMore' => false,
				'isComplete' => true,
				'partial' => false,
				'nextCursor' => null,
				'limitations' => [],
				'errors' => [],
			],
		];
	}

	private function buildDownloadUrl(string $token): string
	{
		if ($this->downloadUrlBuilder !== null)
		{
			return (string)($this->downloadUrlBuilder)($token);
		}

		return (string)(new McpFileDownload())->getActionUri(
			'download',
			['token' => $token],
			absolute: true,
		);
	}
}
