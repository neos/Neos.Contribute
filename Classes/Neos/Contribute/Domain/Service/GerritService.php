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
use TYPO3\Flow\Configuration\Exception\ParseErrorException;
use TYPO3\Flow\Utility\Files;

class GerritService
{

    /**
     * @Flow\Inject
     * @var \TYPO3\Flow\Package\PackageManagerInterface
     */
    protected $packageManager;

    /**
     * @Flow\Inject
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
    public function getPatchFromGerrit($patchId)
    {
        $uri = sprintf($this->gerritApiPattern, $patchId, 'revisions/current/patch?zip');
        $outputDirectory = Files::concatenatePaths([$this->environment->getPathToTemporaryDirectory(), 'GerritPatches']);
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
     * @param string $patchId
     * @return \TYPO3\Flow\Package\PackageInterface
     */
    public function getPatchTargetPackage($patchId)
    {
        $details = $this->requestGerritAPI(sprintf($this->gerritApiPattern, $patchId, 'detail'));

        $projectParts = explode('/', $details['project']);
        $packageName = $projectParts[1];

        $package = $this->packageManager->getPackage($packageName);

        return $package;
    }

    /**
     * @param string $patchId
     * @return array
     * @throws ParseErrorException
     */
    public function getCommitDetails($patchId)
    {
        return $this->requestGerritAPI(sprintf($this->gerritApiPattern, $patchId, 'revisions/current/commit'));
    }

    /**
     * @param string $url
     * @return array
     * @throws ParseErrorException
     */
    protected function requestGerritAPI($url)
    {
        $httpClient = new Client();
        $responseText = $httpClient->get($url)->send()->getBody(true);
        $responseText = substr($responseText, 4); // Remove buggy characters in output
        $responseData = json_decode($responseText, true);

        if ($responseData == false) {
            throw new ParseErrorException('The response data could not be parsed.', 1439716592);
        }

        return $responseData;
    }
}
