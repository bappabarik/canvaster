<?php
declare(strict_types=1);

namespace App\Application\Actions\Auth;

use App\Application\Actions\Action;
use App\Domain\User\User;
use Psr\Http\Message\ResponseInterface as Response;

class MeAction extends Action
{
    protected function action(): Response
    {
        /** @var User $user */
        $user = $this->request->getAttribute('user');
        return $this->respondWithData(['user' => $user->jsonSerialize()]);
    }
}