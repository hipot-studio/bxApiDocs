<?php
namespace Bitrix\Landing\PublicAction;

use Bitrix\Landing\AI\SiteBuilder\Html\AiBlockHtmlPayloadProcessor;
use Bitrix\Landing\Assets\PreProcessing;
use Bitrix\Landing\Block;
use Bitrix\Landing\Copilot\Services\Manifest\Builder;
use Bitrix\Landing\Countdown;
use Bitrix\Landing\Error;
use Bitrix\Landing\Internals\BlockTable;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Repo;
use Bitrix\Landing\PublicActionResult;
use Bitrix\Landing\History;

class AiBlock
{
	public static function createTemplate(string $html, array $meta = []): PublicActionResult
	{
		$result = new PublicActionResult();
		$error = new Error();

		$html = static::sanitizeHtml($html);

		$name = trim((string)($meta['name'] ?? ''));
		if ($name === '')
		{
			$error->addError('AI_NAME_EMPTY', 'Block name is required.');
			$result->setError($error);
			return $result;
		}

		$sections = self::normalizeSections($meta['sections'] ?? ['ai']);
		$builder = static::createManifestBuilder();
		$manifest = $builder->build($html, array_merge($meta, [
			'name' => $name,
			'sections' => $sections,
		]));
		$html = $builder->getSanitizedHtml() ?? trim($html);
		$manifest = self::prepareManifestAssets($manifest, $html);

		$xmlId = trim((string)($meta['xml_id'] ?? ''));
		if ($xmlId === '')
		{
			$xmlId = 'ai_' . md5($name . $html);
		}
		else
		{
			$exists = static::repoGetList([
				'select' => ['ID'],
				'filter' => [
					'=XML_ID' => $xmlId,
				],
			])->fetch();
			if ($exists)
			{
				$error->addError('AI_XML_ID_EXISTS', 'XML_ID already exists.');
				$result->setError($error);
				return $result;
			}
		}

		$repoRes = static::repoAdd([
			'XML_ID' => $xmlId,
			'NAME' => $name,
			'SECTIONS' => implode(',', $sections),
			'ACTIVE' => 'N',
			'CONTENT' => $html,
			'MANIFEST' => serialize($manifest),
			'APP_CODE' => null,
			'PREVIEW' => (string)($meta['preview'] ?? ''),
			'DESCRIPTION' => (string)($meta['description'] ?? ''),
		]);

		if (!$repoRes->isSuccess())
		{
			$error->addFromResult($repoRes);
			$result->setError($error);
			return $result;
		}

		$repoId = (int)$repoRes->getId();
		$result->setResult([
			'code' => 'repo_' . $repoId,
			'repoId' => $repoId,
			'templateBlockId' => 0,
		]);

		return $result;
	}

	public static function addToLanding(int $lid, int $repoOrTemplateId, int $afterId = 0, int $beforeId = 0): PublicActionResult
	{
		$result = new PublicActionResult();
		$error = new Error();

		$editModeBefore = Landing::getEditMode();
		Landing::setEditMode(true);
		try
		{
			$landing = static::createLandingInstance($lid);
			if (!$landing->exist())
			{
				$error->addError('AI_LANDING_NOT_FOUND', 'Landing not found.');
				$result->setError($error);
				return $result;
			}

			if (!$landing->canEdit())
			{
				$error->addError('AI_LANDING_ACCESS', 'Access denied.');
				$result->setError($error);
				return $result;
			}

			$repoId = self::resolveRepoId($repoOrTemplateId, $error);
			if (!$repoId)
			{
				$result->setError($error);
				return $result;
			}

			$repoManifest = static::repoGetBlock($repoId);
			$repoTypes = array_map('mb_strtolower', (array)($repoManifest['block']['type'] ?? []));
			if (!in_array('ai', $repoTypes, true))
			{
				$error->addError('AI_REPO_TYPE', 'Repo entry is not an AI block.');
				$result->setError($error);
				return $result;
			}

			$sort = self::getSortForLanding($landing, $afterId, $beforeId);
			$fields = [
				'PUBLIC' => 'N',
				'DESIGNED' => 'Y',
			];
			if ($sort !== null)
			{
				$fields['SORT'] = $sort;
			}

			$blockId = $landing->addBlock('repo_' . $repoId, $fields);
			if (!$blockId)
			{
				$error->copyError($landing->getError());
				if ($error->isEmpty())
				{
					$error->addError('AI_BLOCK_ADD', 'Failed to add block to landing.');
				}
				$result->setError($error);
				return $result;
			}

			$landing->resortBlocks();
			$landing->touch();

			$result->setResult([
				'blockId' => (int)$blockId,
			]);

			return $result;
		}
		finally
		{
			Landing::setEditMode($editModeBefore);
		}
	}

	private static function resolveRepoId(int $repoOrTemplateId, Error $error): ?int
	{
		$template = static::blockTableGetById($repoOrTemplateId)->fetch();
		if ($template && (int)$template['LID'] === 0)
		{
			if (!preg_match(Block::REPO_MASK, (string)$template['CODE'], $matches))
			{
				$error->addError('AI_TEMPLATE_CODE', 'Template block code is invalid.');
				return null;
			}

			return (int)$matches[1];
		}

		$repo = static::repoGetById($repoOrTemplateId)->fetch();
		if (!$repo)
		{
			$error->addError('AI_REPO_NOT_FOUND', 'Repo entry not found.');
			return null;
		}

		return (int)$repo['ID'];
	}

	protected static function sanitizeHtml(string $html): string
	{
		return AiBlockHtmlPayloadProcessor::sanitizeHtml($html);
	}

	protected static function createManifestBuilder(): Builder
	{
		return new Builder();
	}

	protected static function repoGetList(array $params)
	{
		return Repo::getList($params);
	}

	protected static function repoAdd(array $fields)
	{
		return Repo::add($fields);
	}

	protected static function repoGetBlock(int $repoId): array
	{
		return Repo::getBlock($repoId);
	}

	protected static function repoGetById(int $repoId)
	{
		return Repo::getById($repoId);
	}

	protected static function blockTableGetById(int $blockId)
	{
		return BlockTable::getById($blockId);
	}

	protected static function createLandingInstance(int $landingId): Landing
	{
		return Landing::createInstance($landingId);
	}

	private static function normalizeSections(mixed $sections): array
	{
		if (!is_array($sections))
		{
			$sections = [$sections];
		}

		$sections = array_values(array_filter($sections, static fn($value) => (string)$value !== ''));

		return $sections ?: ['ai'];
	}

	private static function prepareManifestAssets(array $manifest, string $html): array
	{
		if (!self::containsCrmFormEmbed($html))
		{
			return $manifest;
		}

		$ext = $manifest['assets']['ext'] ?? [];
		$ext = is_array($ext) ? $ext : [$ext];
		if (!in_array('landing_form', $ext, true))
		{
			$ext[] = 'landing_form';
		}
		$manifest['assets']['ext'] = $ext;

		return $manifest;
	}

	private static function containsCrmFormEmbed(string $html): bool
	{
		return str_contains($html, 'bitrix24forms') || str_contains($html, 'data-b24form');
	}

	private static function createTemplateBlock(int $repoId, string $html)
	{
		self::enableMutatorMode();

		return Block::add([
			'LID' => 0,
			'CODE' => 'repo_' . $repoId,
			'CONTENT' => $html,
			'ACTIVE' => 'Y',
			'PUBLIC' => 'N',
			'DELETED' => 'N',
			'DESIGNED' => 'Y',
			'ACCESS' => Block::ACCESS_X,
		]);
	}

	private static function createBlockFromTemplate(
		Landing $landing,
		array $template,
		int $afterId,
		Error $error,
		int $beforeId = 0
	): ?Block
	{
		self::enableMutatorMode();

		$content = (string)($template['CONTENT'] ?? '');
		if ($content === '')
		{
			$error->addError('AI_TEMPLATE_EMPTY', 'Template content is empty.');
			return null;
		}

		$sort = self::getSortForLanding($landing, $afterId, $beforeId);

		$fields = [
			'LID' => $landing->getId(),
			'CODE' => (string)$template['CODE'],
			'CONTENT' => $content,
			'ACTIVE' => 'Y',
			'PUBLIC' => 'N',
			'DESIGNED' => (string)($template['DESIGNED'] ?? 'Y'),
			'ACCESS' => (string)($template['ACCESS'] ?? Block::ACCESS_X),
			'SOURCE_PARAMS' => $template['SOURCE_PARAMS'] ?? [],
		];
		if ($sort !== null)
		{
			$fields['SORT'] = $sort;
		}

		$res = Block::add($fields);
		if (!$res->isSuccess())
		{
			$error->addFromResult($res);
			return null;
		}

		$block = new Block($res->getId());
		$manifest = $block->getManifest();

		if (!$block->getLocalAnchor())
		{
			$historyActive = History::isActive();
			History::deactivate();
			$block->setAnchor('b' . $block->getId());
			$historyActive ? History::activate() : History::deactivate();
		}

		PreProcessing::blockAddProcessing($block);

		if (
			isset($manifest['callbacks']['afteradd']) &&
			is_callable($manifest['callbacks']['afteradd'])
		)
		{
			$manifest['callbacks']['afteradd']($block);
		}

		if ($fields['SOURCE_PARAMS'])
		{
			$block->saveDynamicParams($fields['SOURCE_PARAMS']);
		}

		$blockContentOriginal = $block->getContent();
		$blockContent = self::prepareBlockContent($blockContentOriginal);
		if ($blockContent !== $blockContentOriginal)
		{
			$block->saveContent($blockContent);
		}

		if (isset($manifest['block']['app_code']))
		{
			$block->save([
				'INITIATOR_APP_CODE' => $manifest['block']['app_code'],
			]);
		}
		else
		{
			$block->save();
		}

		return $block;
	}

	private static function getSortForLanding(Landing $landing, int $afterId, int $beforeId = 0): ?int
	{
		$blocks = $landing->getBlocks();
		if ($beforeId > 0 && isset($blocks[$beforeId]))
		{
			return $blocks[$beforeId]->getSort() - 1;
		}

		if ($afterId > 0 && isset($blocks[$afterId]))
		{
			return $blocks[$afterId]->getSort() + 1;
		}

		if ($blocks)
		{
			$items = array_values($blocks);
			$last = array_pop($items);
			if ($last)
			{
				return $last->getSort() + 1;
			}
		}

		return null;
	}

	private static function prepareBlockContent(string $content): string
	{
		if (str_contains($content, '#YEAR#'))
		{
			$content = str_replace('#YEAR#', date('Y'), $content);
		}

		if (str_contains($content, '#COUNTDOWN#'))
		{
			$content = str_replace('#COUNTDOWN#', Countdown::getTimestamp(), $content);
		}

		if (!str_contains($content, '#DEFAULT_VIDEO_SRC#'))
		{
			return $content;
		}

		$replace = Manager::getZone() === 'ru'
			? [
				'#DEFAULT_VIDEO_SRC#' => 'data-src=""',
				'#DEFAULT_VIDEO_SOURCE#' => 'data-source=""',
				'#DEFAULT_VIDEO_PREVIEW#' => 'data-preview=""',
				'#DEFAULT_VIDEO_STYLE#' => 'style=""',
				'#DEFAULT_VIDEO_SRC_2#' => 'data-src=""',
				'#DEFAULT_VIDEO_SOURCE_2#' => 'data-source=""',
				'#DEFAULT_VIDEO_PREVIEW_2#' => 'data-preview=""',
				'#DEFAULT_VIDEO_STYLE_2#' => 'style=""',
			]
			: [
				'#DEFAULT_VIDEO_SRC#' => 'data-src="//www.youtube.com/embed/q4d8g9Dn3ww?autoplay=0&controls=1&loop=1&mute=0&rel=0"',
				'#DEFAULT_VIDEO_SOURCE#' => 'data-source="https://www.youtube.com/watch?v=q4d8g9Dn3ww"',
				'#DEFAULT_VIDEO_PREVIEW#' => 'data-preview="//img.youtube.com/vi/q4d8g9Dn3ww/sddefault.jpg"',
				'#DEFAULT_VIDEO_STYLE#' => 'style="background-image:url(//img.youtube.com/vi/q4d8g9Dn3ww/sddefault.jpg)"',
				'#DEFAULT_VIDEO_SRC_2#' => 'data-src="//www.youtube.com/embed/IISycTRZ-UA?autoplay=0&controls=1&loop=1&mute=0&rel=0"',
				'#DEFAULT_VIDEO_SOURCE_2#' => 'data-source="https://www.youtube.com/watch?v=IISycTRZ-UA"',
				'#DEFAULT_VIDEO_PREVIEW_2#' => 'data-preview="//img.youtube.com/vi/q4d8g9Dn3ww/sddefault.jpg"',
				'#DEFAULT_VIDEO_STYLE_2#' => 'style="background-image:url(//img.youtube.com/vi/IISycTRZ-UA/sddefault.jpg)"',
			];

		return str_replace(array_keys($replace), array_values($replace), $content);
	}

	private static function enableMutatorMode(): void
	{
		if (!defined('LANDING_MUTATOR_MODE'))
		{
			define('LANDING_MUTATOR_MODE', true);
		}
	}
}
