<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Copilot\Manager as CopilotManager;
use Bitrix\Landing\Integration\AiAssistant\Dto\GetMarkupPackageDto;
use Bitrix\Landing\Integration\AiAssistant\Exception\MarkupRequestRefusedException;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteModeGuard;
use Bitrix\Landing\Markup\FileStore;
use Bitrix\Landing\Markup\Manifest;
use Bitrix\Landing\Markup\PackageExporter;
use Bitrix\Landing\Markup\Submission\EditTargetResolver;
use Bitrix\Landing\Markup\UploadBuffer;
use Bitrix\Landing\Markup\Validator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * The read channel of the markup family: reads the current package version of the custom-markup
 * page of the site the binding of the user is pinned to, in the contract form, file by file. The
 * page is found by the edit target resolver, never named by the caller; the read serves the edit
 * line of the markup generation and is closed with it. Without a path it answers the inventory of
 * the package without contents; with a path - one base64 part of that file, no longer than the part
 * limit of the upload buffer. A refusal of the resolver is a soft answer with its code, the missing
 * edit right an exception; a page without a custom block answers with the NOT_MARKUP_PAGE code - the
 * signal to hand the request over to the change_ai_site line. Read-only: nothing is written or locked.
 * readWholeFile() gives one whole file of the same version to landing.markup.getfile.
 */
class GetMarkupPackageHandler extends AbstractSiteHandler
{
	public const BINDING_REQUIRED_ERROR = 'bindingId is required to read the markup package.';

	public function handle(GetMarkupPackageDto $dto, int $userId): array
	{
		// reading the package starts a markup edit, so it follows the selected generation as well
		(new AiSiteModeGuard())->assertModeSelected(CopilotManager::AI_SITES_MODE_MARKUP);

		if ($dto->bindingId <= 0)
		{
			throw new \InvalidArgumentException(self::BINDING_REQUIRED_ERROR);
		}

		if (!$this->isAiSitesMarkupEnabled())
		{
			throw new \RuntimeException('AI sites markup tools are disabled.');
		}

		$resolved = $this->resolveEditTarget($dto->bindingId, $userId);
		if (!$resolved->isSuccess())
		{
			$refusal = $resolved->getErrors()[0];
			if ((string)$refusal->getCode() === EditTargetResolver::ERROR_ACCESS_DENIED)
			{
				throw new \RuntimeException($refusal->getMessage());
			}

			return $this->buildEditTargetRefusedResponse($refusal);
		}

		$manifest = $resolved->getData()['target']->manifest;
		if (!$manifest instanceof Manifest || $manifest->getEntry() === null)
		{
			// a custom page is never handed over to change_ai_site, even without a stored version
			throw new \RuntimeException('The custom markup page has no stored package version.');
		}

		if (!$dto->isInventory())
		{
			return $this->readFilePart($manifest, $dto);
		}

		$inventory = $this->buildInventory($manifest);
		if ($inventory === null)
		{
			throw new \RuntimeException('The stored package version cannot be read.');
		}

		return [
			'files' => $inventory['files'],
			'images' => $inventory['images'],
			'forms' => array_map(
				// an empty design stays a JSON object, as in the declaration
				static fn(array $form): array => array_merge($form, ['design' => $form['design'] ?: new \stdClass()]),
				$inventory['forms'],
			),
			'meta' => $inventory['meta'],
			'manifestVersion' => Manifest::VERSION,
			'chunkBytes' => $this->resolveChunkBytes(),
			'limits' => [
				'packageSize' => Validator::LIMIT_PACKAGE_SIZE,
				'fileSize' => Validator::LIMIT_FILE_SIZE,
				'filesCount' => Validator::LIMIT_FILES_COUNT,
				'entrySize' => Validator::LIMIT_ENTRY_SIZE,
				'videoCount' => Validator::LIMIT_VIDEO_COUNT,
			],
			'message' => 'The inventory lists the package without contents, the entry first. Read only the files you '
				. 'need to change, each whole by one wrapper call GET /v1/sites/markup/files with bindingId and its '
				. 'path, as the skill says. Change only what the user asked for. Return unchanged files '
				. 'as {path, fileId, hash} references in submit_markup_package with mode=update. forms lists the CRM '
				. 'forms of the page by token: keep their markers and tokens; formId and formName are read-only '
				. '(formName is empty without the right to read CRM forms), form fields are changed by the CRM form '
				. 'tools.',
		];
	}

	/**
	 * One whole file of the current package version for the REST read of the bridge: the page and the
	 * rights as in handle(), but every refusal is a MarkupRequestRefusedException with a string code - the
	 * refusal of the resolver with its own code, a path the version does not hold with FILE_NOT_FOUND.
	 * The file is read as one part bounded by the base64 length of the single file limit; a page without
	 * a stored version and a stored file that cannot be read whole are failures of the system.
	 *
	 * @return array{path: string, content: string, hash: string, size: int} Content is base64, the hash
	 *  and the size are of the bytes given.
	 *
	 * @throws MarkupRequestRefusedException
	 */
	public function readWholeFile(GetMarkupPackageDto $dto, int $userId): array
	{
		(new AiSiteModeGuard())->assertModeSelected(CopilotManager::AI_SITES_MODE_MARKUP);

		if (!$this->isAiSitesMarkupEnabled())
		{
			throw new MarkupRequestRefusedException(
				'AI sites markup tools are disabled.',
				MarkupRequestRefusedException::DISABLED,
			);
		}

		if ($dto->bindingId <= 0 || $dto->isInventory())
		{
			throw new MarkupRequestRefusedException(
				'bindingId and path of the package file are required to read the file.',
				MarkupRequestRefusedException::BAD_REQUEST,
			);
		}

		$resolved = $this->resolveEditTarget($dto->bindingId, $userId);
		if (!$resolved->isSuccess())
		{
			$refusal = $resolved->getErrors()[0];
			$code = (string)$refusal->getCode();
			if ($code === EditTargetResolver::ERROR_ACCESS_DENIED)
			{
				$code = MarkupRequestRefusedException::ACCESS_DENIED;
			}

			throw new MarkupRequestRefusedException($refusal->getMessage(), $code);
		}

		$manifest = $resolved->getData()['target']->manifest;
		if (!$manifest instanceof Manifest || $manifest->getEntry() === null)
		{
			throw new \RuntimeException('The custom markup page has no stored package version.');
		}

		// the base64 length of the single file limit: the whole file comes as one part
		$maxLength = 4 * (int)ceil(Validator::LIMIT_FILE_SIZE / 3);
		try
		{
			$part = $this->createExporter()->readPart($manifest, $dto->path, 0, $maxLength);
		}
		catch (\InvalidArgumentException $exception)
		{
			throw new MarkupRequestRefusedException(
				$exception->getMessage(),
				MarkupRequestRefusedException::FILE_NOT_FOUND,
				$exception,
			);
		}

		$bytes = $part !== null && $part['nextOffset'] === null ? base64_decode($part['content'], true) : false;
		if ($bytes === false)
		{
			throw new \RuntimeException("The stored file {$dto->path} of the package cannot be read whole.");
		}

		return [
			'path' => $part['path'],
			'content' => $part['content'],
			'hash' => FileStore::hash($bytes),
			'size' => strlen($bytes),
		];
	}

	private function readFilePart(Manifest $manifest, GetMarkupPackageDto $dto): array
	{
		if ($dto->offset < 0)
		{
			throw new \InvalidArgumentException('offset must be a non-negative integer.');
		}

		$part = $this->createExporter()->readPart($manifest, $dto->path, $dto->offset, $this->resolveChunkBytes());
		if ($part === null)
		{
			throw new \RuntimeException("The stored file {$dto->path} of the package cannot be read.");
		}

		return $part;
	}

	protected function isAiSitesMarkupEnabled(): bool
	{
		return CopilotManager::isAiSitesMarkupEnabled();
	}

	/**
	 * The page to read: the single live custom page of the site the binding of the user is pinned to,
	 * which the user may edit. Runs in the user context of the tool, where the rights are read.
	 * @return Result Success data: ['target' => SubmissionTarget].
	 */
	protected function resolveEditTarget(int $bindingId, int $userId): Result
	{
		return (new EditTargetResolver())->resolve($bindingId, $userId);
	}

	/**
	 * The soft refusal of the read target: the code of the resolver in the common refusal form.
	 */
	private function buildEditTargetRefusedResponse(Error $refusal): array
	{
		$code = (string)$refusal->getCode();
		$message = match ($code)
		{
			EditTargetResolver::ERROR_NOT_MARKUP_PAGE => 'The page is not a custom markup page.',
			EditTargetResolver::ERROR_SITE_NOT_SINGLE_PAGE => 'The site of the binding does not hold a single page.',
			default => 'The binding names no site of the user to read.',
		};

		return [
			'status' => 'failed',
			'errors' => [[
				'code' => $code,
				'path' => null,
				'line' => null,
				'details' => $refusal->getMessage(),
				'repairable' => false,
			]],
			'message' => $message,
		];
	}

	protected function buildInventory(Manifest $manifest): ?array
	{
		return $this->createExporter()->inventory($manifest);
	}

	protected function createExporter(): PackageExporter
	{
		return new PackageExporter();
	}

	/**
	 * Read part limit in base64 characters; put_markup_file answers with its own write limit.
	 */
	protected function resolveChunkBytes(): int
	{
		return (new UploadBuffer())->getChunkLimit();
	}
}
