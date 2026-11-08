<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\MyCompany\Dto;

use Bitrix\Mobile\Dto\Dto;

/**
 * Response of the section action: the composition of the section and the data of every block of it,
 * gathered for one answer. The screen is drawn from this answer alone, so nothing here waits for a
 * request of its own. Unavailable section is a field of the answer, not an error.
 */
final class MyCompanyData extends Dto
{
	public bool $available;

	/** @var string[] block codes in display order */
	public array $blocks;

	/**
	 * Data of the blocks, keyed by the block code. A block whose card is a static view, or one with
	 * nothing to show, has no record here: the client draws a card per code of `blocks` and reads its
	 * data by that code.
	 *
	 * @var array<string, array>
	 */
	public array $blockData;

	/** @var array{name: string, url: string, theme: ?array} */
	public array $portal;

	/**
	 * The QA showcase of the tariff plate states draws the tariff block instead of the plate. The
	 * signal travels with the section so it belongs to the portal that answered, not to the map of
	 * feature flags the device keeps for every portal at once.
	 */
	public bool $tariffShowcase;
}
