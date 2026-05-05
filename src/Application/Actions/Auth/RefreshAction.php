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
use Slim\Exception\HttpUnauthorizedException;

class RefreshAction extends Action
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
            ['refresh_token' => v::notEmpty()->stringType()]
        );

        $hash = hash('sha256', $data['refresh_token']);
        $row  = $this->refreshTokens->findValid($hash);

        if (!$row) {
            throw new HttpUnauthorizedException($this->request, 'Invalid or expired refresh token');
        }

        $user = $this->users->findById((int) $row['user_id']);

        if (!$user || !$user->isActive()) {
            $this->refreshTokens->revoke($hash);
            throw new HttpUnauthorizedException($this->request, 'User not found or disabled');
        }

        // Rotate — revoke old, issue new pair
        $this->refreshTokens->revoke($hash);

        $accessToken  = $this->jwt->issueAccessToken($user->getId(), $user->getEmail(), $user->getPlan());
        $refreshToken = $this->jwt->issueRefreshToken();

        $this->refreshTokens->store(
            $user->getId(),
            hash('sha256', $refreshToken),
            $this->jwt->getRefreshTtl()
        );

        return $this->respondWithData([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'user'          => $user->jsonSerialize(),
        ]);
    }
}