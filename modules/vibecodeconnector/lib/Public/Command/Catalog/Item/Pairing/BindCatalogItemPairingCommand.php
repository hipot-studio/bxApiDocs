<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Pairing;

use Bitrix\Main;
use Bitrix\Main\Validation\Rule;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;

/**
 * Stamps the pairing identity of the calling credential onto a catalog item that carries none.
 *
 * `$iss` is resolved by the portal from the REST credential, never taken from the client payload.
 */
final class BindCatalogItemPairingCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		#[Rule\PositiveNumber]
		public readonly int $userId,
		#[Rule\PositiveNumber]
		public readonly int $catalogItemId,
		public readonly ?string $iss = null,
	)
	{
	}

	/**
	 * Tells whether the repair can be performed safely on this database.
	 *
	 * The write has to be serialised against `RegistrationService::unregister()` and against a
	 * concurrent `catalog.item.update`, and the only mechanism for that is row locking. Where the
	 * driver has none, the command would report a successful repair that a parallel unregistration
	 * could outlive or a parallel update could overwrite - so the caller is told the repair is
	 * unavailable instead of being told a comfortable lie.
	 */
	public static function isSupportedHere(): bool
	{
		return CatalogItemRepository::supportsRowLocking();
	}

	protected function execute(): Main\Result
	{
		$result = new Main\Result();
		$result->setData((new BindCatalogItemPairingCommandHandler())($this));

		return $result;
	}
}
