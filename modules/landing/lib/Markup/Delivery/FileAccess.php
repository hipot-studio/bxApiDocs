<?php

namespace Bitrix\Landing\Markup\Delivery;

use Bitrix\Landing\Internals\BlockTable;
use Bitrix\Landing\Internals\LandingTable;
use Bitrix\Landing\Markup\FileStore;
use Bitrix\Landing\Markup\Manifest;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Access decision for a file of a markup package requested by a signed link (API-12).
 *
 * The link must be signed for the block and the file, the file must belong to the current
 * manifest of the block and be stored by the package storage. A published link (no expiry)
 * serves only a public block which is not deleted, of an active page of an active site;
 * a draft link serves a block while its window lasts.
 * No session is involved: the frame of a custom block sends no portal cookies.
 */
class FileAccess
{
	public const ERROR_BAD_SIGNATURE = 'MARKUP_FILE_BAD_SIGNATURE';
	public const ERROR_NOT_FOUND = 'MARKUP_FILE_NOT_FOUND';
	public const ERROR_NOT_PUBLISHED = 'MARKUP_FILE_NOT_PUBLISHED';

	private const TEXT_TYPES = ['text/css', 'text/javascript', 'application/javascript'];

	private FileLinkSigner $signer;

	public function __construct(?FileLinkSigner $signer = null)
	{
		$this->signer = $signer ?? new FileLinkSigner();
	}

	/**
	 * @param int|null $expiresAt Null for a published link.
	 * @return Result Data: [
	 *     'manifest' => Manifest,
	 *     'file' => array{path: string, fileId: int, hash: string},
	 *     'type' => string,
	 *     'isText' => bool,
	 *     'isPublic' => bool,
	 * ]
	 */
	public function resolve(int $blockId, int $fileId, ?int $expiresAt, string $signature): Result
	{
		$result = new Result();

		if (!$this->signer->verify($blockId, $fileId, $expiresAt, $signature))
		{
			return $result->addError(new Error('The link is not valid.', self::ERROR_BAD_SIGNATURE));
		}

		$block = BlockTable::getList([
			'select' => ['ID', 'LID', 'PUBLIC', 'DELETED', 'MARKUP_MANIFEST'],
			'filter' => ['=ID' => $blockId],
		])->fetch();
		if (!$block || $block['DELETED'] === 'Y')
		{
			return $result->addError(new Error('The file is not found.', self::ERROR_NOT_FOUND));
		}

		$manifest = Manifest::fromJson((string)$block['MARKUP_MANIFEST']);
		$file = $manifest ? $this->findAsset($manifest, $fileId) : null;
		if (!$file || !FileStore::isPackageFile($fileId))
		{
			return $result->addError(new Error('The file is not found.', self::ERROR_NOT_FOUND));
		}

		$isPublic = $expiresAt === null;
		if ($isPublic && ($block['PUBLIC'] !== 'Y' || !$this->isLandingPublished((int)$block['LID'])))
		{
			return $result->addError(new Error('The file is not published.', self::ERROR_NOT_PUBLISHED));
		}

		$type = (string)($file['type'] ?? '');
		if ($type === '' || $type === Manifest::TYPE_UNKNOWN)
		{
			$type = FileStore::getContentType($fileId);
		}

		return $result->setData([
			'manifest' => $manifest,
			'file' => $file,
			'type' => $type,
			'isText' => in_array($type, self::TEXT_TYPES, true),
			'isPublic' => $isPublic,
		]);
	}

	/**
	 * Only the assets are served: the entry goes into the frame data.
	 */
	private function findAsset(Manifest $manifest, int $fileId): ?array
	{
		foreach ($manifest->getAssets() as $asset)
		{
			if ((int)$asset['fileId'] === $fileId)
			{
				return $asset;
			}
		}

		return null;
	}

	private function isLandingPublished(int $landingId): bool
	{
		if ($landingId <= 0)
		{
			return false;
		}

		// the query bypasses LandingTable::getList(): its scope filter by site type and its switchable
		// deleted filter depend on the request, the answer here must not
		$landing = LandingTable::query()
			->setSelect(['ID'])
			->where('ID', $landingId)
			->where('ACTIVE', 'Y')
			->where('DELETED', 'N')
			->where('SITE.ACTIVE', 'Y')
			->where('SITE.DELETED', 'N')
			->setLimit(1)
			->fetch()
		;

		return (bool)$landing;
	}
}
