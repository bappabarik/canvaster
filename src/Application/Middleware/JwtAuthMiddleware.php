<?php
declare(strict_types=1);

namespace App\Application\Middleware;

use App\Application\Services\JwtService;
use App\Domain\User\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;
use Throwable;

class JwtAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private JwtService      $jwt,
        private UserRepository  $users,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        $header = $request->getHeaderLine('Authorization');

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            throw new HttpUnauthorizedException($request, 'Missing or malformed Authorization header');
        }

        try {
            $payload = $this->jwt->decode($matches[1]);
        } catch (Throwable) {
            throw new HttpUnauthorizedException($request, 'Invalid or expired token');
        }

        $user = $this->users->findById((int) ($payload['sub'] ?? 0));

        if (!$user) {
            throw new HttpUnauthorizedException($request, 'User not found');
        }

        if (!$user->isActive()) {
            throw new HttpForbiddenException($request, 'Account is disabled');
        }

        return $handler->handle(
            $request->withAttribute('user', $user)
        );
    }
}