<?php

namespace Bitrix\Landing\Markup;

use Bitrix\Landing\AI\SiteBuilder\Tailwind\TailwindRuntimeEligibilityService;
use Bitrix\Landing\Block;
use Bitrix\Landing\File;
use Bitrix\Landing\History;
use Bitrix\Landing\History\Action\UpdateMarkupPackageAction;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Markup\Image\ImagePlan;
use Bitrix\Landing\Markup\Reference\ReferenceMatcher;
use Bitrix\Landing\Security\RemoteFile;
use Bitrix\Landing\Site;
use Bitrix\Main\Entity;
use Bitrix\Main\Error;
use Bitrix\Main\EventManager;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

Loc::loadMessages(__FILE__);

/**
 * Intake service of a markup package: the single writer of custom block versions.
 *
 * Source-agnostic: the dev entry, the chat tool and the future REST intake all call
 * this service. Validates the package, materializes files (immutable, deduplicated
 * against the previous version by content hash and source url), stores the version
 * manifest and pushes a history step which owns releasing the files of the replaced version.
 *
 * Text files are stored in the contract form - package paths and bx-asset:// tokens, as they
 * came; links to the files are built at serve time (Delivery\LinkRewriter), a token without a
 * file is shown as a placeholder there. The image declarations go to the manifest with their
 * outcome: the file of a generated picture is copied from the ai module into the package
 * storage, a declaration that repeats the current version inherits its outcome (Image\ImagePlan).
 * The CRM form bindings of the package go into the version manifest as they are.
 *
 * A file that cannot be stored stops the build: no manifest is written and the bindings
 * made by the build are released.
 */
class Intake
{
	public const BLOCK_CODE = '99.custom_markup';
	public const HISTORY_ACTION = 'UPDATE_MARKUP_PACKAGE';
	public const TOKEN_SCHEME = ReferenceMatcher::TOKEN_SCHEME;

	public const IMAGE_REASON_NOT_STORED = 'not_stored';
	public const IMAGE_REASON_NOT_GENERATED = 'not_generated';
	public const IMAGE_REASON_NOT_DOWNLOADED = 'not_downloaded';

	private const IMAGE_EXTENSION_BY_TYPE = [
		IMAGETYPE_PNG => 'png',
		IMAGETYPE_JPEG => 'jpg',
		IMAGETYPE_WEBP => 'webp',
		IMAGETYPE_GIF => 'gif',
	];

	private Validator $validator;

	/** @var int[] Files bound to the block by the running build. */
	private array $boundFileIds = [];

	public function __construct(?Validator $validator = null)
	{
		$this->validator = $validator ?? new Validator();
	}

	/**
	 * Returns the custom block of the landing, null if there is none.
	 */
	public static function findCustomBlock(Landing $landing): ?Block
	{
		foreach ($landing->getBlocks() as $block)
		{
			if (
				($block->getMarkupManifest() ?? '') !== ''
				|| $block->getCode() === self::BLOCK_CODE
			)
			{
				return $block;
			}
		}

		return null;
	}

	/**
	 * Creates the single custom block on the landing. Guard: exactly one per page.
	 */
	public function createBlock(Landing $landing): ?Block
	{
		if (self::findCustomBlock($landing))
		{
			return null;
		}

		// The intake is a backend operation on a specific site: the UI scope of the
		// current request (e.g. VIBE main page) must not filter the block repository.
		// A scope handler may have already enabled skip_common_blocks request-wide,
		// so the scope is both cleared and countered with an explicit disable.
		$scopeBefore = Site\Type::getCurrentScopeId();
		if ($scopeBefore !== null)
		{
			Site\Type::clearScope();
		}
		$eventManager = EventManager::getInstance();
		$eventHandlerKey = $eventManager->addEventHandler(
			'landing',
			'onBlockRepoSetFilters',
			static function (): Entity\EventResult
			{
				$result = new Entity\EventResult();
				$result->modifyFields([
					'DISABLE' => Block\BlockRepo::FILTER_SKIP_COMMON_BLOCKS,
				]);

				return $result;
			},
		);

		try
		{
			$blockId = $landing->addBlock(self::BLOCK_CODE, [
				'PUBLIC' => 'N',
				'DESIGNED' => 'Y',
			]);
		}
		finally
		{
			$eventManager->removeEventHandler('landing', 'onBlockRepoSetFilters', $eventHandlerKey);
			if ($scopeBefore !== null)
			{
				Site\Type::setScope($scopeBefore);
			}
		}

		if (!$blockId)
		{
			return null;
		}

		// the page has just become a markup one: an answer memoized earlier in this request is stale
		TailwindRuntimeEligibilityService::clearMarkupLandingCache((int)$landing->getId());

		return new Block($blockId);
	}

	/**
	 * Applies a package as the new version of the block.
	 * @param array<string, array{status: string, aiFileId?: int, reason?: string}> $imageOutcomes
	 *  Outcomes of the generation step by token: generated with the ai file of the picture, or failed.
	 * @return Result Data: ['manifest' => Manifest, 'images' => array{total: int, generated: int, reused: int,
	 *  failed: list<array{token: string, reason: string}>}].
	 */
	public function apply(Package $package, Block $block, int $landingId, array $imageOutcomes = []): Result
	{
		$result = $this->validator->validate($package);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$blockId = $block->getId();
		$this->boundFileIds = [];
		$oldManifestJson = (string)($block->getMarkupManifest() ?? '');
		$oldManifest = Manifest::fromJson($oldManifestJson);
		$newManifest = new Manifest();
		$newManifest->setMeta($package->getMeta());
		$newManifest->setForms(self::collectFormBindings($package));

		$images = $this->materializeImages($package, $imageOutcomes, $blockId, $oldManifest, $newManifest, $result);
		if (!$result->isSuccess())
		{
			return $this->releaseBuild($blockId, $result);
		}
		$this->materializeAssets($package, $blockId, $oldManifest, $newManifest, $result);
		if (!$result->isSuccess())
		{
			return $this->releaseBuild($blockId, $result);
		}

		$entry = $package->getEntry();
		$entryContent = $entry['content'];
		$entryHash = FileStore::hash($entryContent);
		$known = $oldManifest?->findByHash($entryHash);
		$entryFileId = $known ? (int)$known['fileId'] : FileStore::saveEntry($entryContent);
		if ($entryFileId <= 0)
		{
			$result->addError(new Error(
				Loc::getMessage('LANDING_MARKUP_INTAKE_SAVE_FAILED', ['#PATH#' => $entry['path']]),
				'MARKUP_SAVE_FAILED'
			));

			return $this->releaseBuild($blockId, $result);
		}
		if (!$known)
		{
			$this->bindNewFile($blockId, $entryFileId);
		}
		$newManifest->setEntry($entry['path'], $entryFileId, $entryHash, Manifest::ENTRY_TYPE);

		if (!$block->saveMarkupManifest($newManifest->toJson()))
		{
			$result->addError(new Error(
				Loc::getMessage('LANDING_MARKUP_INTAKE_MANIFEST_FAILED'),
				'MARKUP_MANIFEST_FAILED'
			));

			return $this->releaseBuild($blockId, $result);
		}

		$this->recordHistoryStep($blockId, $landingId, $oldManifestJson, $oldManifest, $newManifest);
		FileStore::commitBlockFiles($blockId);

		$result->setData(['manifest' => $newManifest, 'images' => $images]);

		return $result;
	}

	/**
	 * Binds the form token of the current version to another CRM form: a new version with the same
	 * files and a history step, so undo returns the previous binding. The design of the token is kept.
	 * The page is marked changed: the publication of the site skips the pages that are not.
	 * @return Result Data: ['manifest' => Manifest].
	 */
	public function rebindForm(Block $block, int $landingId, string $token, int $formId): Result
	{
		$result = new Result();

		$oldManifestJson = (string)($block->getMarkupManifest() ?? '');
		$oldManifest = Manifest::fromJson($oldManifestJson);
		$newManifest = Manifest::fromJson($oldManifestJson);
		if (!$oldManifest || !$newManifest)
		{
			return $result->addError(new Error(
				Loc::getMessage('LANDING_MARKUP_INTAKE_MANIFEST_FAILED'),
				'MARKUP_MANIFEST_MISSING'
			));
		}
		if ($formId <= 0 || !preg_match(Validator::TOKEN_REGEX, $token))
		{
			return $result->addError(new Error(
				Loc::getMessage('LANDING_MARKUP_INTAKE_BAD_FORM_BINDING', [
					'#TOKEN#' => $token,
					'#FORM_ID#' => $formId,
				]),
				'MARKUP_BAD_FORM_BINDING'
			));
		}

		$forms = $newManifest->getForms();
		$forms[$token] = [
			'formId' => $formId,
			'design' => $forms[$token]['design'] ?? [],
		];
		$newManifest->setForms($forms);

		if (!$block->saveMarkupManifest($newManifest->toJson()))
		{
			return $result->addError(new Error(
				Loc::getMessage('LANDING_MARKUP_INTAKE_MANIFEST_FAILED'),
				'MARKUP_MANIFEST_FAILED'
			));
		}

		$this->recordHistoryStep($block->getId(), $landingId, $oldManifestJson, $oldManifest, $newManifest);

		$landing = Landing::createInstance($landingId, ['skip_blocks' => true]);
		if ($landing->exist())
		{
			$landing->touch();
		}

		$result->setData(['manifest' => $newManifest]);

		return $result;
	}

	/**
	 * @return array<string, array{formId: int, design: array}>
	 */
	private static function collectFormBindings(Package $package): array
	{
		$bindings = [];
		foreach ($package->getFormDeclaration() as $form)
		{
			$binding = $form['binding'] ?? null;
			if (is_array($binding) && is_string($form['token'] ?? null))
			{
				$bindings[$form['token']] = $binding;
			}
		}

		return $bindings;
	}

	/**
	 * Stores an asset file of the package. The single storage point of the build.
	 * @return Result Data: ['fileId' => int].
	 */
	protected function saveAsset(string $path, string $content): Result
	{
		return FileStore::saveAsset($path, $content);
	}

	private function bindNewFile(int $blockId, int $fileId): void
	{
		FileStore::bindToBlock($blockId, $fileId);
		$this->boundFileIds[] = $fileId;
	}

	/**
	 * A failed build leaves no trace on the block: the bindings it made are released.
	 */
	private function releaseBuild(int $blockId, Result $result): Result
	{
		if ($this->boundFileIds)
		{
			File::deleteFromBlock($blockId, array_values(array_unique($this->boundFileIds)));
		}
		$this->boundFileIds = [];

		return $result;
	}

	/**
	 * Materializes the image declarations by the plan: a download of an external image (deduplicated
	 * by source url, no repeated requests), a copy of a generated picture from the ai file, the file
	 * of the current version for a repeated description, nothing for a failed picture. Every
	 * declaration goes to the manifest with its outcome, a failed one with the reason of the failure;
	 * an inherited failure keeps the reason of the version it came from. A picture that cannot be
	 * stored stops the build.
	 * @param array<string, array{status: string, aiFileId?: int, reason?: string}> $imageOutcomes
	 * @return array{total: int, generated: int, reused: int, failed: list<array{token: string, reason: string}>}
	 */
	private function materializeImages(
		Package $package,
		array $imageOutcomes,
		int $blockId,
		?Manifest $oldManifest,
		Manifest $newManifest,
		Result $result
	): array
	{
		$summary = ['total' => 0, 'generated' => 0, 'reused' => 0, 'failed' => []];
		$declarations = [];
		$declarationByToken = [];
		foreach ($package->getImageDeclaration() as $declaration)
		{
			$declarationByToken[(string)$declaration['token']] = $declaration;
		}

		foreach (ImagePlan::build($package->getImageDeclaration(), $oldManifest)->getItems() as $item)
		{
			$token = $item['token'];
			$declaration = $declarationByToken[$token];
			$summary['total']++;

			$stored = null;
			$reason = self::IMAGE_REASON_NOT_STORED;
			switch ($item['action'])
			{
				case ImagePlan::ACTION_DOWNLOAD:
					$stored = $this->materializeExternalImage($declaration, $blockId, $oldManifest, $result);
					$reason = self::IMAGE_REASON_NOT_DOWNLOADED;
					break;
				case ImagePlan::ACTION_REUSE:
					$stored = $this->takeKnownAsset($oldManifest?->findAssetByToken($token));
					if ($stored !== null)
					{
						$summary['reused']++;
					}
					break;
				case ImagePlan::ACTION_GENERATE:
					$outcome = $imageOutcomes[$token] ?? null;
					if (($outcome['status'] ?? '') === Manifest::OUTCOME_GENERATED)
					{
						$stored = $this->copyGeneratedImage(
							(int)($outcome['aiFileId'] ?? 0),
							$blockId,
							$oldManifest,
							$result,
						);
					}
					else
					{
						$reason = (string)($outcome['reason'] ?? '') ?: self::IMAGE_REASON_NOT_GENERATED;
					}
					break;
				default:
					// an inherited failure keeps the reason of the version it inherits from (ADR 3.3, 3.4)
					$reason = (string)($oldManifest?->findImage($token)['reason'] ?? '')
						?: self::IMAGE_REASON_NOT_GENERATED
					;
			}
			if (!$result->isSuccess())
			{
				return $summary;
			}

			if ($stored !== null)
			{
				$newManifest->addAsset(
					[
						'path' => 'images/' . $token,
						'fileId' => $stored['fileId'],
						'hash' => $stored['hash'],
						'type' => $stored['type'],
						'token' => $token,
					]
					+ ($item['action'] === ImagePlan::ACTION_DOWNLOAD
						? ['sourceUrl' => (string)$declaration['url']]
						: []
					)
				);
				if ($item['action'] === ImagePlan::ACTION_GENERATE)
				{
					$summary['generated']++;
				}
			}
			else
			{
				$summary['failed'][] = ['token' => $token, 'reason' => $reason];
			}

			// the outcome of the build and the trimmed prompt win over whatever the submission carried
			$declarations[] = ['prompt' => $item['prompt'], 'outcome' => $this->resolveOutcome($item, $stored)]
				+ ($stored === null ? ['reason' => $reason] : [])
				+ $declaration;
		}
		$newManifest->setImages($declarations);

		return $summary;
	}

	private function resolveOutcome(array $item, ?array $stored): string
	{
		if ($stored === null)
		{
			return Manifest::OUTCOME_FAILED;
		}

		return $item['action'] === ImagePlan::ACTION_DOWNLOAD
			? Manifest::OUTCOME_DOWNLOADED
			: Manifest::OUTCOME_GENERATED;
	}

	/**
	 * @return array{fileId: int, hash: string, type: string}|null
	 */
	private function takeKnownAsset(?array $known): ?array
	{
		if ($known === null || (int)$known['fileId'] <= 0)
		{
			return null;
		}

		return [
			'fileId' => (int)$known['fileId'],
			'hash' => (string)($known['hash'] ?? ''),
			'type' => $this->resolveKnownType($known),
		];
	}

	/**
	 * An external image known by its source url is taken from the current version, a new one is
	 * downloaded (SSRF-safe); a download that fails leaves the picture without a file.
	 * @return array{fileId: int, hash: string, type: string}|null
	 */
	private function materializeExternalImage(
		array $declaration,
		int $blockId,
		?Manifest $oldManifest,
		Result $result
	): ?array
	{
		$url = (string)($declaration['url'] ?? '');
		$known = $this->takeKnownAsset($oldManifest?->findBySourceUrl($url));
		if ($known !== null)
		{
			return $known;
		}

		$download = RemoteFile::download($url, Validator::LIMIT_FILE_SIZE);
		if (!$download->isSuccess())
		{
			return null;
		}

		return $this->storeImageBytes(
			'external',
			(string)$download->getData()['content'],
			$blockId,
			$oldManifest,
			$result,
		);
	}

	/**
	 * Copies the picture of the ai module into the package storage: the module keeps its original.
	 * An unreadable file leaves the picture without one.
	 * @return array{fileId: int, hash: string, type: string}|null
	 */
	private function copyGeneratedImage(int $aiFileId, int $blockId, ?Manifest $oldManifest, Result $result): ?array
	{
		$content = $this->readGeneratedImage($aiFileId);
		if ($content === null)
		{
			return null;
		}

		return $this->storeImageBytes('generated', $content, $blockId, $oldManifest, $result);
	}

	/**
	 * The bytes of the picture the ai module stored: a file of another module, which the package
	 * store never reads. The id comes from the generation state the image step wrote, not from a caller.
	 */
	private function readGeneratedImage(int $aiFileId): ?string
	{
		if ($aiFileId <= 0)
		{
			return null;
		}

		$fileArray = \CFile::makeFileArray($aiFileId);
		if (!is_array($fileArray) || empty($fileArray['tmp_name']))
		{
			return null;
		}

		$content = @file_get_contents($fileArray['tmp_name']);

		return $content === false ? null : $content;
	}

	/**
	 * Stores the bytes of a picture, deduplicated against the current version by content hash.
	 * @return array{fileId: int, hash: string, type: string}|null Null when the bytes are not an image
	 *  or not stored; the storage refusal is added to the result.
	 */
	private function storeImageBytes(
		string $name,
		string $content,
		int $blockId,
		?Manifest $oldManifest,
		Result $result
	): ?array
	{
		$info = @getimagesizefromstring($content);
		if (!is_array($info) || !isset(self::IMAGE_EXTENSION_BY_TYPE[$info[2]]))
		{
			return null;
		}

		$name .= '.' . self::IMAGE_EXTENSION_BY_TYPE[$info[2]];
		$hash = FileStore::hash($content);
		$known = $this->takeKnownAsset($oldManifest?->findByHash($hash));
		if ($known !== null)
		{
			return $known;
		}

		$saved = $this->saveAsset($name, $content);
		if (!$saved->isSuccess())
		{
			$result->addErrors($saved->getErrors());

			return null;
		}
		$fileId = (int)$saved->getData()['fileId'];
		$this->bindNewFile($blockId, $fileId);

		return [
			'fileId' => $fileId,
			'hash' => $hash,
			'type' => Manifest::typeByContent($name, $content),
		];
	}

	/**
	 * Stores package assets as they are. The first file that cannot be stored stops the build.
	 */
	private function materializeAssets(
		Package $package,
		int $blockId,
		?Manifest $oldManifest,
		Manifest $newManifest,
		Result $result
	): void
	{
		foreach ($package->getAssets() as $asset)
		{
			if (!$this->storeAsset($asset['path'], $asset['content'], $blockId, $oldManifest, $newManifest, $result))
			{
				return;
			}
		}
	}

	/**
	 * @return bool False when the file cannot be stored, the refusal is added to the result.
	 */
	private function storeAsset(
		string $path,
		string $content,
		int $blockId,
		?Manifest $oldManifest,
		Manifest $newManifest,
		Result $result
	): bool
	{
		$hash = FileStore::hash($content);
		$known = $oldManifest?->findByHash($hash);
		if ($known)
		{
			$fileId = (int)$known['fileId'];
		}
		else
		{
			$saved = $this->saveAsset($path, $content);
			if (!$saved->isSuccess())
			{
				$result->addErrors($saved->getErrors());

				return false;
			}
			$fileId = (int)$saved->getData()['fileId'];
			$this->bindNewFile($blockId, $fileId);
		}
		$newManifest->addAsset([
			'path' => $path,
			'fileId' => $fileId,
			'hash' => $hash,
			'type' => Manifest::typeByContent($path, $content),
		]);

		return true;
	}

	/**
	 * Type of a file reused from the previous version: a first version manifest has no types,
	 * a synthetic image path has no extension to guess by - the stored file tells then.
	 */
	private function resolveKnownType(array $known): string
	{
		$type = (string)($known['type'] ?? '');

		return ($type === '' || $type === Manifest::TYPE_UNKNOWN)
			? FileStore::getContentType((int)$known['fileId'])
			: $type;
	}

	/**
	 * Every version change leaves a trace: either a history step (which owns releasing
	 * the files of the replaced version) or, when the step cannot be pushed, an
	 * immediate unbind of the files used by the previous version only.
	 */
	private function recordHistoryStep(
		int $blockId,
		int $landingId,
		string $oldManifestJson,
		?Manifest $oldManifest,
		Manifest $newManifest
	): void
	{
		$pushed = false;
		if ($landingId > 0)
		{
			// History is a request-wide switch, normally enabled only on PublicAction entries
			$historyWasActive = History::isActive();
			History::activate();
			$history = new History($landingId, History::ENTITY_TYPE_LANDING);
			// manifest json is safe for the history params guard by construction:
			// Json::encode escapes angle brackets (JSON_HEX_TAG), so user meta cannot
			// smuggle a raw php open tag into ACTION_PARAMS
			$pushed = $history->push(
				self::HISTORY_ACTION,
				UpdateMarkupPackageAction::buildParams($blockId, $landingId, $oldManifestJson, $newManifest)
			);
			if (!$historyWasActive)
			{
				History::deactivate();
			}
		}

		if (!$pushed && $oldManifest)
		{
			$obsolete = array_diff($oldManifest->getFileIds(), $newManifest->getFileIds());
			if ($obsolete)
			{
				File::deleteFromBlock($blockId, array_values($obsolete));
			}
		}
	}
}
