<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DocumentHeader;

use Bitrix\Disk\Document\Models;
use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Disk\Document\Vibeoffice\Clients;
use Bitrix\Disk\Document\Vibeoffice\HostDocumentKey;
use Bitrix\Disk\File;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\Ui;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Renaming of the object behind an opening (API-03), whichever editor it is opened in.
 *
 * The right to rename is the right on the object, not the kind of the session: a view session of a
 * user who may change the object renames it too. Telling the editor the new name is the only part
 * that branches by engine, and its failure does not undo the rename - the opening is answered with
 * `editorSynchronized: false` instead of an error.
 */
class RenameService
{
	public const ERROR_OBJECT_NOT_FOUND = 'OBJECT_NOT_FOUND';
	public const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';
	public const ERROR_INVALID_NAME = 'INVALID_NAME';
	public const ERROR_RENAME_FAILED = 'RENAME_FAILED';

	private ?Clients\ProtocolClientInterface $protocolClient = null;
	private \Closure $diagnosticHandler;

	/**
	 * @param Clients\ProtocolClientInterface|null $protocolClient injectable for tests; built from the
	 *        configuration of the platform on first use, because an opening of another engine needs none
	 * @param callable(string): void|null $diagnosticHandler internal diagnostic seam for tests
	 */
	public function __construct(
		?Clients\ProtocolClientInterface $protocolClient = null,
		?callable $diagnosticHandler = null,
	)
	{
		$this->protocolClient = $protocolClient;
		$this->diagnosticHandler = \Closure::fromCallable(
			$diagnosticHandler
			?? static fn(string $message) => \AddMessage2Log($message, 'disk')
		);
	}

	/**
	 * @param string $newName name without the extension: the extension is appended by the server
	 * @return Result data keys `name` and `editorSynchronized`
	 */
	public function rename(Models\DocumentSession $documentSession, string $newName, int $userId): Result
	{
		$result = new Result();

		$file = $documentSession->getObject();
		if (!$file)
		{
			return $result->addError(
				new Error('Document session has no object.', self::ERROR_OBJECT_NOT_FOUND)
			);
		}

		// A version is a frozen state of the object opened for reading; the object keeps its own name.
		if ($documentSession->isVersion())
		{
			return $result->addError(
				new Error('A version cannot be renamed.', self::ERROR_ACCESS_DENIED)
			);
		}

		$securityContext = $this->getSecurityContext($file, $userId);
		if (!$securityContext || !$documentSession->canRename($securityContext))
		{
			return $result->addError(
				new Error('Rename of the object is denied.', self::ERROR_ACCESS_DENIED)
			);
		}

		$name = $this->buildName($newName, $file);
		if ($name === null)
		{
			return $result->addError(
				new Error('The name does not pass the rules of the module.', self::ERROR_INVALID_NAME)
			);
		}

		$storedName = $this->renameObject($file, $name);
		if ($storedName === null)
		{
			return $result->addError(
				new Error('The object could not be renamed.', self::ERROR_RENAME_FAILED)
			);
		}

		return $result->setData([
			'name' => $storedName,
			'editorSynchronized' => $this->synchronizeEditor($documentSession, $storedName),
		]);
	}

	/**
	 * The extension is appended by the server alone: the user edits the name without it.
	 *
	 * @return string|null null when nothing acceptable to the module is left of the name
	 */
	private function buildName(string $newName, File $file): ?string
	{
		$extension = (string)$file->getExtension();
		$name = trim($newName);
		if ($extension !== '')
		{
			$name .= '.' . $extension;
		}

		$name = Ui\Text::correctFilename($name);

		return ($name === '' || $name === '.' . $extension) ? null : $name;
	}

	/**
	 * An editor that is unreachable, unconfigured or simply refuses is not a failure of the rename:
	 * the object already carries the new name, so every outcome here is answered with a flag. The
	 * reason still reaches the log - without it a portal whose platform route is missing shows nothing
	 * but a name that comes back on the next redraw.
	 */
	private function synchronizeEditor(Models\DocumentSession $documentSession, string $name): bool
	{
		try
		{
			return match ($documentSession->getService())
			{
				Models\DocumentService::OnlyOffice => $this->synchronizeWithOnlyOffice($documentSession, $name),
				Models\DocumentService::Vibeoffice => $this->synchronizeWithVibeoffice($documentSession, $name),
				Models\DocumentService::FlipChart => $this->synchronizeWithBoard(),
				// A session row of an engine that is gone names no editor to synchronize with.
				null => false,
			};
		}
		catch (\Throwable $exception)
		{
			$this->reportFailedSynchronization($documentSession, $exception->getMessage());

			return false;
		}
	}

	protected function synchronizeWithOnlyOffice(Models\DocumentSession $documentSession, string $name): bool
	{
		return OnlyOfficeHandler::renameDocument($documentSession->getExternalHash(), $name)->isSuccess();
	}

	/**
	 * The editor draws a name the person typed over its own field without taking it into its state,
	 * so any redraw of its header would bring the old one back. The `meta` command of the document
	 * server behind the platform is what replaces that state, and it reaches every co-author of the
	 * session at once, because they share the hash of the opening.
	 */
	protected function synchronizeWithVibeoffice(Models\DocumentSession $documentSession, string $name): bool
	{
		$this->protocolClient ??= Clients\ProtocolClientFactory::create();

		$result = $this->protocolClient->updateMeta(HostDocumentKey::resolve($documentSession), $name);
		if (!$result->isSuccess())
		{
			$this->reportFailedSynchronization($documentSession, implode('; ', $result->getErrorMessages()));

			return false;
		}

		return true;
	}

	private function reportFailedSynchronization(Models\DocumentSession $documentSession, string $reason): void
	{
		$service = $documentSession->getService()?->value ?? 'unknown';
		$message =
			'Rename synchronization with the editor failed: '
			. "service={$service}; EXTERNAL_HASH={$documentSession->getExternalHash()}; {$reason}"
		;

		try
		{
			($this->diagnosticHandler)($message);
		}
		catch (\Throwable)
		{
			// The rename is already stored: an unavailable log must not turn it into an error.
		}
	}

	/**
	 * The board platform stores no name of its own: a board is given it at opening time and its api
	 * (@see \Bitrix\Disk\Document\Flipchart\BoardApiService) has no rename either, so there is nothing
	 * to tell it from the server.
	 */
	protected function synchronizeWithBoard(): bool
	{
		return false;
	}

	protected function getSecurityContext(File $file, int $userId): ?SecurityContext
	{
		if ($userId <= 0)
		{
			return null;
		}

		return $file->getStorage()?->getSecurityContext($userId);
	}

	/**
	 * The name asked for is not always the name stored: an occupied name is made unique by the module.
	 *
	 * @return string|null the name the object carries afterwards, null when it was not renamed
	 */
	protected function renameObject(File $file, string $name): ?string
	{
		return $file->rename($name, true) ? (string)$file->getName() : null;
	}
}
