<?php

namespace Bitrix\BIConnector\Integration\UI\EntitySelector;

use Bitrix\BIConnector\Internal\Digest\DigestPeriod;
use Bitrix\BIConnector\Internal\Digest\DigestPeriodSelection;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\Date;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\Tab;

/**
 * Periods a digest agent can cover, offered to the setup wizard as a selector.
 *
 * The list is fixed and short, so it is filled in whole and searched in place: there is nothing to load
 * on demand and nothing that differs between two openings of the wizard. A fixed range of dates is never
 * part of the list: the dialog footer lets a user set one by hand, and a range already chosen is restored
 * as the selected value, titled with its bounds.
 */
class DigestPeriodProvider extends BaseProvider
{
	public const ENTITY_ID = 'biconnector-digest-period';

	private const TAB_ID = 'periods';
	private const FOOTER = 'BX.BIConnector.EntitySelector.DigestPeriodFooter';

	public function __construct(array $options = [])
	{
		parent::__construct();

		$this->options = $options;
	}

	public function isAvailable(): bool
	{
		global $USER;

		return is_object($USER) && $USER->isAuthorized();
	}

	public function fillDialog(Dialog $dialog): void
	{
		$dialog->addTab(new Tab([
			'id' => self::TAB_ID,
			'title' => Loc::getMessage('BI_DIGEST_PERIOD_SELECTOR_TAB'),
			'stub' => true,
		]));

		$dialog->addItems($this->getItems([]));
		$dialog->setFooter(self::FOOTER);
	}

	/**
	 * @param string[] $ids Period codes to resolve; an empty list stands for all of them.
	 * @return Item[]
	 */
	public function getItems(array $ids): array
	{
		$items = [];
		foreach (DigestPeriod::cases() as $period)
		{
			if ($ids !== [] && !in_array($period->value, $ids, true))
			{
				continue;
			}

			$items[] = new Item([
				'id' => $period->value,
				'entityId' => self::ENTITY_ID,
				'title' => $period->title(),
				'tabs' => self::TAB_ID,
			]);
		}

		return $items;
	}

	/**
	 * @param string[] $ids
	 * @return Item[]
	 */
	public function getSelectedItems(array $ids): array
	{
		$items = [];
		foreach ($ids as $id)
		{
			$item = $this->makeSelectedItem((string)$id);
			if ($item !== null)
			{
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * A legacy rolling code comes back as the entry it is read as, so the setup shows the period the agent
	 * actually covers and saves it under the current code.
	 */
	private function makeSelectedItem(string $id): ?Item
	{
		$selection = DigestPeriodSelection::fromStored($id);
		$bounds = $selection?->toBounds();
		if ($bounds === null)
		{
			return $selection === null ? null : ($this->getItems([$selection->toStored()])[0] ?? null);
		}

		return new Item([
			'id' => $id,
			'entityId' => self::ENTITY_ID,
			'title' => $this->formatDate($bounds['from']) . ' – ' . $this->formatDate($bounds['to']),
		]);
	}

	private function formatDate(string $date): string
	{
		return (new Date($date, 'Y-m-d'))->toString();
	}
}
