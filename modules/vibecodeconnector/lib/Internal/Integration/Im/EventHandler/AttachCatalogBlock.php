<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Im\EventHandler;

use Bitrix\Im\Model\MessageParamTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Entity\Url\UrlItem;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Web\Json;
use Bitrix\Vibecodeconnector\Internal\Integration\Im\ImGate;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Preview\BlackholeAppLinkDetector;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Preview\CatalogItemPreviewService;

final class AttachCatalogBlock
{
	private const MAX_LINKS_PER_MESSAGE = 10;

	public function __construct(
		private readonly CatalogItemPreviewService $previewService,
		private readonly BlackholeAppLinkDetector $detector,
		private readonly ImGate $imGate = new ImGate(),
	) {}

	public static function execute(int $messageId, array $fields): void
	{
		if (!str_contains((string)($fields['MESSAGE'] ?? ''), '://'))
		{
			return;
		}

		try
		{
			ServiceLocator::getInstance()->get(self::class)->handle($messageId, $fields);
		}
		catch (\Throwable)
		{
		}
	}

	public function handle(int $messageId, array $fields): void
	{
		$authorId = (int)($fields['AUTHOR_ID'] ?? 0);
		$text = (string)($fields['MESSAGE'] ?? '');

		if (
			$messageId <= 0
			|| $authorId <= 0
			|| (string)($fields['SYSTEM'] ?? '') === 'Y'
			|| in_array((string)($fields['MESSAGE_TYPE'] ?? ''), [Chat::IM_TYPE_SYSTEM, Chat::IM_TYPE_OPEN_LINE], true)
			|| (string)($fields['CHAT_EXTRANET'] ?? '') === 'Y'
			|| !str_contains($text, '://')
		)
		{
			return;
		}

		$attach = $this->buildAttach($text, $authorId);
		if ($attach === null)
		{
			return;
		}

		\CIMMessageParam::Set($messageId, [Params::ATTACH => [...$this->storedAttaches($messageId), $attach]]);
		\CIMMessageParam::SendPull($messageId);
	}

	private function buildAttach(string $text, int $authorId): ?\CIMMessageParamAttach
	{
		foreach (array_slice(array_unique(UrlItem::getUrlsFromText($text)), 0, self::MAX_LINKS_PER_MESSAGE) as $url)
		{
			$catalogItemId = $this->detector->detect($url);
			$preview = $catalogItemId === null ? null : $this->previewService->getPreview($catalogItemId, $authorId);
			if ($preview !== null)
			{
				return $this->imGate->buildAttach($preview) ?: null;
			}
		}

		return null;
	}

	private function storedAttaches(int $messageId): array
	{
		$rows = MessageParamTable::query()
			->setSelect(['PARAM_JSON'])
			->where('MESSAGE_ID', $messageId)
			->where('PARAM_NAME', Params::ATTACH)
			->whereNot('PARAM_JSON', '')
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;

		return array_map(static fn (array $row): array => Json::decode($row['PARAM_JSON']), $rows);
	}
}
