<?php
namespace Bitrix\Landing\Copilot\Services\Manifest;

use Bitrix\Landing\AI\SiteBuilder\Html\AiBlockHtmlPayloadProcessor;
use Bitrix\Landing\Block;
use Bitrix\Landing\Error;
use Bitrix\Landing\Internals\BlockTable;
use Bitrix\Landing\Landing;
use Bitrix\Landing\PublicActionResult;
use Bitrix\Landing\Repo;
use Bitrix\Landing\Sanitizer;

class AiBlockTemplateFactory
{
	public function createTemplate(string $html, array $meta = []): PublicActionResult
	{
		$result = new PublicActionResult();
		$error = new Error();

		// html of an AI template is model output, so php in it is user content, not block source
		if (Sanitizer::containsUnneutralizedPhpOpenTag($html))
		{
			$error->addError('AI_HTML_PHP_FORBIDDEN', 'PHP is not allowed in AI block html.');
			$result->setError($error);

			return $result;
		}

		$html = $this->sanitizeHtml($html);

		$name = trim((string)($meta['name'] ?? ''));
		if ($name === '')
		{
			$error->addError('AI_NAME_EMPTY', 'Block name is required.');
			$result->setError($error);
			return $result;
		}

		$sections = self::normalizeSections($meta['sections'] ?? ['ai']);
		$builder = $this->createManifestBuilder();
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
			$exists = $this->repoGetList([
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

		$repoRes = $this->repoAdd([
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

	public function addToLanding(
		int $landingId,
		int $repoOrTemplateId,
		int $afterId = 0,
		int $beforeId = 0
	): PublicActionResult
	{
		$result = new PublicActionResult();
		$error = new Error();

		$editModeBefore = Landing::getEditMode();
		Landing::setEditMode(true);
		try
		{
			$landing = $this->createLandingInstance($landingId);
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

			$repoId = $this->resolveRepoId($repoOrTemplateId, $error);
			if (!$repoId)
			{
				$result->setError($error);
				return $result;
			}

			$repoManifest = $this->repoGetBlock($repoId);
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

	private function resolveRepoId(int $repoOrTemplateId, Error $error): ?int
	{
		$template = $this->blockTableGetById($repoOrTemplateId)->fetch();
		if ($template && (int)$template['LID'] === 0)
		{
			if (!preg_match(Block::REPO_MASK, (string)$template['CODE'], $matches))
			{
				$error->addError('AI_TEMPLATE_CODE', 'Template block code is invalid.');
				return null;
			}

			return (int)$matches[1];
		}

		$repo = $this->repoGetById($repoOrTemplateId)->fetch();
		if (!$repo)
		{
			$error->addError('AI_REPO_NOT_FOUND', 'Repo entry not found.');
			return null;
		}

		return (int)$repo['ID'];
	}

	protected function sanitizeHtml(string $html): string
	{
		return AiBlockHtmlPayloadProcessor::sanitizeHtml($html);
	}

	protected function createManifestBuilder(): Builder
	{
		return new Builder();
	}

	protected function repoGetList(array $params)
	{
		return Repo::getList($params);
	}

	protected function repoAdd(array $fields)
	{
		return Repo::add($fields);
	}

	protected function repoGetBlock(int $repoId): array
	{
		return Repo::getBlock($repoId);
	}

	protected function repoGetById(int $repoId)
	{
		return Repo::getById($repoId);
	}

	protected function blockTableGetById(int $blockId)
	{
		return BlockTable::getById($blockId);
	}

	protected function createLandingInstance(int $landingId): Landing
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
}
