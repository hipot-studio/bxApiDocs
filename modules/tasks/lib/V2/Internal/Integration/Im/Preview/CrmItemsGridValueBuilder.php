<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Preview;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Ui\Preview\TextTruncator;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItem;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Provider\CrmItemProvider;
use Bitrix\Tasks\V2\Internal\Service\TariffService;

Loc::loadLanguageFile(dirname(__DIR__, 5) . '/ui/preview/task.php');

final class CrmItemsGridValueBuilder
{
	public const MAX_ITEMS = 5;
	public const MAX_ITEM_LENGTH = 100;
	// Grid values keep the im line break marker: it is converted to [BR] by \CIMMessageParam.
	private const LINE_SEPARATOR = '#BR#';
	private const IM_BB_TAG_PATTERN = '/\[(?:\/)?(?:'
		. 'attach|br|call|chat|context|department|dialog|disk|dislike|document|h[12]|hr|icode|icon|like|'
		. 'pch|put|rating|send|source|th|timestamp'
		. ')(?=[\s=\]])[^\]]*]/iu';

	public function __construct(
		private readonly CrmItemProvider $crmItemProvider,
		private readonly TariffService $tariffService,
	)
	{
	}

	public function build(array $crmItemIds, int $userId, string $moreLink): string
	{
		if (empty($crmItemIds))
		{
			return '';
		}

		$featureId = $this->tariffService->getCrmIntegrationFeatureId();
		if (!$this->tariffService->isEnabled($featureId))
		{
			return '';
		}

		$preview = $this->crmItemProvider->getPreviewByIds(
			$crmItemIds,
			$userId,
			self::MAX_ITEMS,
		);
		if ($preview->items->isEmpty())
		{
			return '';
		}

		$lines = [];
		foreach ($preview->items as $item)
		{
			$lines[] = $this->formatItem($item);
		}

		$rest = $preview->total - $preview->items->count();
		if ($rest > 0)
		{
			$moreText = Loc::getMessage(
				'TASK_PREVIEW_FIELD_CRM_MORE',
				['#COUNT#' => $rest],
			);
			$moreLink = $this->sanitizeUrl($moreLink);
			$lines[] = "[URL={$moreLink}]{$moreText}[/URL]";
		}

		return implode(self::LINE_SEPARATOR, $lines);
	}

	private function formatItem(CrmItem $item): string
	{
		$typeName = $this->sanitizeText((string)$item->typeName);
		$title = $this->sanitizeText((string)$item->title);
		$text = implode(
			': ',
			array_filter(
				[$typeName, $title],
				static fn(string $part): bool => $part !== '',
			),
		);
		$text = TextTruncator::truncate($text, self::MAX_ITEM_LENGTH);

		if (($item->link ?? '') === '')
		{
			return $text;
		}

		$link = $this->sanitizeUrl($item->link);

		return "[URL={$link}]{$text}[/URL]";
	}

	private function sanitizeText(string $text): string
	{
		do
		{
			$previousLength = strlen($text);
			$text = (string)\CTextParser::clearAllTags($text);
			$text = (string)preg_replace(
				self::IM_BB_TAG_PATTERN,
				'',
				$text,
			);
			$text = $this->removeLineBreaks($text);
		}
		while (strlen($text) < $previousLength);

		return $text;
	}

	private function removeLineBreaks(string $text): string
	{
		$text = str_ireplace(self::LINE_SEPARATOR, '', $text);

		return (string)preg_replace('/[\r\n]+/', ' ', $text);
	}

	private function sanitizeUrl(string $url): string
	{
		return str_replace(
			["\r", "\n", '[', ']'],
			['', '', '%5B', '%5D'],
			$url,
		);
	}
}
