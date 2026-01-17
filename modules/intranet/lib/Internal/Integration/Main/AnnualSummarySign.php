<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Main;

use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\Signer;

class AnnualSummarySign
{
	private const SIGNER_SALT = 'user_annual_summary_link';

	private Signer $signer;

	public function __construct()
	{
		$this->signer = new Signer();
	}

	/**
	 * @throws ArgumentTypeException
	 */
	public function sign(mixed $value): string
	{
		return $this->signer->sign($value, self::SIGNER_SALT);
	}

	/**
	 * @throws ArgumentTypeException
	 * @throws BadSignatureException
	 */
	public function unsign(mixed $signedValue): string
	{
		return $this->signer->unsign($signedValue, self::SIGNER_SALT);
	}
}
