<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\PublicKey;

use Bitrix\Main\Config\Option;

final class CloudKeySourceSettings
{
	public function getSource(): PublicKeySource
	{
		$raw = (string)Option::get('vibecodeconnector', 'cloud_shared_key_source', '');

		return PublicKeySource::tryFromOrDefault($raw);
	}

	public function setSource(PublicKeySource $source): void
	{
		Option::set('vibecodeconnector', 'cloud_shared_key_source', $source->value);
	}
}
