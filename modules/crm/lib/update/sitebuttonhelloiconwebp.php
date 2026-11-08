<?php

namespace Bitrix\Crm\Update;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\SiteButton\Internals\ButtonTable;

class SiteButtonHelloIconWebp extends \Bitrix\Main\Update\Stepper
{
	protected static $moduleId = "crm";

	protected $limit = 50;

	private const RETRY_INTERVAL = 60;
	private const MAX_BUILD_ATTEMPTS = 3;

	public static function normalizeIconUrl(string $url): string
	{
		return preg_replace(
			'#(/bitrix/components/bitrix/crm\.button\.edit/templates/\.default/images/upload-(?:girl|man)-mini-[1-4])\.png(?=$|[?\#])#',
			'$1.webp',
			$url,
		);
	}

	public static function normalizeSettings(array $settings): array
	{
		if (
			!isset($settings['HELLO'])
			|| !is_array($settings['HELLO'])
			|| !isset($settings['HELLO']['CONDITIONS'])
			|| !is_array($settings['HELLO']['CONDITIONS'])
		)
		{
			return $settings;
		}

		foreach ($settings['HELLO']['CONDITIONS'] as $conditionKey => $condition)
		{
			if (
				is_array($condition)
				&& isset($condition['ICON'])
				&& is_string($condition['ICON'])
				&& $condition['ICON'] !== ''
			)
			{
				$settings['HELLO']['CONDITIONS'][$conditionKey]['ICON'] = self::normalizeIconUrl($condition['ICON']);
			}
		}

		return $settings;
	}

	public function execute(array &$option)
	{
		$lastId = (int)($option['lastId'] ?? 0);
		$pending = (isset($option['pending']) && is_array($option['pending'])) ? $option['pending'] : [];

		$pending = $this->retryPending($pending);

		$buttons = ButtonTable::getList([
			'select' => ['ID', 'SETTINGS'],
			'filter' => ['>ID' => $lastId],
			'order' => ['ID' => 'ASC'],
			'limit' => $this->limit,
		]);
		$fetched = 0;

		while ($button = $buttons->fetch())
		{
			$fetched++;
			$lastId = (int)$button['ID'];
			$settings = is_array($button['SETTINGS']) ? $button['SETTINGS'] : [];
			$newSettings = self::normalizeSettings($settings);
			if ($newSettings !== $settings)
			{
				ButtonTable::update($button['ID'], ['SETTINGS' => $newSettings]);
				if (!$this->rebuildButton($button['ID']))
				{
					$pending[$button['ID']] = [
						'attempts' => 1,
						'nextAttemptAt' => time() + self::RETRY_INTERVAL,
					];
				}
			}
		}

		if ($fetched < $this->limit && empty($pending))
		{
			return false;
		}
		if ($fetched < $this->limit)
		{
			$this->delayUntilNextRetry($pending);
		}

		$option['lastId'] = $lastId;
		$option['pending'] = $pending;

		return true;
	}

	protected function retryPending(array $pending): array
	{
		$now = time();
		$processed = 0;
		foreach ($pending as $id => $retry)
		{
			$retry = $this->normalizeRetry($retry);
			$pending[$id] = $retry;

			if ($retry['nextAttemptAt'] > $now || $processed >= $this->limit)
			{
				continue;
			}
			$processed++;

			if ($this->rebuildButton((int)$id))
			{
				unset($pending[$id]);

				continue;
			}

			$retry['attempts']++;
			if ($retry['attempts'] >= self::MAX_BUILD_ATTEMPTS)
			{
				$this->logFinalFailure((int)$id, $retry['attempts']);
				unset($pending[$id]);

				continue;
			}

			$retry['nextAttemptAt'] = time() + self::RETRY_INTERVAL;
			$pending[$id] = $retry;
		}

		return $pending;
	}

	private function normalizeRetry($retry): array
	{
		if (is_array($retry))
		{
			return [
				'attempts' => max(1, (int)($retry['attempts'] ?? 1)),
				'nextAttemptAt' => (int)($retry['nextAttemptAt'] ?? 0),
			];
		}

		return [
			'attempts' => 1,
			'nextAttemptAt' => (int)$retry,
		];
	}

	private function delayUntilNextRetry(array $pending): void
	{
		global $pPERIOD;

		$nextAttemptAt = array_map(
			static fn(array $retry): int => $retry['nextAttemptAt'],
			$pending,
		);
		$pPERIOD = max(1, min($nextAttemptAt) - time());
	}

	protected function rebuildButton(int $id): bool
	{
		try
		{
			return \Bitrix\Crm\UI\Webpack\Button::rebuild($id);
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}

	protected function logFinalFailure(int $id, int $attempts): void
	{
		Container::getInstance()->getLogger('Agent')->error(
			'Failed to rebuild CRM site button loader after WebP icon migration',
			[
				'buttonId' => $id,
				'attempts' => $attempts,
			],
		);
	}
}
