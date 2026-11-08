<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\FileUploader;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\FileOwnershipCollection;
use Bitrix\UI\FileUploader\UploaderController;

Loader::requireModule('ui');

class AutomationFieldUploaderController extends UploaderController
{
	public const OPTION_DOCUMENT_TYPE = 'documentType';
	public const OPTION_MAX_FILE_SIZE = 'MaxFileSize';
	public const OPTION_ACCEPTED_FILE_TYPES = 'AcceptedFileTypes';

	/**
	 * Every option this controller knows; the rest of what a caller sends is dropped. The options are the
	 * salt of the upload token signature (Uploader::getTokenSalt mixes all of them in), so a key nothing
	 * here reads would still move the salt and cost the files already in a dropzone their tokens.
	 */
	private const KNOWN_OPTIONS = [
		self::OPTION_DOCUMENT_TYPE,
		self::OPTION_MAX_FILE_SIZE,
		self::OPTION_ACCEPTED_FILE_TYPES,
	];

	/**
	 * How many types one request may name. A field lists units of them, and the widest list a portal could
	 * plausibly configure - every document, image, archive and media family, spelled as extensions and as
	 * mime types both - still stays well under this.
	 */
	private const MAX_ACCEPTED_FILE_TYPES = 100;

	private ?bool $hasRights = null;

	/**
	 * The fourth part of the document type, the one \CBPHelper::parseDocumentId drops. It reaches the rights
	 * check as an operation parameter instead, the way FieldType::inputAndAccessCheck passes it: crm resolves
	 * the permission entity of the category by it, so a check without it asks about the default category.
	 */
	private readonly mixed $documentCategoryId;

	public function __construct(array $options = [])
	{
		$documentType = $options[self::OPTION_DOCUMENT_TYPE] ?? null;
		$this->documentCategoryId = self::extractDocumentCategoryId($documentType);

		$options = array_intersect_key($options, array_flip(self::KNOWN_OPTIONS));
		$options[self::OPTION_DOCUMENT_TYPE] = self::normalizeDocumentType($documentType);

		parent::__construct($options);
	}

	public function isAvailable(): bool
	{
		return $this->hasRights();
	}

	/**
	 * The limits below are sent by no client of the product. The narrowing is kept as depth for any other
	 * caller that does send them.
	 */
	public function getConfiguration(): Configuration
	{
		$configuration = new Configuration();

		$this->applyMaxFileSize($configuration);
		$this->applyAcceptedFileTypes($configuration);

		return $configuration;
	}

	public function canUpload(): bool
	{
		return $this->hasRights();
	}

	public function canView(): bool
	{
		return $this->hasRights();
	}

	public function verifyFileOwner(FileOwnershipCollection $files): void
	{
		// field values have no ownership registry yet, so no persistent file may be claimed as own
	}

	public function canRemove(): bool
	{
		return $this->hasRights();
	}

	private function hasRights(): bool
	{
		// current user and document type are fixed for the instance lifetime, so the verdict is computed once
		$this->hasRights ??= $this->checkRights();

		return $this->hasRights;
	}

	private function checkRights(): bool
	{
		$documentType = $this->getOption(self::OPTION_DOCUMENT_TYPE);
		if (!is_array($documentType))
		{
			return false;
		}

		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return false;
		}

		$operationParameters = $this->documentCategoryId === null
			? []
			: ['DocumentCategoryId' => $this->documentCategoryId]
		;

		return
			\CBPDocument::CanUserOperateDocumentType(
				\CBPCanUserOperateOperation::CreateWorkflow,
				$userId,
				$documentType,
				$operationParameters
			)
			|| \CBPDocument::CanUserOperateDocumentType(
				\CBPCanUserOperateOperation::CreateAutomation,
				$userId,
				$documentType,
				$operationParameters
			)
		;
	}

	private static function extractDocumentCategoryId(mixed $documentType): mixed
	{
		return is_array($documentType) ? ($documentType[3] ?? null) : null;
	}

	private static function normalizeDocumentType(mixed $documentType): ?array
	{
		if (!is_array($documentType))
		{
			return null;
		}

		// parseDocumentId reads the elements by the integer keys 0, 1, 2 but counts keys of any kind, so an
		// associative type of the same size reaches the reads and only warns its way to the exception
		foreach ([0, 1, 2] as $index)
		{
			if (!array_key_exists($index, $documentType))
			{
				return null;
			}
		}

		try
		{
			[$moduleId, $entity, $type] = \CBPHelper::parseDocumentId($documentType);
		}
		catch (\CBPArgumentNullException)
		{
			return null;
		}

		// parseDocumentId also accepts an entity-type pair and leaves moduleId empty; then rights depend on
		// which modules the request has already loaded, so an incomplete document type is rejected here
		if ($moduleId === '')
		{
			return null;
		}

		return [$moduleId, $entity, $type];
	}

	/**
	 * The size is client input, so it is applied as a narrowing of the product default only: a value above
	 * the default is dropped.
	 */
	private function applyMaxFileSize(Configuration $configuration): void
	{
		$maxFileSize = $this->getOption(self::OPTION_MAX_FILE_SIZE);
		if (!is_numeric($maxFileSize))
		{
			return;
		}

		$maxFileSize = (int)$maxFileSize;
		$defaultMaxFileSize = $configuration->getMaxFileSize();
		if ($maxFileSize <= 0 || ($defaultMaxFileSize !== null && $maxFileSize > $defaultMaxFileSize))
		{
			return;
		}

		$configuration->setMaxFileSize($maxFileSize);
	}

	/**
	 * The types are client input, so the request may only narrow the product defaults: a type the
	 * non-empty default list does not cover is dropped. An empty list means "any type", so a request
	 * covered by nothing leaves the defaults in place instead of emptying them.
	 *
	 * The count is bounded because nothing but post_max_size limits what a caller may send, while
	 * every walk over the list is linear - here and again for every chunk of every upload.
	 *
	 * An over-long list is dropped whole rather than trimmed: trimming would have the server silently
	 * refuse a file the caller accepted by the tail the server never saw. Dropping only discards a
	 * client-sent narrowing - the defaults and the portal setting, images-only mode included, stay as
	 * they are.
	 */
	private function applyAcceptedFileTypes(Configuration $configuration): void
	{
		$acceptedFileTypes = $this->getOption(self::OPTION_ACCEPTED_FILE_TYPES);
		if (!is_array($acceptedFileTypes))
		{
			return;
		}

		// counted before the list is walked, so an over-long one costs nothing to refuse
		if (count($acceptedFileTypes) > self::MAX_ACCEPTED_FILE_TYPES)
		{
			return;
		}

		$acceptedFileTypes = array_values(
			array_filter($acceptedFileTypes, static fn($fileType) => is_string($fileType) && trim($fileType) !== '')
		);
		if (!$acceptedFileTypes)
		{
			return;
		}

		// Configuration::setAcceptedFileTypes() switches the images-only mode off, and setAcceptOnlyImages()
		// cannot switch it back without restoring the whole list of image extensions along with it. Of the two
		// reachable outcomes keeping the portal setting is the stricter one, so the request is dropped here
		if ($configuration->shouldAcceptOnlyImages())
		{
			return;
		}

		$defaultFileTypes = $configuration->getAcceptedFileTypes();
		if ($defaultFileTypes)
		{
			$acceptedFileTypes = self::intersectFileTypes($acceptedFileTypes, $defaultFileTypes);
			if (!$acceptedFileTypes)
			{
				return;
			}
		}

		$configuration->setAcceptedFileTypes($acceptedFileTypes);
	}

	/**
	 * Keeps the requested types the allowed ones already cover, matching them the way
	 * ConfigurationValidator does: case-insensitively, and a mime type mask covers every mime type sharing
	 * its base. An extension covers no mime type and no mime type covers an extension - the validator matches
	 * an extension against the file name and a mime type against the reported content type, so a file passing
	 * one of them may well fail the other.
	 */
	private static function intersectFileTypes(array $requestedFileTypes, array $allowedFileTypes): array
	{
		$allowed = [];
		$allowedMimeTypeBases = [];
		foreach ($allowedFileTypes as $allowedFileType)
		{
			$allowedFileType = self::normalizeFileType($allowedFileType);
			if ($allowedFileType === '')
			{
				continue;
			}

			$allowed[$allowedFileType] = true;
			if (str_ends_with($allowedFileType, '/*'))
			{
				$allowedMimeTypeBases[self::getMimeTypeBase($allowedFileType)] = true;
			}
		}

		$isCovered = static function (string $requestedFileType) use ($allowed, $allowedMimeTypeBases): bool
		{
			$requestedFileType = self::normalizeFileType($requestedFileType);
			if (isset($allowed[$requestedFileType]))
			{
				return true;
			}

			if (str_starts_with($requestedFileType, '.'))
			{
				return false;
			}

			return isset($allowedMimeTypeBases[self::getMimeTypeBase($requestedFileType)]);
		};

		return array_values(array_filter($requestedFileTypes, $isCovered));
	}

	private static function normalizeFileType(mixed $fileType): string
	{
		return is_string($fileType) ? mb_strtolower(trim($fileType)) : '';
	}

	private static function getMimeTypeBase(string $fileType): string
	{
		$separatorPosition = mb_strpos($fileType, '/');

		return $separatorPosition === false ? $fileType : mb_substr($fileType, 0, $separatorPosition);
	}
}
