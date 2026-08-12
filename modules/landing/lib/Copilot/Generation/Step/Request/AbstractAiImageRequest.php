<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step\Request;

use Bitrix\Landing\Block;
use Bitrix\Landing\Copilot\Generation\Request;
use Bitrix\Landing\Copilot\Generation\Type\RequestEntities;
use Bitrix\Landing\Copilot\Generation\Type\RequestEntityDto;
use Bitrix\Landing\Copilot\Model\RequestToEntitiesTable;
use Bitrix\Landing\File;
use Bitrix\Landing\Manager;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Web\DOM;
use Bitrix\Main\Web\Uri;

abstract class AbstractAiImageRequest extends RequestMultiple
{
	protected const DEFAULT_IMAGE_ASPECT = 'square';
	protected const SUPPORTED_IMAGE_ASPECTS = ['landscape', 'portrait', 'square'];

	/**
	 * @var array<string, array{alt: string, aspect: string}>
	 */
	protected array $entityMeta = [];
	/**
	 * @var array<int, array{entity: RequestEntityDto, meta: array{alt: string, aspect: string}}>
	 */
	protected array $entitiesByRequestId = [];

	protected function appendEntityFilter(object $filter, int $landingId, int $blockId, string $nodeCode, int $position): void
	{
		$filter->where(
			Query::filter()
				->where('LANDING_ID', '=', $landingId)
				->where('BLOCK_ID', '=', $blockId)
				->where('NODE_CODE', '=', $nodeCode)
				->where('POSITION', '=', $position)
				->where('ENTITY_TYPE', '=', RequestEntities::Image->value)
		);
	}

	protected function bindExistingRequests(object $filter): void
	{
		$res = RequestToEntitiesTable::query()
			->setSelect([
				'REQUEST_ID',
				'LANDING_ID',
				'BLOCK_ID',
				'NODE_CODE',
				'POSITION',
			])
			->where($filter)
			->where('STEP_REF.GENERATION_ID', '=', $this->generation->getId())
			->where('STEP_REF.STEP', '=', $this->stepId)
			->where('REQUEST_REF.DELETED', '=', 'N')
			->exec()
		;
		while ($row = $res->fetch())
		{
			$key = static::createEntityKey(
				(int)$row['LANDING_ID'],
				(int)$row['BLOCK_ID'],
				(string)$row['NODE_CODE'],
				(int)$row['POSITION'],
			);
			if (!isset($this->entities[$key]))
			{
				continue;
			}

			$requestId = (int)$row['REQUEST_ID'];
			$this->entities[$key]->requestId = $requestId;
			$this->bindEntityRequest($key, $requestId);
		}
	}

	protected static function createEntityKey(
		int $landingId,
		int $blockId,
		string $nodeCode,
		int $position,
	): string
	{
		return "l{$landingId}_b{$blockId}_n{$nodeCode}_p{$position}";
	}

	/**
	 * @return array{entity: RequestEntityDto, meta: array{alt: string, aspect: string}}|null
	 */
	protected function getEntityDataByRequestId(?int $requestId): ?array
	{
		if ($requestId === null || $requestId <= 0)
		{
			return null;
		}

		if ($this->entitiesByRequestId === [])
		{
			$this->getEntitiesToRequest();
		}

		return $this->entitiesByRequestId[$requestId] ?? null;
	}

	/**
	 * @return array{alt: string, aspect: string}
	 */
	protected function buildDefaultEntityMeta(): array
	{
		return [
			'alt' => '',
			'aspect' => self::DEFAULT_IMAGE_ASPECT,
		];
	}

	protected function bindEntityRequest(string $entityKey, int $requestId): void
	{
		if ($requestId <= 0)
		{
			return;
		}

		$entity = $this->entities[$entityKey] ?? null;
		if (!$entity)
		{
			return;
		}

		$this->entitiesByRequestId[$requestId] = [
			'entity' => $entity,
			'meta' => $this->entityMeta[$entityKey] ?? $this->buildDefaultEntityMeta(),
		];
	}

	protected function loadBlock(int $blockId): ?Block
	{
		if ($blockId <= 0)
		{
			return null;
		}

		$block = new Block($blockId);

		return $block->exist() ? $block : null;
	}

	protected function buildImageValueFromRequest(Request $request, string $alt): ?array
	{
		$result = $request->getResult();
		if (!is_array($result) || $result === [])
		{
			return null;
		}

		$url = trim((string)reset($result));
		if ($url === '')
		{
			return null;
		}

		$fileData = $this->resolveImageFileData($url);
		if ($fileData === null)
		{
			return null;
		}

		$fileId = (int)($fileData['id'] ?? 0);
		$src = trim((string)($fileData['src'] ?? ''));
		if ($fileId <= 0 || $src === '')
		{
			return null;
		}

		return $this->createImageValue($fileId, $src, $alt);
	}

	protected function extractFileId(string $url): int
	{
		return (int)($this->getUrlQueryParams($url)['id'] ?? 0);
	}

	/**
	 * @return array{id: int, src: string}|null
	 */
	protected function resolveImageFileData(string $url): ?array
	{
		$fileId = $this->extractFileId($url);
		if ($fileId > 0)
		{
			return $this->getFileDataById($fileId);
		}

		return $this->saveImageByUrl($url);
	}

	/**
	 * @return array{id: int, src: string}|null
	 */
	protected function getFileDataById(int $fileId): ?array
	{
		if ($fileId <= 0)
		{
			return null;
		}

		$file = \CFile::GetFileArray($fileId);
		if (!is_array($file) || empty($file['SRC']))
		{
			return null;
		}

		return [
			'id' => $fileId,
			'src' => (string)$file['SRC'],
		];
	}

	/**
	 * @return array{id: int, src: string}|null
	 */
	protected function saveImageByUrl(string $url): ?array
	{
		$savedFile = Manager::savePicture($url);
		if (!is_array($savedFile) || empty($savedFile['ID']) || empty($savedFile['SRC']))
		{
			return null;
		}

		return [
			'id' => (int)$savedFile['ID'],
			'src' => (string)$savedFile['SRC'],
		];
	}

	/**
	 * @return array{id: int, id2x: int, src: string, src2x: string, alt: string, type: string}
	 */
	protected function createImageValue(int $fileId, string $src, string $alt): array
	{
		return [
			'src' => $src,
			'src2x' => $src,
			'id' => $fileId,
			'id2x' => $fileId,
			'alt' => $alt,
			'type' => 'image',
		];
	}

	protected function getUrlQueryParams(string $url): array
	{
		if ($url === '')
		{
			return [];
		}

		$params = [];
		mb_parse_str((new Uri($url))->getQuery(), $params);

		return is_array($params) ? $params : [];
	}

	protected function applyImageToBlock(Block $block, string $selector, int $position, array $imageValue): bool
	{
		if ($selector === '' || $position < 0)
		{
			return false;
		}

		$expectedSrc = trim((string)($imageValue['src'] ?? ''));
		if ($expectedSrc === '')
		{
			return false;
		}

		$fileIds = array_values(array_unique(array_filter([
			(int)($imageValue['id'] ?? 0),
			(int)($imageValue['id2x'] ?? 0),
		])));
		if (!empty($fileIds))
		{
			File::addToBlock($block->getId(), $fileIds);
		}

		$updatedByNodes = $block->updateNodes([
			$selector => [
				$position => $imageValue,
			],
		]) && $block->save();
		if (!$updatedByNodes)
		{
			return false;
		}

		return $this->isImageAppliedToSelector($block, $selector, $position, $expectedSrc);
	}

	protected function isImageAppliedToSelector(object $block, string $selector, int $position, string $expectedSrc): bool
	{
		if ($expectedSrc === '' || $selector === '' || $position < 0)
		{
			return false;
		}

		try
		{
			$doc = $block->getDom(true);
			if (!$doc instanceof DOM\Document)
			{
				return false;
			}

			$nodes = $doc->querySelectorAll($selector);
			if (!isset($nodes[$position]))
			{
				return false;
			}

			return trim((string)$nodes[$position]->getAttribute('src')) === $expectedSrc;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	protected function normalizeAspectValue(string $aspect): string
	{
		$aspect = mb_strtolower(trim($aspect));

		return in_array($aspect, self::SUPPORTED_IMAGE_ASPECTS, true)
			? $aspect
			: self::DEFAULT_IMAGE_ASPECT
		;
	}
}
