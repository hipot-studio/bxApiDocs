<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Entity\Deeplink;

/**
 * The payload a transition link is issued with: the module it belongs to and whatever that module
 * understands.
 *
 * The meaning of the data belongs to its owner alone - this module carries it unread and hands it over
 * as it came, so only the wrapper is stated here. What the wrapper states is the single thing this
 * module does read: the owner. A value naming no owner belongs to nobody and can be delivered nowhere,
 * so it does not become a payload at all.
 */
final class Payload
{
	private function __construct(
		public readonly string $module,
		public readonly array $data,
	)
	{
	}

	/**
	 * The payload comes from a request, so its shape is a hope until it is read. Answering with null
	 * leaves it to the caller to decide what a value of the wrong shape means - a refusal of the request
	 * or a link issued without a payload.
	 *
	 * The data of an owner is an array by the contract of the event it travels in; anything else is a
	 * value the owner could not have sent and is dropped rather than passed on.
	 */
	public static function fromRequest(array $raw): ?self
	{
		$module = $raw['module'] ?? null;
		if (!is_string($module) || $module === '')
		{
			return null;
		}

		$data = $raw['data'] ?? [];

		return new self($module, is_array($data) ? $data : []);
	}
}
