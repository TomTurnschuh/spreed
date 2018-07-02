<?php

/**
 *
 * @copyright Copyright (c) 2018, Daniel Calviño Sánchez (danxuliu@gmail.com)
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\TableNode;
use GuzzleHttp\Client;
use GuzzleHttp\Message\ResponseInterface;

class SharingContext implements Context {

	/** @var string */
	private $baseUrl = '';

	/** @var ResponseInterface */
	private $response = null;

	/** @var string */
	private $currentUser = '';

	/** @var string */
	private $regularUserPassword;

	/** @var \SimpleXMLElement */
	private $lastCreatedShareData = null;

	public function __construct(string $baseUrl, array $admin, string $regularUserPassword) {
		$this->baseUrl = $baseUrl;
		$this->adminUser = $admin;
		$this->regularUserPassword = $regularUserPassword;

		// in case of ci deployment we take the server url from the environment
		$testServerUrl = getenv('TEST_SERVER_URL');
		if ($testServerUrl !== false) {
			$this->baseUrl = $testServerUrl;
		}
	}

	/**
	 * @When user :user shares :path with user :sharee
	 *
	 * @param string $user
	 * @param string $path
	 * @param string $sharee
	 * @param TableNode|null $body
	 */
	public function userSharesWithUser(string $user, string $path, string $sharee, TableNode $body = null) {
		$this->userSharesWith($user, $path, 0 /*Share::SHARE_TYPE_USER*/, $sharee, $body);
	}

	/**
	 * @When user :user shares :path with user :sharee with OCS :statusCode
	 *
	 * @param string $user
	 * @param string $path
	 * @param string $sharee
	 * @param int $statusCode
	 */
	public function userSharesWithUserWithOcs(string $user, string $path, string $sharee, int $statusCode) {
		$this->userSharesWithUser($user, $path, $sharee);
		$this->theOCSStatusCodeShouldBe($statusCode);
	}

	/**
	 * @When user :user shares :path with room :room
	 *
	 * @param string $user
	 * @param string $path
	 * @param string $room
	 * @param TableNode|null $body
	 */
	public function userSharesWithRoom(string $user, string $path, string $room, TableNode $body = null) {
		$this->userSharesWith($user, $path, 10 /*Share::SHARE_TYPE_ROOM*/, FeatureContext::getTokenForIdentifier($room), $body);
	}

	/**
	 * @When user :user shares :path with room :room with OCS :statusCode
	 *
	 * @param string $user
	 * @param string $path
	 * @param string $room
	 * @param int $statusCode
	 */
	public function userSharesWithRoomWithOcs(string $user, string $path, string $room, int $statusCode) {
		$this->userSharesWithRoom($user, $path, $room);
		$this->theOCSStatusCodeShouldBe($statusCode);
	}

	/**
	 * @When user :user gets last share
	 */
	public function userGetsLastShare(string $user) {
		$this->currentUser = $user;

		$url = '/apps/files_sharing/api/v1/shares/' . $this->getLastShareId();

		$this->sendingTo('GET', $url);
	}

	/**
	 * @When user :user gets all shares
	 *
	 * @param string $user
	 */
	public function userGetsAllShares(string $user) {
		$this->currentUser = $user;

		$url = '/apps/files_sharing/api/v1/shares';

		$this->sendingTo('GET', $url);
	}

	/**
	 * @Then the OCS status code should be :statusCode
	 *
	 * @param int $statusCode
	 */
	public function theOCSStatusCodeShouldBe(int $statusCode) {
		$meta = $this->getXmlResponse()->meta[0];

		PHPUnit_Framework_Assert::assertEquals($statusCode, (int)$meta->statuscode, 'Response message: ' . (string)$meta->message);
	}

	/**
	 * @Then the HTTP status code should be :statusCode
	 *
	 * @param int $statusCode
	 */
	public function theHTTPStatusCodeShouldBe(int $statusCode) {
		PHPUnit_Framework_Assert::assertEquals($statusCode, $this->response->getStatusCode());
	}

	/**
	 * @Then the list of returned shares has :count shares
	 */
	public function theListOfReturnedSharesHasShares(int $count) {
		$this->theHTTPStatusCodeShouldBe(200);
		$this->theOCSStatusCodeShouldBe(100);

		$returnedShares = $this->getXmlResponse()->data[0];

		PHPUnit_Framework_Assert::assertEquals($count, count($returnedShares->element));
	}

	/**
	 * @Then share is returned with
	 *
	 * @param TableNode $body
	 */
	public function shareIsReturnedWith(TableNode $body) {
		$this->shareXIsReturnedWith(0, $body);
	}

	/**
	 * @Then share :number is returned with
	 *
	 * @param int $number
	 * @param TableNode $body
	 */
	public function shareXIsReturnedWith(int $number, TableNode $body) {
		$this->theHTTPStatusCodeShouldBe(200);
		$this->theOCSStatusCodeShouldBe(100);

		if (!($body instanceof TableNode)) {
			return;
		}

		$returnedShare = $this->getXmlResponse()->data[0];
		if ($returnedShare->element) {
			$returnedShare = $returnedShare->element[$number];
		}

		$defaultExpectedFields = [
			'id' => 'A_NUMBER',
			'share_type' => '10', // Share::SHARE_TYPE_ROOM,
			'permissions' => '19',
			'stime' => 'A_NUMBER',
			'parent' => '',
			'expiration' => '',
			'token' => '',
			'storage' => 'A_NUMBER',
			'item_source' => 'A_NUMBER',
			'file_source' => 'A_NUMBER',
			'file_parent' => 'A_NUMBER',
			'mail_send' => '0'
		];
		$expectedFields = array_merge($defaultExpectedFields, $body->getRowsHash());

		if (!array_key_exists('uid_file_owner', $expectedFields) &&
				array_key_exists('uid_owner', $expectedFields)) {
			$expectedFields['uid_file_owner'] = $expectedFields['uid_owner'];
		}
		if (!array_key_exists('displayname_file_owner', $expectedFields) &&
				array_key_exists('displayname_owner', $expectedFields)) {
			$expectedFields['displayname_file_owner'] = $expectedFields['displayname_owner'];
		}

		if (array_key_exists('share_type', $expectedFields) &&
				$expectedFields['share_type'] == 10 /* Share::SHARE_TYPE_ROOM */ &&
				array_key_exists('share_with', $expectedFields)) {
			$expectedFields['share_with'] = FeatureContext::getTokenForIdentifier($expectedFields['share_with']);
		}

		foreach ($expectedFields as $field => $value) {
			$this->assertFieldIsInReturnedShare($field, $value, $returnedShare);
		}
	}

	/**
	 * @param string $user
	 * @param string $path
	 * @param string $shareType
	 * @param string $shareWith
	 * @param TableNode|null $body
	 */
	private function userSharesWith(string $user, string $path, string $shareType, string $shareWith, TableNode $body = null) {
		$this->currentUser = $user;

		$url = '/apps/files_sharing/api/v1/shares';

		$parameters = [];
		$parameters[] = 'path=' . $path;
		$parameters[] = 'shareType=' . $shareType;
		$parameters[] = 'shareWith=' . $shareWith;

		if ($body instanceof TableNode) {
			foreach ($body->getRowsHash() as $key => $value) {
				if ($key === 'expireDate' && $value !== 'invalid date'){
					$value = date('Y-m-d', strtotime($value));
				}
				$parameters[] = $key . '=' . $value;
			}
		}

		$url .= '?' . implode('&', $parameters);

		$this->sendingTo('POST', $url);

		$this->lastCreatedShareData = $this->getXmlResponse();
	}

	/**
	 * @param string $verb
	 * @param string $url
	 * @param TableNode $body
	 */
	private function sendingTo(string $verb, string $url, TableNode $body = null) {
		$fullUrl = $this->baseUrl . "ocs/v1.php" . $url;
		$client = new Client();
		$options = [];
		if ($this->currentUser === 'admin') {
			$options['auth'] = $this->adminUser;
		} else {
			$options['auth'] = [$this->currentUser, $this->regularUserPassword];
		}
		$options['headers'] = [
			'OCS_APIREQUEST' => 'true'
		];
		if ($body instanceof TableNode) {
			$fd = $body->getRowsHash();
			if (array_key_exists('expireDate', $fd)){
				$fd['expireDate'] = date('Y-m-d', strtotime($fd['expireDate']));
			}
			$options['body'] = $fd;
		}

		try {
			$this->response = $client->send($client->createRequest($verb, $fullUrl, $options));
		} catch (GuzzleHttp\Exception\ClientException $ex) {
			$this->response = $ex->getResponse();
		}
	}

	/**
	 * @return string
	 */
	private function getLastShareId(): string {
		return (string)$this->lastCreatedShareData->data[0]->id;
	}

	/**
	 * @return SimpleXMLElement
	 */
	private function getXmlResponse(): \SimpleXMLElement {
		return simplexml_load_string($this->response->getBody());
	}

	/**
	 * @param string $field
	 * @param string $contentExpected
	 * @param \SimpleXMLElement $returnedShare
	 */
	private function assertFieldIsInReturnedShare(string $field, string $contentExpected, \SimpleXMLElement $returnedShare){
		if (!array_key_exists($field, $returnedShare)) {
			PHPUnit_Framework_Assert::fail("$field was not found in response");
		}

		if ($field === 'expiration' && !empty($contentExpected)){
			$contentExpected = date('Y-m-d', strtotime($contentExpected)) . " 00:00:00";
		}

		if ($contentExpected === 'A_NUMBER') {
			PHPUnit_Framework_Assert::assertTrue(is_numeric((string)$returnedShare->$field), "Field '$field' is not a number: " . $returnedShare->$field);
		} else if (strpos($contentExpected, 'REGEXP ') === 0) {
			PHPUnit_Framework_Assert::assertRegExp(substr($contentExpected, strlen('REGEXP ')), (string)$returnedShare->$field, "Field '$field' does not match");
		} else {
			PHPUnit_Framework_Assert::assertEquals($contentExpected, (string)$returnedShare->$field, "Field '$field' does not match");
		}
	}

}