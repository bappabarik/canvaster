<?php
declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Actions\Action;
use App\Application\Validation\RequestValidator;
use App\Domain\Auth\RefreshTokenRepository;
use App\Domain\User\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Respect\Validation\Validator as v;

class LogoutAction extends Action
{
    public function __construct(
        LoggerInterface        $logger,
        private RefreshTokenRepository $refreshTokens,
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

        $this->refreshTokens->revoke(hash('sha256', $data['refresh_token']));

        /** @var User $user */
        $user = $this->request->getAttribute('user');
        $this->logger->info('User logged out: ' . $user->getEmail());

        return $this->respondWithData(['message' => 'Logged out successfully']);
    }
}