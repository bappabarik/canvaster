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
use Slim\Exception\HttpBadRequestException;

class RegisterAction extends Action
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
                'name'     => v::notEmpty()->stringType()->length(2, 120),
                'email'    => v::notEmpty()->email()->length(null, 180),
                'password' => v::notEmpty()->stringType()->length(8, 100),
            ]
        );

        if ($this->users->emailExists($data['email'])) {
            throw new HttpBadRequestException($this->request, 'Email already registered');
        }

        $user = $this->users->create(
            $data['name'],
            $data['email'],
            password_hash($data['password'], PASSWORD_BCRYPT)
        );

        $accessToken  = $this->jwt->issueAccessToken($user->getId(), $user->getEmail(), $user->getPlan());
        $refreshToken = $this->jwt->issueRefreshToken();

        $this->refreshTokens->store(
            $user->getId(),
            hash('sha256', $refreshToken),
            $this->jwt->getRefreshTtl()
        );

        $this->logger->info('User registered: ' . $user->getEmail());

        return $this->respondWithData([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'user'          => $user->jsonSerialize(),
        ], 201);
    }
}