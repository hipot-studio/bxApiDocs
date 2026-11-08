<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Response\Catalog\Item;

use Bitrix\Rest\V3\Interaction\Response\Response;

/**
 * @see \Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Pairing\BindCatalogItemPairingCommandHandler
 *      for the `bound` vocabulary.
 */
final class PairingBindResponse extends Response
{
	public function __construct(
		public string $iss,
		public string $bound,
	) {}
}
