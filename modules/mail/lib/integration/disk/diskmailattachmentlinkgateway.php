<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Disk;

use Bitrix\Disk\Public\Command\ExternalLink\CreateMailAttachmentLink\CanCreateMailAttachmentLinkCommand;
use Bitrix\Disk\Public\Command\ExternalLink\CreateMailAttachmentLink\CreateMailAttachmentLinkCommand;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Psr\Log\LoggerInterface;

/**
 * Gateway over the mail attachment link commands of the disk module.
 *
 * The diagnostics of a refusal stay in the log: the error of the disk side carries a message addressed to
 * its own interface and a custom payload, both of which the client of the mail module must never see. The
 * caller gets an error code of the mail module and nothing else.
 */
final class DiskMailAttachmentLinkGateway implements MailAttachmentLinkGateway
{
	/**
	 * The only refusal code of the disk contract the mail module tells apart. The literal is deliberately
	 * not taken from the enum of the disk module: the translation has to work without disk loaded.
	 */
	private const DISK_ERROR_FEATURE_NOT_AVAILABLE = 'FEATURE_NOT_AVAILABLE';

	/** The channel of the storage adapter: a refusal of the link belongs to the log of the upload. */
	private const LOGGER_ID = 'mail.large_attachment.storage';

	private ?LoggerInterface $logger = null;

	public function canCreate(): Result
	{
		if (!Loader::includeModule('disk'))
		{
			return self::diskUnavailable();
		}

		try
		{
			$commandResult = (new CanCreateMailAttachmentLinkCommand())->run();
		}
		catch (CommandException | CommandValidationException $exception)
		{
			$this->logException(CanCreateMailAttachmentLinkCommand::class, $exception);

			return self::mapCommandFailure(null);
		}

		if (!$commandResult->isSuccess())
		{
			$this->logRefusal(CanCreateMailAttachmentLinkCommand::class, $commandResult);

			return self::mapCommandFailure($commandResult);
		}

		$result = new Result();
		$result->setData(['available' => (bool)($commandResult->getData()['available'] ?? false)]);

		return $result;
	}

	public function create(int $userId, int $objectId): Result
	{
		if (!Loader::includeModule('disk'))
		{
			return self::diskUnavailable();
		}

		try
		{
			// neither a death time nor the system context is ever passed: the link of an attachment set
			// must not expire, and the call is always made on behalf of the sender themselves
			$commandResult = (new CreateMailAttachmentLinkCommand(
				userId: $userId,
				objectId: $objectId,
			))->run();
		}
		catch (CommandException | CommandValidationException $exception)
		{
			$this->logException(CreateMailAttachmentLinkCommand::class, $exception);

			return self::mapCommandFailure(null);
		}

		if (!$commandResult->isSuccess())
		{
			$this->logRefusal(CreateMailAttachmentLinkCommand::class, $commandResult);

			return self::mapCommandFailure($commandResult);
		}

		$data = $commandResult->getData();
		$externalLinkId = (int)($data['externalLinkId'] ?? 0);
		$url = (string)($data['url'] ?? '');
		if ($externalLinkId <= 0 || $url === '')
		{
			$this->getLogger()->error('A large attachment link command reported success without a link.', [
				'userId' => $userId,
				'objectId' => $objectId,
			]);

			$failure = self::uploadFailed();
			if ($externalLinkId > 0)
			{
				// the link itself does exist, and only the caller cleans up: without its id the link stays
				// behind as an unreferenced public address of a file
				$failure->setData(['externalLinkId' => $externalLinkId]);
			}

			return $failure;
		}

		$result = new Result();
		$result->setData([
			'externalLinkId' => $externalLinkId,
			'url' => $url,
		]);

		return $result;
	}

	/**
	 * Translates a refusal of a mail attachment link command into an error code of the mail module.
	 *
	 * Every code but the unavailable feature means one and the same failed upload for the sender. A null
	 * argument is the thrown command: there is no result to read from it.
	 */
	public function createsServiceLink(): bool
	{
		return true;
	}

	public static function mapCommandFailure(?Result $commandResult): Result
	{
		$isFeatureRefusal = $commandResult?->getErrorCollection()
			->getErrorByCode(self::DISK_ERROR_FEATURE_NOT_AVAILABLE) !== null;

		if ($isFeatureRefusal)
		{
			return self::error(
				'Creating a large attachment link is not available.',
				LargeAttachmentStorageInterface::ERROR_DISK_FEATURE_UNAVAILABLE,
			);
		}

		return self::uploadFailed();
	}

	private function logRefusal(string $command, Result $commandResult): void
	{
		$this->getLogger()->warning('A large attachment link command refused the call.', [
			'command' => $command,
			'refusal' => self::describeRefusal($commandResult),
		]);
	}

	private function logException(string $command, \Throwable $exception): void
	{
		$this->getLogger()->error('A large attachment link command has thrown.', [
			'command' => $command,
			'exception' => $exception,
		]);
	}

	/**
	 * The reason of a failed link creation lives in the custom data of the error and is the only
	 * diagnostics of such a refusal, so it must not be lost on the way to the log.
	 */
	private static function describeRefusal(Result $commandResult): string
	{
		$described = [];
		foreach ($commandResult->getErrors() as $error)
		{
			$customData = $error->getCustomData();
			$reason = is_array($customData) ? (array)($customData['reason'] ?? []) : [];

			$described[] = $reason === []
				? (string)$error->getCode()
				: (string)$error->getCode() . ': ' . implode('; ', $reason);
		}

		return implode(', ', $described);
	}

	private function getLogger(): LoggerInterface
	{
		if ($this->logger === null)
		{
			$this->logger = (new LoggerFactory())->createById(
				self::LOGGER_ID,
				[],
				false,
			);
		}

		return $this->logger;
	}

	private static function diskUnavailable(): Result
	{
		return self::error(
			'Large attachment disk API is unavailable.',
			LargeAttachmentStorageInterface::ERROR_DISK_UNAVAILABLE,
		);
	}

	private static function uploadFailed(): Result
	{
		return self::error(
			'Could not create a large attachment link.',
			RealLargeAttachmentStorage::ERROR_UPLOAD_FAILED,
		);
	}

	private static function error(string $message, string $code): Result
	{
		$result = new Result();

		return $result->addError(new Error($message, $code));
	}
}

