<?php
namespace Bitrix\Landing\Block;

/**
 * Names of the components the content of a block is allowed to call.
 *
 * The grammar of the block contract does not let the stored content pick a component on its own:
 * a call of a name beyond this list is out of the grammar and is never made, so a component able
 * to include an arbitrary file stays unreachable from the content of a block.
 *
 * The list holds the components of the blocks of the delivery. A new component block adds its
 * name here, in one line, and nothing else.
 */
final class AllowedComponents
{
	private const NAMES = [
		'bitrix:catalog.compare.list',
		'bitrix:catalog.compare.result',
		'bitrix:catalog.compilation',
		'bitrix:catalog.element',
		'bitrix:catalog.section',
		'bitrix:catalog.section.list',
		'bitrix:catalog.smart.filter',
		'bitrix:landing.blocks.catalog_section_with_carousel',
		'bitrix:landing.blocks.cmpfilter',
		'bitrix:landing.blocks.crm_contacts',
		'bitrix:landing.blocks.crm_requisites',
		'bitrix:landing.blocks.html',
		'bitrix:landing.blocks.mp_widget.about',
		'bitrix:landing.blocks.mp_widget.active_employees',
		'bitrix:landing.blocks.mp_widget.apps',
		'bitrix:landing.blocks.mp_widget.birthdays',
		'bitrix:landing.blocks.mp_widget.bp',
		'bitrix:landing.blocks.mp_widget.enterprise_west',
		'bitrix:landing.blocks.mp_widget.kb',
		'bitrix:landing.blocks.mp_widget.livefeed',
		'bitrix:landing.blocks.mp_widget.new_employees',
		'bitrix:landing.blocks.mp_widget.vibe_auto',
		'bitrix:landing.blocks.mp_widget.vibe_boards',
		'bitrix:landing.blocks.mp_widget.vibe_collaboration',
		'bitrix:landing.blocks.mp_widget.west_auto_footer_banner',
		'bitrix:landing.blocks.mp_widget.west_auto_header_banner',
		'bitrix:landing.blocks.mp_widget.west_auto_overview_block',
		'bitrix:landing.blocks.openlines',
		'bitrix:landing.blocks.tariffs',
		'bitrix:landing.rest.pending',
		'bitrix:sale.basket.basket',
		'bitrix:sale.basket.basket.line',
		'bitrix:sale.order.ajax',
		'bitrix:sale.order.checkout',
		'bitrix:sale.order.payment',
		'bitrix:sale.personal.section',
		'bitrix:salescenter.order.details',
		'bitrix:salescenter.payment.pay',
		'bitrix:search.title',
	];

	/**
	 * Tells whether the content of a block may call the component of that name.
	 * @param string $name Component name.
	 * @return bool
	 */
	public static function isAllowed(string $name): bool
	{
		return in_array($name, self::NAMES, true);
	}

	/**
	 * Digest of the whole list. A reader which remembers a verdict it made by these names keys that
	 * memory by this digest, so a name added here or taken from here leaves every verdict made by
	 * the older list behind - see ContentRenderer and the cache of the parse of a content.
	 * @return string
	 */
	public static function fingerprint(): string
	{
		static $fingerprint = null;

		return $fingerprint ??= md5(implode("\n", self::NAMES));
	}
}
