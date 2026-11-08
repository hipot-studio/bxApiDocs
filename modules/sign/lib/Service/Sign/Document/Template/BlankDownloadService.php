<?php

namespace Bitrix\Sign\Service\Sign\Document\Template;

use Bitrix\Main;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Item;
use Bitrix\Sign\Repository\BlankRepository;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Repository\Document\TemplateFolderRepository;
use Bitrix\Sign\Repository\Document\TemplateRepository;
use Bitrix\Sign\Result\Service\Sign\CreateBlankArchiveResult;
use Bitrix\Sign\Result\Service\Sign\Document\Template\BlankDownloadResult;
use Bitrix\Sign\Result\Service\Sign\Document\Template\PrepareBlankDownloadResult;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\BlankArchiveService;
use Bitrix\Sign\Service\Sign\Document\TemplateFolderService;
use Bitrix\Sign\Type\Template\EntityType;
use Bitrix\Sign\Util\Filename;
use Bitrix\Sign\Util\Request;
use Psr\Log\LoggerInterface;

/**
 * Serves unfilled template files for a set of template list entities: folders are expanded into
 * templates, read access and the operation limit are checked, templates without files are skipped.
 */
final class BlankDownloadService
{
	public const TEMPLATES_LIMIT = 50;

	/**
	 * Guard of the input set, told apart from the limit of the operation: the list serves one page
	 * of rows at a time, so a set of this size does not come from it at all.
	 */
	public const ENTITIES_IN_SET_LIMIT = 200;

	/**
	 * Weight of one operation. A single file of a blank is allowed up to fifty megabytes, so the
	 * count of templates alone leaves the archive unbounded: the server would read gigabytes out of
	 * the storage, write them into a temporary file and hold a worker for all of it.
	 */
	public const TOTAL_SIZE_LIMIT_BYTES = 300 * 1024 * 1024;

	public const ERROR_CODE_ENTITIES_INVALID = 'ENTITIES_INVALID';
	public const ERROR_CODE_ACCESS_DENIED = 'ACCESS_DENIED';
	public const ERROR_CODE_LIMIT_EXCEEDED = 'BLANK_DOWNLOAD_LIMIT_EXCEEDED';
	public const ERROR_CODE_NOTHING_TO_DOWNLOAD = 'BLANK_DOWNLOAD_NOTHING_TO_DOWNLOAD';
	public const ERROR_CODE_SIZE_LIMIT_EXCEEDED = 'BLANK_DOWNLOAD_SIZE_LIMIT_EXCEEDED';

	private readonly TemplateRepository $templateRepository;
	private readonly TemplateFolderRepository $templateFolderRepository;
	private readonly DocumentRepository $documentRepository;
	private readonly BlankRepository $blankRepository;
	private readonly TemplateFolderService $templateFolderService;
	private readonly AccessService $accessService;
	private readonly BlankArchiveService $blankArchiveService;
	private ?LoggerInterface $logger = null;

	public function __construct(
		?TemplateRepository $templateRepository = null,
		?TemplateFolderRepository $templateFolderRepository = null,
		?DocumentRepository $documentRepository = null,
		?BlankRepository $blankRepository = null,
		?TemplateFolderService $templateFolderService = null,
		?AccessService $accessService = null,
		?BlankArchiveService $blankArchiveService = null,
	)
	{
		$container = Container::instance();
		$this->templateRepository = $templateRepository ?? $container->getDocumentTemplateRepository();
		$this->templateFolderRepository = $templateFolderRepository ?? $container->getTemplateFolderRepository();
		$this->documentRepository = $documentRepository ?? $container->getDocumentRepository();
		$this->blankRepository = $blankRepository ?? $container->getBlankRepository();
		$this->templateFolderService = $templateFolderService ?? $container->getTemplateFolderService();
		$this->accessService = $accessService ?? $container->getTemplateAccessService();
		$this->blankArchiveService = $blankArchiveService ?? $container->getSignBlankArchiveService();
	}

	/**
	 * @param array<array{id?: mixed, entityType?: mixed}> $entities
	 */
	public function prepare(array $entities): Main\Result|PrepareBlankDownloadResult
	{
		$resolved = $this->resolve($entities);
		if (!$resolved->isSuccess())
		{
			return $resolved;
		}

		$data = $resolved->getData();

		return new PrepareBlankDownloadResult(count($data['entries']), $data['skippedCount']);
	}

	/**
	 * @param array<array{id?: mixed, entityType?: mixed}> $entities
	 */
	public function download(array $entities): Main\Result|BlankDownloadResult
	{
		$resolved = $this->resolve($entities);
		if (!$resolved->isSuccess())
		{
			return $resolved;
		}

		$data = $resolved->getData();
		if ($data['singleFile'])
		{
			$entry = $data['entries'][0];
			$file = $entry['blank']->fileCollection->first();
			// The title of the template reaches Content-Disposition and the download attribute of the
			// client, so it is sanitized here as well as in the archive entry - and sanitized first:
			// a title like `CON:` becomes a device name only once the colon is gone.
			$composedName = Filename::compose($file->name, $entry['entryBaseName']);
			$sanitizedName = Request\File::sanitizeFilename($composedName) ?? Request\File::getRandomName();

			return BlankDownloadResult::createForFile(
				(int)$file->id,
				Filename::forFileSystem($sanitizedName),
				$data['skippedCount'],
			);
		}

		$archiveResult = $this->blankArchiveService->createFromBlanks($data['entries'], $data['archiveTitle']);
		if (!$archiveResult instanceof CreateBlankArchiveResult)
		{
			return $this->replaceArchiveFailureMessages($archiveResult);
		}

		return BlankDownloadResult::createForArchive(
			$archiveResult->filePath,
			$archiveResult->fileName,
			$data['skippedCount'],
		);
	}

	/**
	 * @param array<array{id?: mixed, entityType?: mixed}> $entities
	 * @return Main\Result data on success: entries as
	 *   list<array{blank: Item\Blank, entryBaseName: string|null}>, skippedCount as int,
	 *   singleFile as bool, archiveTitle as string
	 */
	private function resolve(array $entities): Main\Result
	{
		$result = new Main\Result();

		$normalizedEntities = $this->normalizeEntities($entities);
		if ($normalizedEntities === null)
		{
			return $result->addError($this->createError(
				'SIGN_SERVICE_SIGN_DOCUMENT_TEMPLATE_BLANK_DOWNLOAD_ERROR_ENTITIES_INVALID',
				self::ERROR_CODE_ENTITIES_INVALID,
			));
		}

		// The size of the set is what the client controls, so it is bounded before the first query:
		// otherwise a set of any size pays for the reads and the per-entity access check. This is a
		// guard of the transport and not the limit of the operation - a set of this size is beyond
		// what the list itself lets one select - so it answers as an unusable set.
		if (count($normalizedEntities) > self::ENTITIES_IN_SET_LIMIT)
		{
			return $result->addError($this->createError(
				'SIGN_SERVICE_SIGN_DOCUMENT_TEMPLATE_BLANK_DOWNLOAD_ERROR_ENTITIES_INVALID',
				self::ERROR_CODE_ENTITIES_INVALID,
			));
		}

		$folderIds = $this->extractIdsByType($normalizedEntities, EntityType::FOLDER);
		$templateIds = $this->extractIdsByType($normalizedEntities, EntityType::TEMPLATE);

		// Templates named by the set are the templates of the operation as they are, so the limit
		// of the operation is already met here. Folders are not: how many templates they hold is
		// known after the expansion, and an empty folder holds none - the limit meets them below.
		if (count($templateIds) > self::TEMPLATES_LIMIT)
		{
			return $result->addError($this->createLimitError());
		}

		$folders = $this->templateFolderRepository->getByIds($folderIds);
		if ($folders->count() !== count($folderIds))
		{
			return $result->addError($this->createEntitiesUnavailableError('folders of the set are missing'));
		}

		$requestedTemplates = $this->templateRepository->getByIds($templateIds);
		if ($requestedTemplates->count() !== count($templateIds))
		{
			return $result->addError($this->createEntitiesUnavailableError('templates of the set are missing'));
		}

		// Folders are expanded once and the very same collection is both checked and served: read
		// twice, a template moved into a folder between the reads would reach the output unchecked.
		// One template past the limit is enough to refuse below, so the read of folders of any size
		// costs no more than that.
		$templatesInFolders = $folderIds === []
			? new Item\Document\TemplateCollection()
			: $this->templateFolderService->getTemplatesInFolders($folderIds, self::TEMPLATES_LIMIT + 1)
		;

		$accessResult = $this->accessService->hasAccessToReadTemplateEntities(
			$requestedTemplates,
			$templatesInFolders,
			$folders,
		);
		if (!$accessResult->isSuccess())
		{
			return $result->addError($this->createEntitiesUnavailableError(
				implode('; ', $accessResult->getErrorMessages()),
			));
		}

		$templates = $this->mergeTemplatesById($requestedTemplates, $templatesInFolders);
		if (count($templates) > self::TEMPLATES_LIMIT)
		{
			return $result->addError($this->createLimitError());
		}

		$collected = $this->collectEntries($templates);
		$entries = $collected['entries'];
		if ($entries === [])
		{
			return $result->addError($this->createError(
				'SIGN_SERVICE_SIGN_DOCUMENT_TEMPLATE_BLANK_DOWNLOAD_ERROR_NOTHING_TO_DOWNLOAD',
				self::ERROR_CODE_NOTHING_TO_DOWNLOAD,
			));
		}

		if ($this->getTotalSize($entries) > self::TOTAL_SIZE_LIMIT_BYTES)
		{
			return $result->addError($this->createSizeLimitError());
		}

		$singleEntity = count($normalizedEntities) === 1 ? $normalizedEntities[0] : null;

		return $result->setData([
			'entries' => $entries,
			'skippedCount' => $collected['skippedCount'],
			'singleFile' => $this->isSingleFileDownload($singleEntity, $entries),
			'archiveTitle' => $singleEntity === null
				? $this->getDefaultArchiveTitle()
				: $this->resolveEntityTitle($singleEntity, $folders, $requestedTemplates),
		]);
	}

	/**
	 * Client side sends ids as strings and entity types as arbitrary strings, so both are validated
	 * here instead of being cast silently. Repeated entities collapse into one.
	 *
	 * @param array<array{id?: mixed, entityType?: mixed}> $entities
	 * @return list<array{id: int, type: EntityType}>|null null when the set is empty or malformed
	 */
	private function normalizeEntities(array $entities): ?array
	{
		if ($entities === [])
		{
			return null;
		}

		$normalizedEntities = [];
		foreach ($entities as $entity)
		{
			if (!is_array($entity))
			{
				return null;
			}

			$id = $this->normalizeEntityId($entity['id'] ?? null);
			$rawType = $entity['entityType'] ?? null;
			$type = is_string($rawType) ? EntityType::tryFrom($rawType) : null;
			if ($id === null || $type === null)
			{
				return null;
			}

			$normalizedEntities[$type->value . ':' . $id] = ['id' => $id, 'type' => $type];
		}

		return array_values($normalizedEntities);
	}

	private function normalizeEntityId(mixed $rawId): ?int
	{
		if (is_int($rawId))
		{
			return $rawId > 0 ? $rawId : null;
		}

		if (is_string($rawId) && preg_match('/^[1-9]\d*$/', $rawId) === 1)
		{
			return (int)$rawId;
		}

		return null;
	}

	/**
	 * @param list<array{id: int, type: EntityType}> $entities
	 * @return list<int>
	 */
	private function extractIdsByType(array $entities, EntityType $type): array
	{
		$ids = [];
		foreach ($entities as $entity)
		{
			if ($entity['type'] === $type)
			{
				$ids[$entity['id']] = $entity['id'];
			}
		}

		return array_values($ids);
	}

	/**
	 * Requested templates come first and keep their order, folder content follows; a template
	 * selected both explicitly and inside a folder is returned once.
	 *
	 * @return array<int, Item\Document\Template>
	 */
	private function mergeTemplatesById(
		Item\Document\TemplateCollection $requestedTemplates,
		Item\Document\TemplateCollection $templatesInFolders,
	): array
	{
		$templates = [];
		foreach ([$requestedTemplates, $templatesInFolders] as $collection)
		{
			foreach ($collection->toArray() as $template)
			{
				$templates[(int)$template->id] ??= $template;
			}
		}

		return $templates;
	}

	/**
	 * @param array<int, Item\Document\Template> $templates
	 * @return array{entries: list<array{blank: Item\Blank, entryBaseName: string|null}>, skippedCount: int}
	 */
	private function collectEntries(array $templates): array
	{
		$blanks = $this->loadBlanksByTemplateId(array_keys($templates));

		$entries = [];
		$skippedCount = 0;
		foreach ($templates as $templateId => $template)
		{
			$blank = $blanks[$templateId] ?? null;
			// The files are already hydrated from their b_file rows and the ones gone from the
			// storage are left out there, so an empty collection is exactly a blank with nothing to
			// serve. Asking the blank service again would read every row a second time.
			if ($blank?->fileCollection === null || $blank->fileCollection->count() === 0)
			{
				$skippedCount++;

				continue;
			}

			$entries[] = ['blank' => $blank, 'entryBaseName' => $template->title];
		}

		return ['entries' => $entries, 'skippedCount' => $skippedCount];
	}

	/**
	 * @param list<int> $templateIds
	 * @return array<int, Item\Blank>
	 */
	private function loadBlanksByTemplateId(array $templateIds): array
	{
		$blankIdsByTemplateId = [];
		foreach ($this->documentRepository->listByTemplateIds($templateIds) as $document)
		{
			$templateId = (int)$document->templateId;
			$blankId = (int)$document->blankId;
			if ($blankId < 1 || isset($blankIdsByTemplateId[$templateId]))
			{
				continue;
			}

			$blankIdsByTemplateId[$templateId] = $blankId;
		}

		$blanksById = [];
		$uniqueBlankIds = array_values(array_unique($blankIdsByTemplateId));
		foreach ($this->blankRepository->getByIds($uniqueBlankIds)->toArray() as $blank)
		{
			$blanksById[$blank->getId()] = $blank;
		}

		$blanks = [];
		foreach ($blankIdsByTemplateId as $templateId => $blankId)
		{
			if (isset($blanksById[$blankId]))
			{
				$blanks[$templateId] = $blanksById[$blankId];
			}
		}

		return $blanks;
	}

	/**
	 * @param array{id: int, type: EntityType}|null $singleEntity
	 * @param list<array{blank: Item\Blank, entryBaseName: string|null}> $entries
	 */
	private function isSingleFileDownload(?array $singleEntity, array $entries): bool
	{
		if ($singleEntity === null || $singleEntity['type'] !== EntityType::TEMPLATE)
		{
			return false;
		}

		return $entries[0]['blank']->fileCollection?->count() === 1;
	}

	/**
	 * @param array{id: int, type: EntityType} $entity
	 */
	private function resolveEntityTitle(
		array $entity,
		Item\Document\TemplateFolderCollection $folders,
		Item\Document\TemplateCollection $templates,
	): string
	{
		$title = $entity['type'] === EntityType::FOLDER
			? $folders->findById($entity['id'])?->title
			: $templates->findById($entity['id'])?->title
		;

		return (string)$title;
	}

	private function getDefaultArchiveTitle(): string
	{
		return (string)Loc::getMessage('SIGN_SERVICE_SIGN_DOCUMENT_TEMPLATE_BLANK_DOWNLOAD_DEFAULT_ARCHIVE_TITLE');
	}

	/**
	 * Archive failures reach the user, so the technical message of the archive service is replaced;
	 * its code is kept as the diagnostic contract.
	 */
	private function replaceArchiveFailureMessages(Main\Result $archiveResult): Main\Result
	{
		$result = new Main\Result();
		foreach ($archiveResult->getErrors() as $error)
		{
			$result->addError($this->createError(
				'SIGN_SERVICE_SIGN_DOCUMENT_TEMPLATE_BLANK_DOWNLOAD_ERROR_ARCHIVE_FAILED',
				(string)$error->getCode(),
			));
		}

		return $result;
	}

	/**
	 * A missing entity and a forbidden one are answered alike. Told apart, the pair reports which
	 * ids of the portal are taken, including those the caller may not read, and that also cheapens
	 * the guessing of an id to pair with an entity of another kind. The reason of the refusal is
	 * kept for the server log only, and at the level the sign.Service channel keeps by default -
	 * a lower one is dropped, leaving the refusal without any diagnostics at all.
	 */
	private function createEntitiesUnavailableError(string $reason): Error
	{
		$this->getLogger()->error(
			'Template blank download refused: {reason}',
			['reason' => $reason],
		);

		return $this->createError(
			'SIGN_SERVICE_SIGN_DOCUMENT_TEMPLATE_BLANK_DOWNLOAD_ERROR_ACCESS_DENIED',
			self::ERROR_CODE_ACCESS_DENIED,
		);
	}

	private function getLogger(): LoggerInterface
	{
		return $this->logger ??= Container::instance()->getLogger('Service');
	}

	/**
	 * Sizes come from the rows the hydration has already read, so weighing the operation costs
	 * nothing beyond what the resolve has done anyway.
	 *
	 * @param list<array{blank: Item\Blank, entryBaseName: string|null}> $entries
	 */
	private function getTotalSize(array $entries): int
	{
		$total = 0;
		foreach ($entries as $entry)
		{
			foreach ($entry['blank']->fileCollection ?? [] as $file)
			{
				$total += max(0, (int)$file->size);
			}
		}

		return $total;
	}

	private function createSizeLimitError(): Error
	{
		return $this->createError(
			'SIGN_SERVICE_SIGN_DOCUMENT_TEMPLATE_BLANK_DOWNLOAD_ERROR_SIZE_LIMIT_EXCEEDED',
			self::ERROR_CODE_SIZE_LIMIT_EXCEEDED,
			['#LIMIT#' => (string)(int)(self::TOTAL_SIZE_LIMIT_BYTES / 1024 / 1024)],
		);
	}

	private function createLimitError(): Error
	{
		return $this->createError(
			'SIGN_SERVICE_SIGN_DOCUMENT_TEMPLATE_BLANK_DOWNLOAD_ERROR_LIMIT_EXCEEDED',
			self::ERROR_CODE_LIMIT_EXCEEDED,
			['#LIMIT#' => (string)self::TEMPLATES_LIMIT],
		);
	}

	/**
	 * @param array<string, string> $replacements
	 */
	private function createError(string $phraseCode, string $errorCode, array $replacements = []): Error
	{
		return new Error((string)Loc::getMessage($phraseCode, $replacements), $errorCode);
	}
}
