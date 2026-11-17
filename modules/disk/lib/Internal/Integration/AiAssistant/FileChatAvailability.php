<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Integration\AiAssistant;

use Bitrix\AiAssistant\Config\Feature;
use Bitrix\AiAssistant\Service\GuardService;
use Bitrix\AiAssistant\Trigger\Repository\TriggerRepository;
use Bitrix\AiAssistant\Trigger\Service\Exception\AccessDeniedException;
use Bitrix\AiAssistant\Trigger\Service\Exception\InvalidRequestException;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Config\Feature\BitrixGptFileChatFlag;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Integration\AiAssistant\Trigger\Action\FileChatTrigger;
use Bitrix\Disk\Internal\Integration\AiAssistant\Trigger\FileChatTriggerCache;
use Bitrix\Disk\Internal\Integration\AiAssistant\Trigger\FileChatTriggerRegistrar;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Feature as MainFeature;
use Bitrix\Main\Config\Feature\Context;
use Bitrix\Main\Diag\ExceptionHandler;
use Bitrix\Main\Loader;

class FileChatAvailability
{
	private const MAX_FILE_SIZE = 50 * 1024 * 1024;

	/** The formats the assistant has a reader for; matched in lower case. */
	private const SUPPORTED_EXTENSIONS = [
		'txt', 'md', 'csv',
		'docx', 'doc', 'rtf', 'odt', 'pdf',
		'xlsx', 'xls', 'ods',
		'pptx', 'ppt', 'odp',
		'html', 'htm',
	];

	private bool $registrationAttempted = false;
	private ?bool $registrationRemembered = null;
	private array $menuTriggerAccess = [];
	private readonly ExceptionHandler $exceptionHandler;
	private readonly FileChatTriggerCache $triggerCache;

	public function __construct(
		private readonly Feature $feature,
		private readonly GuardService $guardService,
		private readonly TriggerRepository $triggerRepository,
		private readonly FileChatTriggerRegistrar $triggerRegistrar,
		?ExceptionHandler $exceptionHandler = null,
		?FileChatTriggerCache $triggerCache = null,
	)
	{
		$this->exceptionHandler = $exceptionHandler ?? Application::getInstance()->getExceptionHandler();
		$this->triggerCache = $triggerCache ?? new FileChatTriggerCache();
	}

	public function getAvailableMenuFile(File $file, int $userId): ?File
	{
		try
		{
			// The row is on a page the grid has already cleared for this user, so the values held in memory
			// answer first: a folder without a single supported file must not ask the trigger registry at all,
			// nor pay for canDownload(), which costs a query per row.
			$this->requireExistingFile($file);
			$this->requireSupportedFile($file);
			$this->requireMenuTrigger($userId);
			$this->requireDownloadableFile($file, $userId);

			return $file;
		}
		catch (\DomainException)
		{
			return null;
		}
		catch (\Throwable $exception)
		{
			$this->logFailure($exception);

			return null;
		}
	}

	public function requireAvailableFile(int $fileId, int $userId): File
	{
		$this->requireAvailableTrigger($userId);
		if ($fileId <= 0)
		{
			throw new InvalidRequestException('invalid_entity', 'A positive file ID is required');
		}

		$file = $this->loadFile($fileId);
		if (!$file instanceof File || (int)$file->getId() !== $fileId)
		{
			throw new InvalidRequestException('file_not_found', 'The file does not exist');
		}

		$this->requireExistingFile($file);
		// The id comes from the client and may name any file, so the right answers first: checked
		// after the format, it would let the refusal code tell a stranger the extension and size of
		// a file they cannot see.
		$this->requireDownloadableFile($file, $userId);
		$this->requireSupportedFile($file);

		return $file;
	}

	/** The launch reads the registry itself: nothing a menu remembered lets a file through here. */
	private function requireAvailableTrigger(int $userId): void
	{
		$this->requireTriggerPrerequisites($userId);
		$this->ensureRegisteredOnce();
		if (!$this->hasAvailableTrigger($userId))
		{
			throw new AccessDeniedException('The file chat is unavailable for the user');
		}
	}

	/**
	 * The menu runs on every opening of a list, so it trusts a registration an earlier request found ready
	 * and a user it found the trigger available for; the launch checks both again anyway.
	 */
	private function requireMenuTrigger(int $userId): void
	{
		$this->requireTriggerPrerequisites($userId);
		$this->registrationRemembered ??= $this->getTriggerCache()->isRegistrationReady();
		if (!$this->registrationRemembered)
		{
			$this->ensureRegisteredOnce();
		}

		$this->menuTriggerAccess[$userId] ??= $this->hasMenuTriggerAccess($userId);
		if (!$this->menuTriggerAccess[$userId])
		{
			throw new AccessDeniedException('The file chat is unavailable for the user');
		}
	}

	private function requireTriggerPrerequisites(int $userId): void
	{
		if (!$this->isAiAssistantAvailable())
		{
			throw new \DomainException('AI assistant is unavailable');
		}

		// The flag answers before anything that costs a query: a menu row of a feature that is off
		// must not reach the trigger registry at all, and a silent refusal is what the menu expects.
		if (!$this->isFileChatFlagEnabled($userId))
		{
			throw new AccessDeniedException('The file chat is unavailable for the user');
		}

		$feature = $this->getFeature();
		if (
			$userId <= 0
			|| !$feature->isBitrixGptV2Available()
			|| !$feature->isBitrixGptV2AvailableForUser($userId, 'bitrixgpt_v2_available', 'im')
			|| !$feature->isCopilotTriggersEnabled()
			|| !$feature->isSuggestsEnabled()
			|| !$this->getGuardService()->canUseMarta($userId)
		)
		{
			throw new AccessDeniedException('The file chat is unavailable for the user');
		}
	}

	private function requireExistingFile(File $file): void
	{
		if ((int)$file->getId() <= 0 || $file->isDeleted())
		{
			throw new InvalidRequestException('file_not_found', 'The file does not exist');
		}
	}

	/** An unknown size is a refusal. */
	private function requireSupportedFile(File $file): void
	{
		$size = $file->getSize();
		if (
			!in_array(strtolower((string)$file->getExtension()), self::SUPPORTED_EXTENSIONS, true)
			|| !is_numeric($size)
			|| $size < 0
			|| $size > self::MAX_FILE_SIZE
		)
		{
			throw new InvalidRequestException('unsupported_file', 'The file format or size is not supported');
		}
	}

	/** The assistant reads the file through the REST download, which answers only to this right. */
	private function requireDownloadableFile(File $file, int $userId): void
	{
		$securityContext = $file->getStorage()?->getSecurityContext($userId);
		if ($securityContext === null)
		{
			throw new InvalidRequestException('file_not_found', 'The file does not exist');
		}

		if (!$file->canDownload($securityContext))
		{
			throw new AccessDeniedException('The user cannot download the file');
		}
	}

	private function ensureRegisteredOnce(): void
	{
		if (!$this->registrationAttempted)
		{
			$this->registrationAttempted = true;
			$this->registerTrigger();
		}
	}

	private function hasAvailableTrigger(int $userId): bool
	{
		return $this->getTriggerRepository()->hasAvailableForUserByCodes($userId, [FileChatTrigger::getCode()]);
	}

	/** Only a positive answer is kept, so a trigger switched on elsewhere shows in the very next menu. */
	private function hasMenuTriggerAccess(int $userId): bool
	{
		$cache = $this->getTriggerCache();
		if ($cache->isAvailableForUser($userId))
		{
			return true;
		}

		$isAvailable = $this->hasAvailableTrigger($userId);
		if ($isAvailable)
		{
			$cache->rememberAvailableForUser($userId);
		}

		return $isAvailable;
	}

	protected function registerTrigger(): void
	{
		$this->triggerRegistrar->ensureRegistered();
	}

	protected function isAiAssistantAvailable(): bool
	{
		return Loader::includeModule('aiassistant');
	}

	protected function isFileChatFlagEnabled(int $userId): bool
	{
		return MainFeature::isEnabled(BitrixGptFileChatFlag::class, new Context($userId));
	}

	protected function getFeature(): Feature
	{
		return $this->feature;
	}

	protected function getGuardService(): GuardService
	{
		return $this->guardService;
	}

	protected function getTriggerRepository(): TriggerRepository
	{
		return $this->triggerRepository;
	}

	protected function getTriggerCache(): FileChatTriggerCache
	{
		return $this->triggerCache;
	}

	protected function loadFile(int $fileId): ?BaseObject
	{
		return File::loadById($fileId);
	}

	protected function logFailure(\Throwable $exception): void
	{
		$this->exceptionHandler->writeToLog($exception);
	}
}
