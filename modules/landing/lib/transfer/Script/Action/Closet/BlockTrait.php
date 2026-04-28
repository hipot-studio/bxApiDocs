<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action\Closet;

use Bitrix\Landing\Block;
use Bitrix\Landing\File;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Repo;
use Bitrix\Landing\Node;
use Bitrix\Landing\Transfer\AppConfiguration;
use Bitrix\Landing\Transfer\Requisite\Context;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;
use Bitrix\Main\ArgumentException;
use Bitrix\Rest\Configuration\Structure;

/** @property Context $context */
trait BlockTrait
{
	private Structure $structure;

	protected function importBlocks(Landing $landing): void
	{
		$data = $this->context->getData();
		$ratio = $this->context->getRatio();
		$blocks = $ratio->get(RatioPart::Blocks) ?? [];
		$blocksPending = $ratio->get(RatioPart::BlocksPending) ?? [];

		foreach ($data['BLOCKS'] as $oldBlockId => $block)
		{
			if (is_array($block) && !empty($block))
			{
				$pending = false;
				$newBlockId = $this->importBlock(
					$landing,
					$block,
					$pending
				);
				$blocks[$oldBlockId] = $newBlockId;
				if ($pending)
				{
					$blocksPending[] = $newBlockId;
				}
			}
		}

		$this->context->getRatio()->set(RatioPart::Blocks, $blocks);
		$this->context->getRatio()->set(RatioPart::BlocksPending, $blocksPending);
	}

	/**
	 * Imports block in to the landing and returns it new id.
	 * @param Landing $landing Landing instance.
	 * @param array $block Block data.
	 * @param bool &$pending This block in pending mode.
	 * @throws ArgumentException
	 */
	private function importBlock(
		Landing $landing,
		array $block,
		bool &$pending = false
	): ?int
	{
		static $sort = 0;
		static $appChecked = [];

		if ($this->context->getStructure() === null)
		{
			return null;
		}
		$this->structure = $this->context->getStructure();

		$blockId = 0;

		// if this is a REST block
		if (
			isset($block['repo_block']['app_code'], $block['repo_block']['xml_id'])
			&& is_string($block['repo_block']['app_code'])
			&& is_string($block['repo_block']['xml_id'])
		)
		{
			unset($block['code']);

			$repoId = $this->getRepoId(
				$block['repo_block']['app_code'],
				$block['repo_block']['xml_id']
			);
			if ($repoId)
			{
				$block['code'] = 'repo_' . $repoId;
			}

			// force append REST blocks
			if (
				!isset($block['code'])
				&& !empty($block['repo_info'])
			)
			{
				$appCode = $block['repo_block']['app_code'];
				if (!array_key_exists($appCode, $appChecked))
				{
					$appChecked[$appCode] = Repo::getAppByCode($appCode);
				}

				if ($appChecked[$appCode])
				{
					$repoInfo = $block['repo_info'];
					$res = Repo::add([
						'APP_CODE' => $block['repo_block']['app_code'],
						'XML_ID' => $block['repo_block']['xml_id'],
						'NAME' => $repoInfo['NAME'] ?? null,
						'DESCRIPTION' => $repoInfo['DESCRIPTION'] ?? null,
						'SECTIONS' => $repoInfo['SECTIONS'] ?? null,
						'PREVIEW' => $repoInfo['PREVIEW'] ?? null,
						'MANIFEST' => serialize(unserialize($repoInfo['MANIFEST'] ?? '', ['allowed_classes' => false])),
						'CONTENT' => $repoInfo['CONTENT'] ?? null,
					]);
					if ($res->isSuccess())
					{
						$block['code'] = 'repo_' . $res->getId();
					}
				}
			}

			if (!isset($block['code']))
			{
				$pending = true;
				$blockId = $landing->addBlock(
					AppConfiguration::SYSTEM_BLOCK_REST_PENDING,
					[
						'PUBLIC' => 'N',
						'SORT' => $sort,
						'ANCHOR' => $block['anchor'] ?? '',
						'INITIATOR_APP_CODE' => $block['repo_block']['app_code'],
					]
				);
				if ($blockId)
				{
					$sort += 500;
					$blockInstance = $landing->getBlockById($blockId);
					if ($blockInstance)
					{
						if (isset($block['nodes']) && is_array($block['nodes']))
						{
							$block['nodes'] = $this->addFilesToBlock(
								$blockInstance,
								$block['nodes'],
								true
							);
						}
						$blockInstance->updateNodes([
							AppConfiguration::SYSTEM_COMPONENT_REST_PENDING => [
								'BLOCK_ID' => $blockId,
								'DATA' => base64_encode(serialize($block)),
							],
						]);
						$blockInstance->save();
					}
				}

				return $blockId;
			}
		}

		if (!isset($block['code']))
		{
			return $blockId;
		}

		// add block to the landing
		$blockFields = [
			'PUBLIC' => 'N',
			'SORT' => $sort,
			'ANCHOR' => $block['anchor'] ?? '',
			'INITIATOR_APP_CODE' => $block['repo_block']['app_code'] ?? null,
		];
		if ($block['full_content'] ?? null)
		{
			$blockFields['CONTENT'] = str_replace(
				['<?', '?>'],
				['< ?', '? >'],
				$block['full_content']
			);
		}
		if ($block['designed'] ?? null)
		{
			$blockFields['DESIGNED'] = 'Y';
		}
		$blockId = $landing->addBlock(
			$block['code'],
			$blockFields
		);
		if ($blockId)
		{
			$sort += 500;
			$blockInstance = $landing->getBlockById($blockId);
			if (isset($block['nodes']) && is_array($block['nodes']))
			{
				$block['nodes'] = $this->addFilesToBlock(
					$blockInstance,
					$block['nodes'],
				);
			}
			if ($block['meta']['FAVORITE_META'] ?? [])
			{
				$favoriteMeta = $block['meta']['FAVORITE_META'];
				if ($block['repo_block']['app_code'] ?? null)
				{
					$favoriteMeta['tpl_code'] = $block['repo_block']['app_code'];
				}
				if ((int)($favoriteMeta['preview'] ?? 0) > 0)
				{
					$unpackFile = $this->structure->getUnpackFile($favoriteMeta['preview']);
					if ($unpackFile)
					{
						$favoriteMeta['preview'] = AppConfiguration::saveFile($unpackFile);
						File::addToBlock($blockInstance->getId(), $favoriteMeta['preview']);
					}
					if (!$favoriteMeta['preview'])
					{
						unset($favoriteMeta['preview']);
					}
				}
				$blockInstance->changeFavoriteMeta($favoriteMeta);
				Block::clearRepositoryCache();
			}
			if ($blockFields['CONTENT'] ?? null)
			{
				$blockInstance->saveContent($blockFields['CONTENT'], $block['designed'] ?? false);
			}
			Block::saveDataToBlock($blockInstance, $block);
			$blockInstance->save();
			// if block is favorite
			if ((int)($block['meta']['LID'] ?? -1) === 0)
			{
				$blockInstance->changeLanding(0);
			}
		}

		return $blockId;
	}

	/**
	 * Finds repository block and return it id.
	 * @param string $appCode Application code.
	 * @param string $xmlId External id application.
	 * @return int|null
	 */
	private function getRepoId(string $appCode, string $xmlId): ?int
	{
		static $items = null;

		if ($items === null)
		{
			$items = [];
			$res = Repo::getList([
				'select' => [
					'ID', 'APP_CODE', 'XML_ID',
				],
			]);
			while ($row = $res->fetch())
			{
				$items[$row['APP_CODE'] . '@' . $row['XML_ID']] = $row['ID'];
			}
		}

		return $items[$appCode . '@' . $xmlId] ?? null;
	}

	/**
	 * Checks files in update data and replace files paths.
	 * @param Block $block Block instance.
	 * @param array $data Data for passing to updateNodes.
	 * Additional instance for unpacking files.
	 * @param bool $ignoreManifest Ignore manifest for detecting files.
	 * @return array
	 */
	private function addFilesToBlock(
		Block $block,
		array $data,
		bool $ignoreManifest = false
	): array
	{
		if (!$ignoreManifest)
		{
			$manifest = $block->getManifest();
		}

		foreach ($data as $selector => &$nodes)
		{
			if (!$ignoreManifest)
			{
				if (!isset($manifest['nodes'][$selector]))
				{
					continue;
				}
				if (
					$manifest['nodes'][$selector]['type'] !== Node\Type::IMAGE
					&& $manifest['nodes'][$selector]['type'] !== Node\Type::STYLE_IMAGE
				)
				{
					continue;
				}
			}
			foreach ($nodes as &$node)
			{
				foreach (['', '2x'] as $x)
				{
					$index = 'id' . $x;
					if (isset($node[$index]))
					{
						$unpackedFile = $this->structure->getUnpackFile($node['id' . $x]);
						if ($unpackedFile)
						{
							$newFileId = AppConfiguration::saveFile($unpackedFile);
							if ($newFileId)
							{
								$newFilePath = File::getFilePath($newFileId);
								if ($newFilePath)
								{
									File::addToBlock($block->getId(), $newFileId);
									$node['id' . $x] = $newFileId;
									$node['src' . $x] = $newFilePath;
								}
							}
						}
					}
				}
			}
		}

		return $data;
	}
}