<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Rest;

use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Integration\AiAssistant\Dto\PutMarkupFileDto;
use Bitrix\Landing\Integration\AiAssistant\Exception\AiSiteModeDisabledException;
use Bitrix\Landing\Integration\AiAssistant\Exception\MarkupRequestRefusedException;
use Bitrix\Landing\Integration\AiAssistant\Log;
use Bitrix\Landing\Integration\AiAssistant\Service\MarkupRequestAuthorizer;
use Bitrix\Landing\Integration\AiAssistant\UseCase\PutMarkupFileHandler;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Markup\UploadBuffer;
use Bitrix\Landing\Markup\Validator;
use Bitrix\Landing\Site\Type;

/**
 * Intake of one whole markup package file over REST for the Marta bridge: the file comes in one request
 * as base64 instead of model-sized parts of put_markup_file. The caller, the availability and the target
 * rights are answered by the authorizer the tool shares, the file goes to the same handler, the answer is
 * the answer of the tool. A new buffer is opened by the binding of the page context in both modes.
 *
 * Only whole content is taken: parts, a Disk file and the inventory call stay with the tool.
 * The form of the body is checked before the target and the handler: the base64 length bound, the strict
 * decoding and the sha256 of the bytes the caller computed. Every refusal is a MarkupRequestRefusedException
 * with a string code, a failed answer of the handler included; the buffer stays as it was.
 */
class PutMarkupFileRestAction
{
	public const SIGNED_USER_AUTH_TYPE = MarkupRequestAuthorizer::SIGNED_USER_AUTH_TYPE;
	private const METHOD = 'landing.markup.putfile';

	private const ACCEPTED_FIELDS = [
		'uploadId',
		'mode',
		AiSiteChatBindingContract::TOOL_BINDING_ID_FIELD,
		'path',
		'content',
	];
	private const OPENING_MODES = [UploadBuffer::MODE_CREATE, UploadBuffer::MODE_UPDATE];
	private const ERROR_FILE_TOO_BIG = 'MARKUP_FILE_TOO_BIG';
	private const LOG_MASKED_FIELDS = ['content'];

	/**
	 * @throws MarkupRequestRefusedException
	 */
	public function execute(array $fields): array
	{
		$userId = $this->getUserId();
		if (!$this->isLogEnabled())
		{
			return $this->accept($fields, $userId);
		}

		$log = $this->createLog();
		$log->addToolCall(self::METHOD, static::class, $userId, $userId, $this->maskLogFields($fields));
		try
		{
			$answer = $this->accept($fields, $userId);
		}
		catch (MarkupRequestRefusedException $exception)
		{
			$log->addToolResult(self::METHOD, static::class, $userId, $userId, 'failed', [$exception->getErrorCode()]);

			throw $exception;
		}
		catch (\Throwable $exception)
		{
			$log->addToolResult(self::METHOD, static::class, $userId, $userId, 'exception', [$exception->getMessage()]);

			throw $exception;
		}
		$log->addToolResult(self::METHOD, static::class, $userId, $userId, 'ok');

		return $answer;
	}

	protected function getRestAuthType(): ?string
	{
		// on the AJAX path of the public actions there is no REST server at all
		if (!class_exists(\CRestServer::class))
		{
			return null;
		}

		return \CRestServer::instance()?->getAuthType();
	}

	protected function leaveSiteScope(): void
	{
		Type::clearScope();
	}

	protected function createAuthorizer(): MarkupRequestAuthorizer
	{
		return new MarkupRequestAuthorizer();
	}

	protected function createHandler(): PutMarkupFileHandler
	{
		return new PutMarkupFileHandler();
	}

	protected function getUserId(): int
	{
		return Manager::getUserId();
	}

	protected function isLogEnabled(): bool
	{
		return Log::isEnabled();
	}

	protected function createLog(): Log
	{
		return new Log();
	}

	/**
	 * @throws MarkupRequestRefusedException
	 */
	private function accept(array $fields, int $userId): array
	{
		$authorizer = $this->createAuthorizer();
		$authorizer->assertBridgeCaller($this->getRestAuthType());

		// the REST gateway enters the site scope named in the request (a KNOWLEDGE or VIBE section),
		// and the create/edit rights are answered for that section: a scope with its own roles could
		// let a file into the buffer of a page the user may not edit. The tool runs with no scope,
		// so the target rights here are checked with no scope as well.
		$this->leaveSiteScope();
		$authorizer->assertMarkupAvailable();

		$dto = $this->buildDto($fields);
		$this->assertWholeFile($dto->content, $fields['sha256']);
		$handler = $this->createHandler();

		try
		{
			$authorizer->assertPutTargetPermitted($handler->resolveTarget($dto, $userId), $userId);
			$answer = $handler->handle($dto, $userId);
		}
		catch (AiSiteModeDisabledException $exception)
		{
			// the handler asks the selection again right before the first saved effect
			throw new MarkupRequestRefusedException(
				$exception->getMessage(),
				MarkupRequestRefusedException::MODE_DISABLED,
				$exception,
			);
		}
		catch (\InvalidArgumentException $exception)
		{
			throw new MarkupRequestRefusedException(
				$exception->getMessage(),
				MarkupRequestRefusedException::BAD_REQUEST,
				$exception,
			);
		}

		if (($answer['status'] ?? null) === 'failed')
		{
			throw $this->createHandlerRefusal($answer);
		}

		return $answer;
	}

	private function buildDto(array $fields): PutMarkupFileDto
	{
		$props = array_intersect_key($fields, array_flip(self::ACCEPTED_FIELDS));
		$props['encoding'] = UploadBuffer::ENCODING_BASE64;
		$dto = PutMarkupFileDto::fromArray($props);

		if ($dto->path === '' || !is_string($fields['content'] ?? null))
		{
			throw $this->createBadRequest('path and base64 content of the whole file are required.');
		}

		$sha256 = $fields['sha256'] ?? null;
		if (!is_string($sha256) || preg_match('/^[0-9a-f]{64}$/iD', $sha256) !== 1)
		{
			throw $this->createBadRequest('sha256 of the whole file (64 hex digits) is required.');
		}

		if ($dto->uploadId === '' && (!in_array($dto->mode, self::OPENING_MODES, true) || $dto->bindingId <= 0))
		{
			throw $this->createBadRequest(
				'mode ("create" or "update") with bindingId is required to open a new upload buffer.',
			);
		}

		return $dto;
	}

	/**
	 * The length bound comes before decoding: an oversized body is refused without allocating its bytes.
	 *
	 * @throws MarkupRequestRefusedException
	 */
	private function assertWholeFile(string $content, string $sha256): void
	{
		if (strlen($content) > 4 * (int)ceil(Validator::LIMIT_FILE_SIZE / 3))
		{
			throw new MarkupRequestRefusedException(
				'The file exceeds the single file size limit.',
				self::ERROR_FILE_TOO_BIG,
			);
		}

		$bytes = base64_decode($content, true);
		if ($bytes === false)
		{
			throw new MarkupRequestRefusedException('Content is not valid base64.', UploadBuffer::ERROR_BASE64_INVALID);
		}

		if (!hash_equals(strtolower($sha256), hash('sha256', $bytes)))
		{
			throw new MarkupRequestRefusedException(
				'sha256 of the decoded content does not match the passed sha256. Nothing was stored.',
				MarkupRequestRefusedException::HASH_MISMATCH,
			);
		}
	}

	private function createHandlerRefusal(array $answer): MarkupRequestRefusedException
	{
		$error = $answer['errors'][0] ?? [];
		$details = (string)($error['details'] ?? '');
		$answerMessage = (string)($answer['message'] ?? 'The file was rejected.');
		$message = $details !== '' ? $details : $answerMessage;
		$code = (string)($error['code'] ?? MarkupRequestRefusedException::BAD_REQUEST);

		return new MarkupRequestRefusedException($message, $code);
	}

	private function maskLogFields(array $fields): array
	{
		foreach (self::LOG_MASKED_FIELDS as $name)
		{
			if (isset($fields[$name]) && is_string($fields[$name]))
			{
				$fields[$name] = '[masked ' . strlen($fields[$name]) . ' bytes]';
			}
		}

		return $fields;
	}

	private function createBadRequest(string $message): MarkupRequestRefusedException
	{
		return new MarkupRequestRefusedException($message, MarkupRequestRefusedException::BAD_REQUEST);
	}
}
