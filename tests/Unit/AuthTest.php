<?php

namespace Kreait\Firebase\Tests\Unit;

use Firebase\Auth\Token\Domain\Generator;
use Firebase\Auth\Token\Domain\Verifier;
use Firebase\Auth\Token\Exception\IssuedInTheFuture;
use Kreait\Firebase\Auth;
use Kreait\Firebase\Auth\ApiClient;
use Kreait\Firebase\Exception\InvalidArgumentException;
use Kreait\Firebase\Tests\UnitTestCase;
use Lcobucci\JWT\Token;

class AuthTest extends UnitTestCase
{
    /**
     * @var ApiClient
     */
    private $apiClient;

    /**
     * @var Generator
     */
    private $tokenGenerator;

    /**
     * @var Verifier
     */
    private $idTokenVerifier;

    /**
     * @var Auth
     */
    private $auth;

    protected function setUp()
    {
        $this->tokenGenerator = $this->createMock(Generator::class);
        $this->idTokenVerifier = $this->createMock(Verifier::class);
        $this->apiClient = $this->createMock(ApiClient::class);
        $this->auth = new Auth($this->apiClient, $this->tokenGenerator, $this->idTokenVerifier);
    }

    public function testGetApiClient()
    {
        $this->assertSame($this->apiClient, $this->auth->getApiClient());
    }

    public function testCreateCustomToken()
    {
        $this->tokenGenerator
            ->expects($this->once())
            ->method('createCustomToken');

        $this->auth->createCustomToken('uid');
    }

    public function testVerifyIdTokenWithInvalidToken()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->auth->verifyIdToken('some id token string');
    }

    public function testDisallowFutureTokens()
    {
        $tokenProphecy = $this->prophesize(Token::class);
        $tokenProphecy->getClaim('iat')->willReturn(date('U'));

        $token = $tokenProphecy->reveal();

        $this->idTokenVerifier
            ->expects($this->once())
            ->method('verifyIdToken')
            ->willThrowException(new IssuedInTheFuture($token));

        $this->expectException(IssuedInTheFuture::class);
        $this->auth->verifyIdToken($token);
    }

    public function testAllowFutureTokens()
    {
        $tokenProphecy = $this->prophesize(Token::class);
        $tokenProphecy->getClaim('iat')->willReturn(date('U'));

        $token = $tokenProphecy->reveal();

        $this->idTokenVerifier
            ->expects($this->once())
            ->method('verifyIdToken')
            ->willReturn($token);

        $verifiedToken = $this->auth->verifyIdToken($token, false, true);
        $this->assertSame($token, $verifiedToken);
    }
}
