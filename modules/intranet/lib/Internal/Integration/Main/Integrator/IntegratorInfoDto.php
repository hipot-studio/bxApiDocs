<?php

namespace Bitrix\Intranet\Internal\Integration\Main\Integrator;

use Bitrix\Main\Type\Contract\Arrayable;

final class IntegratorInfoDto implements Arrayable
{
	public function __construct(
		public readonly ?int $id = null,
		public readonly ?string $name = null,
		public readonly ?string $url = null,
		public readonly ?string $phone = null,
		public readonly ?string $ol = null,
		public readonly ?bool $canContact = null,
		public readonly ?string $logo = null,
		public readonly ?string $company = null,
		public readonly ?string $email = null,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'url' => $this->url,
			'phone' => $this->phone,
			'ol' => $this->ol,
			'canContact' => $this->canContact,
			'logo' => $this->logo,
			'company' => $this->company,
			'email' => $this->email,
		];
	}
}
