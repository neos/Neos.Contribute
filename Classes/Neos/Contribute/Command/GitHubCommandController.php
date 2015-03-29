<?php
namespace Neos\Contribute\Command;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "Neos.Contribute".       *
 *                                                                        *
 *                                                                        */

use Github\Client as GitHubClient;
use Github\Exception\RuntimeException;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Configuration\ConfigurationManager;
use TYPO3\Flow\Utility\Arrays;
use TYPO3\Flow\Utility\Files;

/**
 * @Flow\Scope("singleton")
 */
class GitHubCommandController extends \TYPO3\Flow\Cli\CommandController {

	/**
	 * @var \TYPO3\Flow\Configuration\Source\YamlSource
	 * @Flow\Inject
	 */
	protected $configurationSource;

	/**
	 @Flow\Inject(setting="gitHub")
	 */
	protected $gitHubSettings;

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
	 * @var GitHubClient
	 */
	protected $gitHubClient;


	public function initializeObject() {
		$this->gitHubClient = new GithubClient();
	}


	public function getStartedCommand() {

		$this->output("
<b>Welcome To Flow / Neos Development</b>
This wizzard gets your environemnt up and running to easily contribute
code or documentation to the Neos project.
All you need is a github.com account.\n\n");

		$answer = $this->output->askConfirmation('Do you want to fork the flow development repository into your github account? ');

		if($answer === TRUE) {
			$this->forkRepository(
				Arrays::getValueByPath($this->gitHubSettings, 'origin.organization'),
				Arrays::getValueByPath($this->gitHubSettings, 'repositories.flow.origin')
			);
		}

		$this->saveUserSettings();
	}


	/**
	 * @param integer $patchId The gerrit patchset id
	 */
	public function createPullRequestFromGerritCommand($patchId) {
		$package = $this->getPatchTargetPackage($patchId);
		$patchPathAndFileName = $this->getPatchFromGerrit($patchId);

		$this->outputLine(sprintf('The following changes will be applied to package <b>%s</b>', $package->getPackageKey()));

		$this->output($this->executeGitCommand(sprintf('git apply --check %s', $patchPathAndFileName), $package->getPackagePath()));
		$this->output($this->executeGitCommand(sprintf('git apply --stat %s', $patchPathAndFileName), $package->getPackagePath()));

		if(!$this->output->askConfirmation('Would you like to apply this patch? ', FALSE)) {
			return;
		}

		$this->output($this->executeGitCommand(sprintf('git am < %s', $patchPathAndFileName), $package->getPackagePath()));
		$this->output(sprintf('<success>Successfully Applied patch %s</success>', $patchId));

		if(!$this->output->askConfirmation('Would you like to push the change to your default remote repository? ', FALSE)) {
			return;
		}
	}


	/**
	 * @param string $organization
	 * @param string$repositoryName
	 */
	protected function forkRepository($organization, $repositoryName) {
		$this->authenticateToGitHub();
		try {
			$response = $this->gitHubClient->repo()->forks()->create($organization, $repositoryName);
		} catch (RuntimeException $exception) {
			$this->outputLine(sprintf('Error while forking  %s/%s: %s', $organization, $repositoryName, $exception->getMessage()));
			$this->sendAndExit($exception->getMessage());
		}
		$this->outputLine(sprintf('<success>Successfully forked %s/%s to %s</success>', $organization, $repositoryName, $response['html_url']));
	}


	protected function authenticateToGitHub() {
		$gitHubAccessToken = (string) Arrays::getValueByPath($this->gitHubSettings, 'contributer.accessToken');

		if($gitHubAccessToken === '') {
			$gitHubAccessToken = $this->output->askHiddenResponse('Please enter your gitHub access token (This token can be generated in your github.com application settings): ');
			$this->gitHubSettings = Arrays::setValueByPath($this->gitHubSettings, 'contributer.accessToken', $gitHubAccessToken);
		}

		$this->gitHubClient->authenticate($gitHubAccessToken, GithubClient::AUTH_HTTP_TOKEN);

		try {
			$this->gitHubClient->currentUser()->show();
		} catch (RuntimeException $exception) {
			$this->outputLine(sprintf('<error>It was not possible to authenticate to GitHub: %s</error>', $exception->getMessage()));
			$this->sendAndExit($exception->getCode());
		}
	}

	protected function saveUserSettings() {
		$frameworkConfiguration = $this->configurationSource->load(FLOW_PATH_CONFIGURATION . ConfigurationManager::CONFIGURATION_TYPE_SETTINGS);
		$frameworkConfiguration = Arrays::setValueByPath($frameworkConfiguration, 'Neos.Contribute.gitHub.contributer.accessToken', Arrays::getValueByPath($this->gitHubSettings, 'contributer.accessToken'));
		$this->configurationSource->save(FLOW_PATH_CONFIGURATION . ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, $frameworkConfiguration);
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

		// $this->outputLine(sprintf('DEBUG: Exec Git Command %s in WD %s', $command, $workingDirectory));

		exec($command . " 2>&1", $output, $returnValue);
		chdir($cwd);
		$outputString = implode("\n", $output) . "\n";

		if ($returnValue !== 0) {
			$this->outputLine(sprintf("<error>%s</error>\n", $outputString));
			$this->sendAndExit(1);
		}

		return $outputString;
	}
}