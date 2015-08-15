<?php
 /***************************************************************
 *  Copyright notice
 *
 *  (c) 2015 Daniel Lienert <lienert@punkt.de>
 *
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

namespace Neos\Contribute\Domain\Service;

use TYPO3\Flow\Annotations as Flow;
use Guzzle\Http\Client;
use TYPO3\Flow\Utility\Files;

class GerritService {

	/**
	 * @Flow\inject
	 * @var \TYPO3\Flow\Package\PackageManagerInterface
	 */
	protected $packageManager;


	/**
	 * @Flow\inject
	 * @var \TYPO3\Flow\Utility\Environment
	 */
	protected $environment;


	/**
	 * @var string
	 */
	protected $gerritApiPattern = 'https://review.typo3.org/changes/%s/%s';


	/**
	 * Gets the patch as zip package from gerrit
	 *
	 * @param int $patchId
	 * @return bool|string
	 * @throws \TYPO3\Flow\Utility\Exception
	 */
	public function getPatchFromGerrit($patchId) {
		$uri = sprintf($this->gerritApiPattern, $patchId, 'revisions/current/patch?zip');
		$outputDirectory = Files::concatenatePaths(array($this->environment->getPathToTemporaryDirectory(), 'GerritPatches'));
		Files::createDirectoryRecursively($outputDirectory);
		$outputZipFilePath = Files::concatenatePaths(array($outputDirectory, $patchId . '.zip'));

		$httpClient = new Client();
		$httpClient->get($uri)->setResponseBody($outputZipFilePath)->send();

		$zip = new \ZipArchive();
		$zip->open($outputZipFilePath);
		$patchFile = $zip->getNameIndex(0);
		$zip->extractTo($outputDirectory);
		$zip->close();
		Files::unlink($outputZipFilePath);

		return Files::concatenatePaths(array($outputDirectory, $patchFile));
	}



	/**
	 * @param $patchId
	 * @return \TYPO3\Flow\Package\PackageInterface
	 */
	public function getPatchTargetPackage($patchId) {
		$packageName = '';

		$httpClient = new Client();
		$responseText = $httpClient->get(sprintf($this->gerritApiPattern, $patchId, 'detail'))->send()->getBody(TRUE);
		$responseText = substr($responseText, 4); // Remove buggy characters in output
		$details = json_decode($responseText, TRUE);

		if($details !== FALSE) {
			$projectParts = explode('/', $details['project']);
			$packageName = $projectParts[1];
		}

		$package = $this->packageManager->getPackage($packageName);

		return $package;
	}

}