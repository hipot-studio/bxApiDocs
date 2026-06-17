<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline;

use Bitrix\Crm\Copilot\Pipeline\Scenario\AnalyzeCommunicationScenario;
use Bitrix\Crm\Copilot\Pipeline\Scenario\CallScoringScenario;
use Bitrix\Crm\Copilot\Pipeline\Scenario\ExtractScoringCriteriaScenario;
use Bitrix\Crm\Copilot\Pipeline\Scenario\FillFieldsScenario;
use Bitrix\Crm\Copilot\Pipeline\Scenario\FullScenario;
use Bitrix\Crm\Copilot\Pipeline\Scenario\RepeatSaleScreeningScenario;
use Bitrix\Crm\Copilot\Pipeline\Scenario\RepeatSaleTipsScenario;
use Bitrix\Crm\Copilot\Pipeline\Scenario\SummarizeScenario;
use Bitrix\Crm\Copilot\Pipeline\Scenario\TranscribeRecordScenario;
use Bitrix\Main\DI\ServiceLocator;

final class ScenarioRegistry
{
	/** @var array<string, ScenarioInterface> */
	private array $scenarios = [];

	public function __construct()
	{
		$this->registerDefaults();
	}

	/**
	 * @internal For tests only — resets the cached instance in ServiceLocator
	 */
	public static function resetInstance(): void
	{
		ServiceLocator::getInstance()->addInstance(self::class, new self());
	}

	public function register(ScenarioInterface $scenario): void
	{
		$this->scenarios[$scenario->getId()] = $scenario;
	}

	public function getByName(string $name): ?ScenarioInterface
	{
		return $this->scenarios[$name] ?? null;
	}

	/** @return array<string, ScenarioInterface> */
	public function getAll(): array
	{
		return $this->scenarios;
	}

	private function registerDefaults(): void
	{
		$this->register(new FillFieldsScenario());
		$this->register(new SummarizeScenario());
		$this->register(new CallScoringScenario());
		$this->register(new FullScenario());
		$this->register(new TranscribeRecordScenario());
		$this->register(new AnalyzeCommunicationScenario());
		$this->register(new ExtractScoringCriteriaScenario());
		$this->register(new RepeatSaleTipsScenario());
		$this->register(new RepeatSaleScreeningScenario());
	}
}
