<?php

namespace Kreait\Firebase;

use Firebase\Auth\Token\Domain\Generator as TokenGenerator;
use Firebase\Auth\Token\Domain\Verifier as LegacyIdTokenVerifier;
use Firebase\Auth\Token\Exception\InvalidSignature;
use Firebase\Auth\Token\Exception\InvalidToken;
use Firebase\Auth\Token\Exception\IssuedInTheFuture;
use Kreait\Firebase\Auth\ApiClient;
use Kreait\Firebase\Auth\IdTokenVerifier as NewIdTokenVerifier;
use Kreait\Firebase\Auth\SessionTokenVerifier;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Exception\Auth\InvalidPassword;
use Kreait\Firebase\Exception\Auth\RevokedIdToken;
use Kreait\Firebase\Exception\Auth\UserNotFound;
use Kreait\Firebase\Exception\AuthException;
use Kreait\Firebase\Exception\InvalidArgumentException;
use Kreait\Firebase\Exception\RevokedToken;
use Kreait\Firebase\Util\DT;
use Kreait\Firebase\Util\Duration;
use Kreait\Firebase\Util\JSON;
use Kreait\Firebase\Value\ClearTextPassword;
use Kreait\Firebase\Value\Email;
use Kreait\Firebase\Value\PhoneNumber;
use Kreait\Firebase\Value\Provider;
use Kreait\Firebase\Value\Uid;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Token;
use Psr\Http\Message\UriInterface;

class Auth
{
    /**
     * @var ApiClient
     */
    private $client;

    /**
     * @var TokenGenerator
     */
    private $tokenGenerator;

    /**
     * @var NewIdTokenVerifier|LegacyIdTokenVerifier
     */
    private $idTokenVerifier;

    /**
     * @var SessionTokenVerifier
     */
    private $sessionTokenVerifier;

    /**
     * @param ApiClient $client
     * @param TokenGenerator $customToken
     * @param NewIdTokenVerifier|LegacyIdTokenVerifier $idTokenVerifier
     * @param SessionTokenVerifier $sessionTokenVerifier
     */
    public function __construct(ApiClient $client, TokenGenerator $customToken, $idTokenVerifier, SessionTokenVerifier $sessionTokenVerifier)
    {
        $this->client = $client;
        $this->tokenGenerator = $customToken;

        if ($idTokenVerifier instanceof LegacyIdTokenVerifier) {
            trigger_error(sprintf('%s is deprecated, please use %s instead', LegacyIdTokenVerifier::class, NewIdTokenVerifier::class), E_USER_DEPRECATED);
        } elseif (!($idTokenVerifier instanceof NewIdTokenVerifier)) {
            throw new InvalidArgumentException(sprintf('An ID token verifier must be an instance of %s', NewIdTokenVerifier::class));
        }

        $this->idTokenVerifier = $idTokenVerifier;
        $this->sessionTokenVerifier = $sessionTokenVerifier;
    }

    public function getApiClient(): ApiClient
    {
        return $this->client;
    }

    public function getUser($uid): UserRecord
    {
        $uid = $uid instanceof Uid ? $uid : new Uid($uid);

        $response = $this->client->getAccountInfo((string) $uid);

        $data = JSON::decode((string) $response->getBody(), true);

        if (empty($data['users'][0])) {
            throw UserNotFound::withCustomMessage('No user with uid "'.$uid.'" found.');
        }

        return UserRecord::fromResponseData($data['users'][0]);
    }

    /**
     * @param int $maxResults
     * @param int $batchSize
     *
     * @return \Generator|UserRecord[]
     */
    public function listUsers(int $maxResults = null, int $batchSize = null): \Generator
    {
        $maxResults = $maxResults ?? 1000;
        $batchSize = $batchSize ?? 1000;

        $pageToken = null;
        $count = 0;

        do {
            $response = $this->client->downloadAccount($batchSize, $pageToken);
            $result = JSON::decode((string) $response->getBody(), true);

            foreach ((array) ($result['users'] ?? []) as $userData) {
                yield UserRecord::fromResponseData($userData);

                if (++$count === $maxResults) {
                    return;
                }
            }

            $pageToken = $result['nextPageToken'] ?? null;
        } while ($pageToken);
    }

    /**
     * Creates a new user with the provided properties.
     *
     * @param array|Request\CreateUser $properties
     *
     * @throws InvalidArgumentException if invalid properties have been provided
     *
     * @return UserRecord
     */
    public function createUser($properties): UserRecord
    {
        $request = $properties instanceof Request\CreateUser
            ? $properties
            : Request\CreateUser::withProperties($properties);

        $response = $this->client->createUser($request);

        $uid = JSON::decode((string) $response->getBody(), true)['localId'];

        return $this->getUser($uid);
    }

    /**
     * Updates the given user with the given properties.
     *
     * @param Uid|string $uid
     * @param array|Request\UpdateUser $properties
     *
     * @throws InvalidArgumentException if invalid properties have been provided
     *
     * @return UserRecord
     */
    public function updateUser($uid, $properties): UserRecord
    {
        $request = $properties instanceof Request\UpdateUser
            ? $properties
            : Request\UpdateUser::withProperties($properties);

        $request = $request->withUid($uid);

        $response = $this->client->updateUser($request);

        $uid = JSON::decode((string) $response->getBody(), true)['localId'];

        return $this->getUser($uid);
    }

    /**
     * @param Email|string $email
     * @param ClearTextPassword|string $password
     *
     * @return UserRecord
     */
    public function createUserWithEmailAndPassword($email, $password): UserRecord
    {
        return $this->createUser(
            Request\CreateUser::new()
                ->withUnverifiedEmail($email)
                ->withClearTextPassword($password)
        );
    }

    public function getUserByEmail($email): UserRecord
    {
        $email = $email instanceof Email ? $email : new Email($email);

        $response = $this->client->getUserByEmail((string) $email);

        $data = JSON::decode((string) $response->getBody(), true);

        if (empty($data['users'][0])) {
            throw UserNotFound::withCustomMessage('No user with email "'.$email.'" found.');
        }

        return UserRecord::fromResponseData($data['users'][0]);
    }

    public function getUserByPhoneNumber($phoneNumber): UserRecord
    {
        $phoneNumber = $phoneNumber instanceof PhoneNumber ? $phoneNumber : new PhoneNumber($phoneNumber);

        $response = $this->client->getUserByPhoneNumber((string) $phoneNumber);

        $data = JSON::decode((string) $response->getBody(), true);

        if (empty($data['users'][0])) {
            throw UserNotFound::withCustomMessage('No user with phone number "'.$phoneNumber.'" found.');
        }

        return UserRecord::fromResponseData($data['users'][0]);
    }

    public function createAnonymousUser(): UserRecord
    {
        return $this->createUser(Request\CreateUser::new());
    }

    /**
     * @param Uid|string $uid
     * @param ClearTextPassword|string $newPassword
     *
     * @return UserRecord
     */
    public function changeUserPassword($uid, $newPassword): UserRecord
    {
        return $this->updateUser($uid, Request\UpdateUser::new()->withClearTextPassword($newPassword));
    }

    /**
     * @param Uid|string $uid
     * @param Email|string $newEmail
     *
     * @return UserRecord
     */
    public function changeUserEmail($uid, $newEmail): UserRecord
    {
        return $this->updateUser($uid, Request\UpdateUser::new()->withEmail($newEmail));
    }

    /**
     * @param Uid|string $uid
     *
     * @return UserRecord
     */
    public function enableUser($uid): UserRecord
    {
        return $this->updateUser($uid, Request\UpdateUser::new()->markAsEnabled());
    }

    /**
     * @param Uid|string $uid
     *
     * @return UserRecord
     */
    public function disableUser($uid): UserRecord
    {
        return $this->updateUser($uid, Request\UpdateUser::new()->markAsDisabled());
    }

    /**
     * @param Uid|string $uid
     */
    public function deleteUser($uid)
    {
        $uid = $uid instanceof Uid ? $uid : new Uid($uid);

        try {
            $this->client->deleteUser((string) $uid);
        } catch (UserNotFound $e) {
            throw UserNotFound::withCustomMessage('No user with uid "'.$uid.'" found.');
        }
    }

    /**
     * @param Uid|string $uid
     * @param UriInterface|string $continueUrl
     */
    public function sendEmailVerification($uid, $continueUrl = null)
    {
        $response = $this->client->exchangeCustomTokenForIdAndRefreshToken(
            $this->createCustomToken($uid)
        );

        $idToken = JSON::decode((string) $response->getBody(), true)['idToken'];

        $this->client->sendEmailVerification($idToken, (string) $continueUrl);
    }

    /**
     * @param Email|string $email
     * @param UriInterface|string|null $continueUrl
     */
    public function sendPasswordResetEmail($email, $continueUrl = null)
    {
        $email = $email instanceof Email ? $email : new Email($email);

        $this->client->sendPasswordResetEmail((string) $email, (string) $continueUrl);
    }

    /**
     * @param Uid|string $uid
     * @param array $attributes
     *
     * @return UserRecord
     */
    public function setCustomUserAttributes($uid, array $attributes): UserRecord
    {
        return $this->updateUser($uid, Request\UpdateUser::new()->withCustomAttributes($attributes));
    }

    /**
     * @param Uid|string $uid
     * @param array $claims
     *
     * @return Token
     */
    public function createCustomToken($uid, array $claims = null): Token
    {
        $claims = $claims ?? [];

        $uid = $uid instanceof Uid ? $uid : new Uid($uid);

        return $this->tokenGenerator->createCustomToken($uid, $claims);
    }

    /**
     * Verifies a JWT auth token. Returns a Promise with the tokens claims. Rejects the promise if the token
     * could not be verified. If checkRevoked is set to true, verifies if the session corresponding to the
     * ID token was revoked. If the corresponding user's session was invalidated, a RevokedToken
     * exception is thrown. If not specified the check is not applied.
     *
     * @param Token|string $idToken the JWT to verify
     * @param bool $checkIfRevoked whether to check if the ID token is revoked
     * @param bool $allowFutureTokens whether to allow tokens that have been issued for the future
     *
     * @throws InvalidArgumentException
     * @throws InvalidToken
     * @throws IssuedInTheFuture
     * @throws RevokedIdToken
     * @throws InvalidSignature
     *
     * @return Token the verified token
     */
    public function verifyIdToken($idToken, bool $checkIfRevoked = null, bool $allowFutureTokens = null): Token
    {
        try {
            $idToken = $idToken instanceof Token ? $idToken : (new Parser())->parse($idToken);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('The given value could not be parsed as a token: '.$e->getMessage());
        }

        $checkIfRevoked = $checkIfRevoked ?? false;
        $allowFutureTokens = $allowFutureTokens ?? false;

        if ($this->idTokenVerifier instanceof NewIdTokenVerifier) {
            try {
                $this->idTokenVerifier->verify($idToken);
            } catch (Exception\InvalidToken $e) {
                $issuedAt = $idToken->getClaim('iat', false);
                $isIssuedInTheFuture = $issuedAt > time();

                if ($isIssuedInTheFuture && !$allowFutureTokens) {
                    throw new IssuedInTheFuture($idToken);
                }

                if (!$isIssuedInTheFuture) {
                    throw new InvalidToken($idToken);
                }
            }
        } else {
            try {
                $this->idTokenVerifier->verify($idToken);
            } catch (IssuedInTheFuture $e) {
                if (!$allowFutureTokens) {
                    throw $e;
                }
            }
        }

        if ($checkIfRevoked && $this->tokenHasBeenRevoked($idToken)) {
            throw new RevokedIdToken($idToken);
        }

        return $idToken;
    }

    /**
     * Verifies wether the given email/password combination is correct and returns
     * a UserRecord when it is, an Exception otherwise.
     *
     * This method has the side effect of changing the last login timestamp of the
     * given user. The recommended way to authenticate users in a client/server
     * environment is to use a Firebase Client SDK to authenticate the user
     * and to send an ID Token generated by the client back to the server.
     *
     * @param Email|string $email
     * @param ClearTextPassword|string $password
     *
     * @throws InvalidPassword if the given password does not match the given email address
     *
     * @return UserRecord if the combination of email and password is correct
     */
    public function verifyPassword($email, $password): UserRecord
    {
        $email = $email instanceof Email ? $email : new Email($email);
        $password = $password instanceof ClearTextPassword ? $password : new ClearTextPassword($password);

        $response = $this->client->verifyPassword((string) $email, (string) $password);

        $uid = JSON::decode((string) $response->getBody(), true)['localId'];

        return $this->getUser($uid);
    }

    /**
     * Revokes all refresh tokens for the specified user identified by the uid provided.
     * In addition to revoking all refresh tokens for a user, all ID tokens issued
     * before revocation will also be revoked on the Auth backend. Any request with an
     * ID token generated before revocation will be rejected with a token expired error.
     *
     * @param Uid|string $uid the user whose tokens are to be revoked
     */
    public function revokeRefreshTokens($uid)
    {
        $uid = $uid instanceof Uid ? $uid : new Uid($uid);

        $this->client->revokeRefreshTokens((string) $uid);
    }

    public function tokenHasBeenRevoked($token): bool
    {
        $token = $token instanceof Token ? $token : (new Parser())->parse($token);
        $uid = new Uid($token->getClaim('sub'));

        $validSince = $this->getUser($uid)->tokensValidAfterTime;
        $tokenAuthenticatedAt = DT::toUTCDateTimeImmutable($token->getClaim('auth_time'));

        return $tokenAuthenticatedAt < $validSince;
    }

    public function unlinkProvider($uid, $provider): UserRecord
    {
        $uid = $uid instanceof Uid ? $uid : new Uid($uid);
        $provider = array_map(function ($provider) {
            return $provider instanceof Provider ? $provider : new Provider($provider);
        }, (array) $provider);

        $response = $this->client->unlinkProvider($uid, $provider);

        $uid = JSON::decode((string) $response->getBody(), true)['localId'];

        return $this->getUser($uid);
    }

    /**
     * Creates a session token for the user identified by the given ID Token and returns it.
     *
     * @see https://firebase.google.com/docs/auth/admin/manage-cookies#create_session_cookie
     *
     * @param Token|string $idToken
     * @param Duration|null $lifetime
     *
     * @throws InvalidArgumentException
     * @throws AuthException when the session token can not be created
     *
     * @return Token
     */
    public function createSessionToken($idToken, $lifetime = null): Token
    {
        $idToken = $idToken instanceof Token ? $idToken : (new Parser())->parse($idToken);
        $lifetime = $lifetime instanceof Duration ? $lifetime : Duration::fromValue($lifetime ?: '5 minutes');

        if (!$lifetime->isWithin(Duration::fromValue('5 minutes'), Duration::fromValue('2 weeks'))) {
            throw new InvalidArgumentException("A session cookie's lifetime must be between 5 minutes and 2 weeks.");
        }

        $response = $this->client->createSessionCookie((string) $idToken, $lifetime->inSeconds());

        try {
            $data = JSON::decode((string) $response->getBody(), true);
        } catch (\InvalidArgumentException $e) {
            throw new AuthException("Unable to parse the response from the Firebase API as JSON: {$e->getMessage()}", $e->getCode(), $e);
        }

        if (!($tokenString = $data['sessionCookie'] ?? null)) {
            throw new AuthException("The Firebase API response does not include a 'sessionCookie' field, got: ".JSON::prettyPrint($data));
        }

        try {
            return (new Parser())->parse($tokenString);
        } catch (\Throwable $e) {
            throw new AuthException("Unable to parse {$tokenString} into a JWT token: ".$e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Verifies a JWT session token.
     *
     * @param Token|string $token
     * @param bool $checkIfRevoked If set to true, verifies if the session corresponding to the ID token was revoked.
     * @param Duration|mixed $leeway
     *
     * @throws InvalidArgumentException
     * @throws Exception\InvalidToken
     * @throws RevokedToken
     *
     * @return void
     */
    public function verifySessionToken($token, bool $checkIfRevoked = false, $leeway = null)
    {
        try {
            $token = $token instanceof Token ? $token : (new Parser())->parse($token);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('The given value could not be parsed as a token: '.$e->getMessage());
        }

        $this->sessionTokenVerifier->verify($token, $leeway);

        if ($checkIfRevoked && $this->tokenHasBeenRevoked($token)) {
            throw RevokedToken::because('The session has been revoked');
        }
    }
}
