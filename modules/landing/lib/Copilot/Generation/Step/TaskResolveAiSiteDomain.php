<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\AI\SiteBuilder\Domain\AiSiteDomainPicker;
use Bitrix\Landing\AI\SiteBuilder\Dto\EnhancedInputDto;
use Bitrix\Landing\Copilot\Generation\Log;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;
use Bitrix\Landing\Manager;
use Bitrix\Landing\PublicAction\Domain;

class TaskResolveAiSiteDomain extends TaskStep
{
	private const CANDIDATE_TYPE = 'PAGE';
	private const FALLBACK_INVALID_INPUT = 'invalid enhanced input';
	private const FALLBACK_NO_CANDIDATES = 'no valid candidates';
	private const FALLBACK_PERSIST_FAILED = 'persist state failed';
	private const FALLBACK_TIME_IS_OVER = 'execution time is over';

	public function execute(): bool
	{
		parent::execute();

		if (!$this->isCloudEnvironment() || !$this->isDomainAutopickEnabled())
		{
			return true;
		}

		$state = CreateAiSiteState::getDomainPick($this->generation);
		if ($state['resolved'] ?? false)
		{
			return true;
		}

		$enhanced = EnhancedInputDto::fromArray(CreateAiSiteState::getEnhancedInput($this->generation));
		if ($enhanced === null)
		{
			$this->persistDomainPick(true, null, [], 0);
			$this->logFallback(self::FALLBACK_INVALID_INPUT);

			return true;
		}

		$candidates = $this->buildCandidates($enhanced);
		$chosen = null;
		$checks = 0;
		$timeIsOver = false;
		$lastCandidateIndex = array_key_last($candidates);
		foreach ($candidates as $index => $candidate)
		{
			// No readable option left after this one, and Site::add() re-checks the name anyway,
			// falling back to a random technical domain on collision.
			if ($index === $lastCandidateIndex)
			{
				$chosen = $candidate;

				break;
			}

			// Out of the generation pass budget: stop spending external checks and take the current
			// candidate unchecked, on the same grounds as the last one.
			if (!$this->generation->getTimer()->check())
			{
				$chosen = $candidate;
				$timeIsOver = true;

				break;
			}

			$checks++;
			if ($this->isDomainAvailable($candidate))
			{
				$chosen = $candidate;

				break;
			}
		}

		$this->persistDomainPick(true, $chosen, $candidates, $checks);
		if ($timeIsOver)
		{
			$this->logFallback(self::FALLBACK_TIME_IS_OVER);
		}

		if ($chosen === null)
		{
			$this->logFallback(self::FALLBACK_NO_CANDIDATES);
		}

		return true;
	}

	protected function isCloudEnvironment(): bool
	{
		return Manager::isB24Cloud();
	}

	protected function isDomainAutopickEnabled(): bool
	{
		return true;
	}

	/**
	 * @return string[]
	 */
	protected function buildCandidates(EnhancedInputDto $enhanced): array
	{
		return AiSiteDomainPicker::buildCandidates($enhanced, self::CANDIDATE_TYPE);
	}

	protected function isDomainAvailable(string $fqdn): bool
	{
		$result = Domain::check($fqdn)->getResult();

		return is_array($result) && ($result['available'] ?? false);
	}

	protected function logFallback(string $reason): void
	{
		(new Log($this->generation->getId()))->add('AI site domain autopick fallback: ' . $reason);
	}

	/**
	 * @param string[] $candidates
	 */
	private function persistDomainPick(bool $resolved, ?string $domain, array $candidates, int $checks): void
	{
		CreateAiSiteState::setDomainPick($this->generation, [
			'resolved' => $resolved,
			'domain' => $domain,
			'candidates' => $candidates,
			'checks' => $checks,
		]);
		$this->changed = true;

		// Best-effort checkpoint: on persist failure drop the in-memory pick and continue.
		// The kernel then assigns a random technical domain during site creation.
		if (!$this->generation->persistState())
		{
			CreateAiSiteState::setDomainPick($this->generation, []);
			$this->changed = false;
			$this->logFallback(self::FALLBACK_PERSIST_FAILED);
		}
	}
}
