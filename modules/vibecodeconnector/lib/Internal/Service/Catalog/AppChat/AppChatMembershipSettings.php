<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;

final class AppChatMembershipSettings
{
	protected const DEFAULT_AUDIENCE_READ_BATCH = 500;

	private const MIN_AUDIENCE_READ_BATCH = 100;
	private const MAX_AUDIENCE_READ_BATCH = 500;
	private const OPTION_NAME = 'app_chat_audience_read_batch';

	public function __construct(private readonly ModuleOptions $options = new ModuleOptions())
	{
	}

	public function getAudienceReadBatch(): int
	{
		$value = (int)$this->options->get(self::OPTION_NAME, (string)self::DEFAULT_AUDIENCE_READ_BATCH);

		return $this->clamp($value);
	}

	public function setAudienceReadBatch(int $size): void
	{
		$this->options->set(self::OPTION_NAME, (string)$this->clamp($size));
	}

	private function clamp(int $size): int
	{
		if ($size < self::MIN_AUDIENCE_READ_BATCH)
		{
			return self::MIN_AUDIENCE_READ_BATCH;
		}

		if ($size > self::MAX_AUDIENCE_READ_BATCH)
		{
			return self::MAX_AUDIENCE_READ_BATCH;
		}

		return $size;
	}
}
