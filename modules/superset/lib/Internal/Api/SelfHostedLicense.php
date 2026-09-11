<?php

namespace Bitrix\Superset\Internal\Api;

use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\RequestResult;

final class SelfHostedLicense
{
	private const LICENSE_DATE_API_LINK = '/api/v1/bitrix/license/date/';
	private const BOX_LICENSE_DATE_API_LINK = '/api/v1/bitrix/license/box-date/';
	private const LICENSE_RESET_API_LINK = '/api/v1/bitrix/license/reset/';
	private const EDITION_API_LINK = '/api/v1/bitrix/license/edition/';
	private const DATE_PARAMETER = 'date';
	private const ALLOWED_PARAMETER = 'allowed';

	public function __construct(
		private readonly SupersetInstance $connector,
	)
	{
	}

	public function setExpirationDate(int $timestamp): RequestResult
	{
		return $this->connector->post(self::LICENSE_DATE_API_LINK, [
			self::DATE_PARAMETER => $timestamp,
		]);
	}

	public function setBoxExpirationDate(int $timestamp): RequestResult
	{
		return $this->connector->post(self::BOX_LICENSE_DATE_API_LINK, [
			self::DATE_PARAMETER => $timestamp,
		]);
	}

	/**
	 * Tells the instance whether the edition of this box allows the local mode at all. Not a term: the edition
	 * has no date, so the value is a verdict and it is sent as a boolean.
	 */
	public function setEditionVerdict(bool $isAllowed): RequestResult
	{
		return $this->connector->post(self::EDITION_API_LINK, [
			self::ALLOWED_PARAMETER => $isAllowed,
		]);
	}

	/**
	 * Wipes both terms kept by the instance. The request carries no value - it asks for the absence of the terms -
	 * while the answer does: its body carries the marker of a completed reset, which the caller checks.
	 */
	public function reset(): RequestResult
	{
		return $this->connector->post(self::LICENSE_RESET_API_LINK);
	}
}
