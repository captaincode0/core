<?php
/**
 * @author Björn Schießle <schiessle@owncloud.com>
 *
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */


namespace OCA\Federation\Tests\BackgroundJob;


use OCA\Federation\BackgroundJob\RequestSharedSecret;
use OCP\AppFramework\Http;
use Test\TestCase;

class RequestSharedSecretTest extends TestCase {

	/** @var \PHPUnit_Framework_MockObject_MockObject | IClient */
	private $httpClient;

	/** @var \PHPUnit_Framework_MockObject_MockObject | IJobList */
	private $jobList;

	/** @var \PHPUnit_Framework_MockObject_MockObject | IURLGenerator */
	private $urlGenerator;

	/** @var \PHPUnit_Framework_MockObject_MockObject | DbHandler */
	private $dbHandler;

	/** @var \PHPUnit_Framework_MockObject_MockObject | TrustedServers */
	private $trustedServers;

	/** @var \PHPUnit_Framework_MockObject_MockObject | IResponse */
	private $response;

	/** @var  RequestSharedSecret */
	private $requestSharedSecret;

	public function setUp() {
		parent::setUp();

		$this->httpClient = $this->getMock('OCP\Http\Client\IClient');
		$this->jobList = $this->getMock('OCP\BackgroundJob\IJobList');
		$this->urlGenerator = $this->getMock('OCP\IURLGenerator');
		$this->trustedServers = $this->getMockBuilder('OCA\Federation\TrustedServers')
			->disableOriginalConstructor()->getMock();
		$this->dbHandler = $this->getMockBuilder('OCA\Federation\DbHandler')
			->disableOriginalConstructor()->getMock();
		$this->response = $this->getMock('OCP\Http\Client\IResponse');

		$this->requestSharedSecret = new RequestSharedSecret(
			$this->httpClient,
			$this->urlGenerator,
			$this->jobList,
			$this->trustedServers,
			$this->dbHandler
		);
	}

	/**
	 * @dataProvider dataTestExecute
	 *
	 * @param bool $isTrustedServer
	 */
	public function testExecute($isTrustedServer) {
		/** @var RequestSharedSecret |\PHPUnit_Framework_MockObject_MockObject $requestSharedSecret */
		$requestSharedSecret = $this->getMockBuilder('OCA\Federation\BackgroundJob\RequestSharedSecret')
			->setConstructorArgs(
				[
					$this->httpClient,
					$this->urlGenerator,
					$this->jobList,
					$this->trustedServers,
					$this->dbHandler
				]
			)->setMethods(['parentExecute'])->getMock();
		$this->invokePrivate($requestSharedSecret, 'argument', [['url' => 'url']]);

		$this->jobList->expects($this->once())->method('remove');
		$this->trustedServers->expects($this->once())->method('isTrustedServer')
			->with('url')->willReturn($isTrustedServer);
		if ($isTrustedServer) {
			$requestSharedSecret->expects($this->once())->method('parentExecute');
		} else {
			$requestSharedSecret->expects($this->never())->method('parentExecute');
		}

		$requestSharedSecret->execute($this->jobList);

	}

	public function dataTestExecute() {
		return [
			[true],
			[false]
		];
	}

	/**
	 * @dataProvider dataTestRun
	 *
	 * @param int $statusCode
	 */
	public function testRun($statusCode) {

		$target = 'targetURL';
		$source = 'sourceURL';
		$token = 'token';

		$argument = ['url' => $target, 'token' => $token];

		$this->urlGenerator->expects($this->once())->method('getAbsoluteURL')->with('/')
			->willReturn($source);
		$this->httpClient->expects($this->once())->method('post')
			->with(
				$target . '/ocs/v2.php/apps/federation/api/v1/request-shared-secret?format=json',
				[
					'body' =>
						[
							'url' => $source,
							'token' => $token
						],
					'timeout' => 3,
					'connect_timeout' => 3,
				]
			)->willReturn($this->response);

		$this->response->expects($this->once())->method('getStatusCode')
			->willReturn($statusCode);

		if (
			$statusCode !== Http::STATUS_OK
			&& $statusCode !== Http::STATUS_FORBIDDEN
		) {
			$this->jobList->expects($this->once())->method('add')
				->with('OCA\Federation\BackgroundJob\RequestSharedSecret', $argument);
			$this->dbHandler->expects($this->never())->method('addToken');
		}

		if ($statusCode === Http::STATUS_FORBIDDEN) {
			$this->jobList->expects($this->never())->method('add');
			$this->dbHandler->expects($this->once())->method('addToken')->with($target, '');
		}

		$this->invokePrivate($this->requestSharedSecret, 'run', [$argument]);
	}

	public function dataTestRun() {
		return [
			[Http::STATUS_OK],
			[Http::STATUS_FORBIDDEN],
			[Http::STATUS_CONFLICT],
		];
	}
}
