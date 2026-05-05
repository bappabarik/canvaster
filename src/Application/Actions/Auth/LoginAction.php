<?php
declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Actions\Action;
use App\Application\Services\JwtService;
use App\Application\Validation\RequestValidator;
use App\Domain\Auth\RefreshTokenRepository;
use App\Domain\User\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Respect\Validation\Validator as v;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;

class LoginAction extends Action
{
    public function __construct(
        LoggerInterface        $logger,
        private UserRepository         $users,
        private RefreshTokenRepository $refreshTokens,
        private JwtService             $jwt,
        private RequestValidator       $validator,
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $data = $this->validator->validate(
            (array) $this->request->getParsedBody(),
            [
                'email'    => v::notEmpty()->email(),
                'password' => v::notEmpty()->stringType(),
            ]
        );

        $user = $this->users->findByEmail($data['email']);

        if (!$user || !password_verify($data['password'], $user->getPasswordHash())) {
            throw new HttpUnauthorizedException($this->request, 'Invalid credentials');
        }

        if (!$user->isActive()) {
            throw new HttpForbiddenException($this->request, 'Account is disabled');
        }

        $accessToken  = $this->jwt->issueAccessToken($user->getId(), $user->getEmail(), $user->getPlan());
        $refreshToken = $this->jwt->issueRefreshToken();

        $this->refreshTokens->store(
            $user->getId(),
            hash('sha256', $refreshToken),
            $this->jwt->getRefreshTtl()
        );

        $this->logger->info('User logged in: ' . $user->getEmail());

        return $this->respondWithData([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'user'          => $user->jsonSerialize(),
        ]);
    }
}