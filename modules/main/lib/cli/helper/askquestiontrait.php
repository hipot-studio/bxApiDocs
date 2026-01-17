<?php

namespace Bitrix\Main\Cli\Helper;

use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Helper\HelperInterface;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

trait AskQuestionTrait
{
	//abstract public function getHelper(string $name): HelperInterface;

	//abstract public function getDefinition(): InputDefinition;

	private function askOrGetOption(string $optionId, InputInterface $input, OutputInterface $output, bool $isRequired = true): ?string
	{
		$value = null;
		$optionDefinition = $this->getDefinition()->getOption($optionId);
		$questionText = $optionDefinition->getDescription();
		$defaultValue = $optionDefinition->getDefault();
		$rawId = ['--' . $optionDefinition->getName()];
		if ($optionDefinition->getShortcut())
		{
			$rawId[] = '-' . $optionDefinition->getShortcut();
		}

		if ($input->hasParameterOption($rawId))
		{
			$value = $input->getOption($optionId);
		}

		if (!empty($value))
		{
			return $value;
		}

		if (!empty($defaultValue))
		{
			$questionText .= " [default: {$defaultValue}]";
		}

		$questionText .= ": ";

		/**
		 * @var QuestionHelper $helper
		 */
		$helper = $this->getHelper('question');
		$question = new Question($questionText, $defaultValue);
		$value = $helper->ask($input, $output, $question);

		if ($isRequired && empty($value))
		{
			throw new InvalidOptionException("Option '{$optionId}' is required");
		}

		return $value;
	}
}
