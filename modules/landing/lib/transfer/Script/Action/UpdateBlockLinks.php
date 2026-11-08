<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Debug;
use Bitrix\Landing\Internals\BlockTable;
use Bitrix\Landing\Sanitizer;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;

class UpdateBlockLinks extends Blank
{
	public function action(): void
	{
		$ratio = $this->context->getRatio();
		$blocks = $ratio->get(RatioPart::Blocks) ?? [];
		$landings = $ratio->get(RatioPart::Landings) ?? [];
		$blocksPending = $ratio->get(RatioPart::BlocksPending) ?? [];

		$replace = static::buildReplaceMap($blocks, $landings);
		if (empty($replace))
		{
			return;
		}

		$res = BlockTable::getList([
			'select' => [
				'ID', 'CONTENT',
			],
			'filter' => [
				'ID' => array_values($blocks),
				'!ID' => $blocksPending,
			],
		]);
		while ($row = $res->fetch())
		{
			$count = 0;
			$content = preg_replace(
				array_keys($replace),
				array_values($replace),
				$row['CONTENT'],
				-1,
				$count
			);
			if (!$count || $content === null)
			{
				continue;
			}
			// the rewrite must never produce a php open tag the stored content does not carry;
			// the log is the only place such an attempt shows up, and the content stays out of it
			$publishable = Sanitizer::publishableContent($row['CONTENT'], $content);
			if ($publishable !== $content)
			{
				Debug::log(
					$row['ID'],
					'link rewrite of the imported block produced a php open tag'
					. '; php open tags: ' . Sanitizer::countUnneutralizedPhpOpenTags($row['CONTENT'])
					. ' -> ' . Sanitizer::countUnneutralizedPhpOpenTags($content)
					. '; rejected content md5: ' . md5($content),
					'LANDING_IMPORT_PHP_TAG'
				);

				continue;
			}
			if ($content !== $row['CONTENT'])
			{
				BlockTable::update($row['ID'], [
					'CONTENT' => $content,
				]);
			}
		}
	}

	/**
	 * Builds the map "regex of the old #block/#landing reference => replacement with the new id".
	 * An old id becomes a part of the regex, so only an integer id of the archive is accepted:
	 * an id with regex syntax turns the replacement into a way to rebuild a php tag out of
	 * sanitized content (mantis 257039).
	 * Bound late only so a test is able to substitute the map: the scenarios create their actions
	 * from ::class literals, an archive names no action class.
	 * @param array $blocks Old block id => new block id.
	 * @param array $landings Old landing id => new landing id.
	 * @return array<string, string>
	 */
	protected static function buildReplaceMap(array $blocks, array $landings): array
	{
		$replace = [];
		foreach (['block' => $blocks, 'landing' => $landings] as $prefix => $ids)
		{
			$ids = array_filter($ids, 'is_int', ARRAY_FILTER_USE_KEY);
			ksort($ids);
			foreach (array_reverse($ids, true) as $oldId => $newId)
			{
				$pattern = '/#' . $prefix . preg_quote((string)$oldId, '/') . '([^\d]{1})/';
				$replace[$pattern] = '#' . $prefix . (int)$newId . '$1';
			}
		}

		return $replace;
	}
}
