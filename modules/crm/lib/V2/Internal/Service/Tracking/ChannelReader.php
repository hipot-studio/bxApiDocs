<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Tracking;

use Bitrix\Crm\Tracking\Internals\SiteTable;
use Bitrix\Crm\Tracking\Internals\SourceFieldTable;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Channel;
use Bitrix\Crm\V2\Internal\Entity\Tracking\ChannelCode;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TrackingAccessDeniedException;
use Bitrix\Crm\V2\Internal\Repository\Tracking\SourceRepository;
use Bitrix\Main\Config\Option;

/**
 * The base channels of the end-to-end analytics and whether each is set up, decided the way the UI decides
 * it. The tables and the options are read themselves: nothing is cached and nothing is written.
 *
 * @internal
 */
final class ChannelReader
{
	/** The deal field the remote orders are bound by. */
	private const ORDER_DEAL_FIELD_OPTION = '~tracking_order_field';

	public function __construct(
		private readonly TrackingAccess $access = new TrackingAccess(),
		private readonly ChannelCatalog $catalog = new ChannelCatalog(),
		private readonly SourceRepository $sources = new SourceRepository(),
	)
	{
	}

	/**
	 * @return list<Channel> in the order of the catalog
	 * @throws TrackingAccessDeniedException
	 */
	public function list(int $userId): array
	{
		$this->access->assertCanRead($userId);

		$channels = [];
		foreach ($this->catalog->list() as $entry)
		{
			$channels[] = new Channel(
				code: $entry['code'],
				name: $entry['name'],
				isConfigurable: $entry['isConfigurable'],
				isConfigured: $this->isConfigured($entry['code']),
			);
		}

		return $channels;
	}

	private function isConfigured(ChannelCode $code): bool
	{
		return match ($code)
		{
			ChannelCode::Site24, ChannelCode::Shop24, ChannelCode::CrmShop => true,
			ChannelCode::Site => $this->hasReadySite(),
			ChannelCode::Call => $this->sources->hasActiveWithField(SourceFieldTable::FIELD_PHONE),
			ChannelCode::Mail => $this->sources->hasActiveWithField(SourceFieldTable::FIELD_EMAIL),
			// An empty value and "0" mean no field, as for legacy.
			ChannelCode::Order => (bool)Option::get('crm', self::ORDER_DEAL_FIELD_OPTION),
			default => false,
		};
	}

	private function hasReadySite(): bool
	{
		$row =
			SiteTable::query()
				->setSelect(['ID'])
				->where('IS_INSTALLED', 'Y')
				->where('ACTIVE', 'Y')
				->setLimit(1)
				->fetch()
		;

		return $row !== false;
	}
}
