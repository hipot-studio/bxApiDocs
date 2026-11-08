<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Configuration;

/**
 * The portal capabilities an external CRM form editor may rely on.
 *
 * @internal
 */
final readonly class FormConfiguration
{
	/**
	 * @param list<array{id: int, name: string, description: string, entityNames: list<string>,
	 *     mainEntityName: ?string, hasInvoice: bool, dynamic: bool}> $entitySchemes
	 * @param list<array{id: string, name: string}> $duplicateModes
	 * @param array{enabled: bool} $lead
	 * @param list<array{id: int, name: string}> $dealCategories
	 * @param list<array{entityTypeId: int, entityName: string, name: string,
	 *     categories: list<array{id: int, name: string}>}> $smartProcesses
	 * @param list<array{id: string, name: string}> $languages
	 * @param list<array{code: string, name: string, format: string}> $currencies
	 * @param list<array{id: string, name: string, parameters: list<string>}> $fieldTypes
	 * @param list<array{id: int, name: string}> $paySystems empty on a portal without the sale
	 *        module, which has no pay system to name
	 * @param array{canRead: bool, canEdit: bool} $permissions
	 * @param array{activeForms: null|array{current: int, maximum: int}} $limits
	 * @param array{isCloud: bool, isRegionRussian: bool} $product
	 */
	public function __construct(
		public array $entitySchemes = [],
		public array $duplicateModes = [],
		public array $lead = ['enabled' => false],
		public array $dealCategories = [],
		public array $smartProcesses = [],
		public array $languages = [],
		public array $currencies = [],
		public array $fieldTypes = [],
		public array $paySystems = [],
		public array $permissions = [
			'canRead' => true,
			'canEdit' => false,
		],
		public array $limits = ['activeForms' => null],
		public array $product = [
			'isCloud' => false,
			'isRegionRussian' => false,
		],
		public string $region = 'en',
	)
	{
	}
}
