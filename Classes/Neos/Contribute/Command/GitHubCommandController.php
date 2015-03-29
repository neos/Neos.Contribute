<?php
namespace Neos\Contribute\Command;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "Neos.Contribute".       *
 *                                                                        *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Utility\Files;

/**
 * @Flow\Scope("singleton")
 */
class GitHubCommandController extends \TYPO3\Flow\Cli\CommandController {

	/**
	 * @var string
	 */
	protected $gerritApiPattern = 'https://review.typo3.org/changes/%s/%s';


	/**
	 * @Flow\inject
	 * @var \TYPO3\Flow\Utility\Environment
	 */
	protected $environment;


	/**
	 * @Flow\inject
	 * @var \TYPO3\Flow\Package\PackageManagerInterface
	 */
	protected $packageManager;


	/**
	 * @Flow\inject
	 * @var \Github\Client
	 */
	protected $githubClient;



	public function createPullRequestFromGerritCommand($patchId = 32240) {
		$package = $this->getPatchTargetPackage($patchId);
		$patchPathAndFileName = $this->getPatchFromGerrit($patchId);

		$this->outputLine(sprintf('The following changes will be applied to package <b>%s</b>', $package->getPackageKey()));

		$this->output($this->executeGitCommand(sprintf('git apply --check %s', $patchPathAndFileName), $package->getPackagePath()));
		$this->output($this->executeGitCommand(sprintf('git apply --stat %s', $patchPathAndFileName), $package->getPackagePath()));

		if(!$this->output->askConfirmation('Would you like to apply this patch?', FALSE)) {
			return;
		}

		$this->output($this->executeGitCommand(sprintf('git am < %s', $patchPathAndFileName), $package->getPackagePath()));
		$this->output(sprintf('<success>Successfully Applied patch %s</success>', $patchId));
	}


	/**
	 * @param int $patchId
	 * @return bool|string
	 * @throws \TYPO3\Flow\Utility\Exception
	 */
	protected function getPatchFromGerrit($patchId) {
		$uri = sprintf($this->gerritApiPattern, $patchId, 'revisions/current/patch?zip');
		$outputDirectory = Files::concatenatePaths(array($this->environment->getPathToTemporaryDirectory(), 'GerritPatches'));
		Files::createDirectoryRecursively($outputDirectory);
		$outputZipFilePath = Files::concatenatePaths(array($outputDirectory, $patchId . '.zip'));

		$httpClient = new \Guzzle\Http\Client();
		$httpClient->get($uri)->setResponseBody($outputZipFilePath)->send();

		$zip = new \ZipArchive();
		$zip->open($outputZipFilePath);
		$patchFile = $zip->getNameIndex(0);
		$zip->extractTo($outputDirectory);
		$zip->close();
		Files::unlink($outputZipFilePath);

		$this->outputLine('Successfully fetched changeset from gerrit');

		return Files::concatenatePaths(array($outputDirectory, $patchFile));
	}


	/**
	 * @param $patchId
	 * @return \TYPO3\Flow\Package\PackageInterface
	 */
	protected function getPatchTargetPackage($patchId) {
		$packageName = '';

		$this->outputLine('Requesting Patch Details from gerrit.');

		$httpClient = new \Guzzle\Http\Client();
		$responseText = $httpClient->get(sprintf($this->gerritApiPattern, $patchId, 'detail'))->send()->getBody(TRUE);
		$responseText = substr($responseText, 4); // Remove buggy characters in output
		$details = json_decode($responseText, TRUE);

		if($details !== FALSE) {
			$projectParts = explode('/', $details['project']);
			$packageName = $projectParts[1];
		}

		$this->outputLine(sprintf('Determined %s as the package key for this change.', $packageName));
		$package = $this->packageManager->getPackage($packageName);

		return $package;
	}


	/**
	 * @param $workingDirectory
	 * @param $command
	 * @return mixed
	 * @throws \RuntimeException
	 */
	protected function executeGitCommand($command, $workingDirectory) {
		$cwd = getcwd();
		chdir($workingDirectory);
		$this->outputLine(sprintf('DEBUG: Exec Git Command %s in WD %s', $command, $workingDirectory));
		exec($command . " 2>&1", $output, $returnValue);
		chdir($cwd);
		$outputString = implode("\n", $output) . "\n";

		if ($returnValue !== 0) {
			$this->outputLine(sprintf('<error>%s</error>', $outputString));
			$this->sendAndExit(1);
		}

		return $outputString;
	}
}